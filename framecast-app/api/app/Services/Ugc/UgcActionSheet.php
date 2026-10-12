<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The timed action sheet (owner, 2026-10-12: "be explicit about character actions"): before any clip is paid for, the
 * director's model writes each clip's physical action as short timed blocks, what each hand holds and does and how the
 * product ends, so a video model gets concrete hands at concrete moments instead of one sentence of intent. Talking
 * shots tie actions to their words ("on 'Listen to this'"), since the voice decides the timing; silent shots use
 * seconds. Each block carries a yes/no question a single frame can answer, which the reviewer asks of the made clip.
 * The sheet replaces the clip's action sentence; it is never added on top of it.
 */
class UgcActionSheet
{
    private const PROMPT = <<<'TXT'
You direct the physical action of a short creator video ad, filmed as separate clips by a video model. Each clip is generated on its own, so it only knows what its own text says. For every clip below, in order, write a timed action sheet that leaves nothing to guess.

Rules:
- A person has two hands. When the phone is handheld (a selfie), one hand holds the phone the whole clip and only the other hand is free. The phone is propped on a surface whenever an action needs both hands (opening, tearing, pouring into a hand, unscrewing, applying), and then neither hand touches it.
- start: the exact first frame: where the phone is, what the left hand and the right hand each hold and where, and the product's state, carried on from the end of the clip before (opened stays opened; eaten is gone; set down stays down).
- blocks: 2 to 5 blocks in order covering the whole clip, each one simple visible movement. In a clip with spoken words, time each block by the words: "before speaking", "on "<exact words>"", "after "<exact words>""; in a silent clip, by seconds: "0-2s", "2-4s".
- Each block states every one of these, explicitly, even when it does not change: left (what the left hand holds and does), right (what the right hand holds and does), product (where it is and its state), face (where the eyes look and the expression; "not in frame" in a hands-only shot), sound (only a sound the action makes, else empty).
- An action happens once, in its own clip, and is shown being done, from its first movement to its result. A clip never starts an action a later clip performs.
- end: the exact last frame, in the same terms as start.
- check: for each block, one yes/no question a single frame from that moment can answer about the hands and the product ("Is the pouch open, with peanuts visible at the top?").
- Never change the spoken words, the place, the clothes or the product. Plain words a video model can follow; no camera jargon.

Answer JSON only: {"clips":[{"start":"...","blocks":[{"when":"...","left":"...","right":"...","product":"...","face":"...","sound":"","check":"..."}],"end":"..."}]} with exactly one entry per clip, in order.
TXT;

    /**
     * One sheet per chunk, in order, or [] when the sheet could not be written (the take then falls back to the ready check).
     *
     * @param  list<array<string, mixed>>  $chunks  UgcOneShotCompiler output
     * @return list<array{start: string, blocks: list<array{when: string, left: string, right: string, product: string, face: string, sound: string, check: string}>, end: string}>
     */
    public function write(array $chunks, string $product = ''): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || $chunks === []) return [];
        $clips = [];
        foreach (array_values($chunks) as $i => $c) {
            $scene = (string) (explode("\n\n", (string) ($c['prompt'] ?? ''))[1] ?? '');
            $clips[] = 'Clip '.($i + 1).' ('.(int) ($c['seconds'] ?? 0).' s'.(trim((string) ($c['dialogue'] ?? '')) !== '' ? ', says: "'.trim((string) $c['dialogue']).'"' : ', silent').'): '
                .mb_substr(trim((string) preg_replace('/\s*(The presenter says[^"]*"[^"]*"\.?|They say exactly these words.*$|No speech, narration.*$)/su', '', $scene)), 0, 1200);
        }
        $content = self::PROMPT."\n\nProduct: ".($product !== '' ? $product : 'the product')."\n\n".implode("\n\n", $clips);
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('services.ai.direct_model', 'claude-sonnet-5-5'),
                    'max_tokens' => 6000, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) {
                Log::warning('ugc action sheet: the call failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                return [];
            }
            $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        } catch (\Throwable $e) {
            Log::warning('ugc action sheet: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return [];
        }
        $sheets = self::parse($text, count($chunks));
        if ($sheets === []) Log::warning('ugc action sheet: the answer was not one sheet per clip', ['clips' => count($chunks), 'answer' => mb_substr($text, 0, 300)]);

        return $sheets;
    }

    /** The sheets from the model's answer; [] unless there is exactly one usable sheet per clip. */
    public static function parse(string $text, int $count): array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        $out = [];
        foreach ((array) ($json['clips'] ?? []) as $clip) {
            $blocks = [];
            foreach (array_slice((array) ($clip['blocks'] ?? []), 0, 5) as $bk) {
                $field = fn (string $k, int $max = 200) => mb_substr(trim((string) ($bk[$k] ?? '')), 0, $max);
                if ($field('left') === '' && $field('right') === '') continue;
                $blocks[] = ['when' => $field('when', 120), 'left' => $field('left'), 'right' => $field('right'), 'product' => $field('product'),
                    'face' => $field('face'), 'sound' => $field('sound', 120), 'check' => $field('check')];
            }
            $start = mb_substr(trim((string) ($clip['start'] ?? '')), 0, 400);
            if ($blocks === [] || $start === '') return [];
            $out[] = ['start' => $start, 'blocks' => $blocks, 'end' => mb_substr(trim((string) ($clip['end'] ?? '')), 0, 300)];
        }

        return count($out) === $count ? $out : [];
    }

    /** The sheet as the clip prompt's action: it replaces the shot's own action sentence. */
    public static function text(array $sheet): string
    {
        $lines = array_map(fn ($b) => '['.$b['when'].'] '.self::block($b), $sheet['blocks']);

        return "Timed action, follow exactly:\nFirst frame: ".rtrim($sheet['start'], '.').".\n".implode("\n", $lines).($sheet['end'] !== '' ? "\nLast frame: ".rtrim($sheet['end'], '.').'.' : '');
    }

    /** One block in plain words: each hand, the product, the face and the sound it makes. */
    public static function block(array $b): string
    {
        $say = fn (string $label, string $v) => $v !== '' ? $label.': '.rtrim($v, '.').'. ' : '';

        return trim($say('Left hand', $b['left']).$say('Right hand', $b['right']).$say('Product', $b['product']).$say('Face', $b['face']).$say('Sound', $b['sound']));
    }

    /** Each chunk's prompt with its sheet in place of its action sentence; the sheet is kept on the chunk for the reviewer. */
    public static function apply(array $chunks, array $sheets): array
    {
        if (count($sheets) !== count($chunks)) return $chunks;
        foreach (array_values($chunks) as $i => $c) {
            $parts = explode("\n\n", (string) ($c['prompt'] ?? ''));
            // Every action sentence of the clip goes (a packed clip has one per beat): the sheet says it once, timed.
            if (isset($parts[1])) $parts[1] = trim((string) preg_replace('/\s*Action and sound:\s*.*?(?=\s*(The presenter says|They say exactly|No speech, narration|Staging:|Cut to)|$)/su', '', $parts[1]));
            $at = count($parts) >= 3 ? count($parts) - 1 : count($parts);
            array_splice($parts, $at, 0, [self::text($sheets[$i])]);
            $chunks[$i]['prompt'] = implode("\n\n", $parts);
            $chunks[$i]['sheet'] = $sheets[$i];
        }

        return $chunks;
    }
}
