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
You direct a short creator video ad that a video model makes as separate clips, each from its own text and the reference photos of the presenter and the product. For every clip below, in order, write a complete timed sheet that leaves nothing to guess. It is the clip's whole prompt.

Rules:
- shot: one sentence: where the camera is (a handheld selfie, or a phone propped on the desk, or close on the hands), the framing, the place and its light, and the presenter's clothes. Call them "the presenter" and "the product"; never describe their looks: the photos show them.
- A person has two hands. In a handheld selfie one hand holds the phone the whole clip and only the other is free. The phone is propped whenever an action needs both hands (opening, tearing, pouring into a hand, unscrewing, applying), and then no hand touches it.
- start: the exact first frame: what the left hand and the right hand each hold and where, and the product's state, carried on from the end of the clip before (opened stays opened; eaten is gone; set down stays down).
- blocks: timed blocks covering the clip from 00:00 to its length, each "00:00-00:02" style and 1 to 3 seconds long, each one simple visible movement. Every block states left (what the left hand holds and does), right (the same for the right hand), product (where it is and its state), face (where the eyes look and the expression, or "not in frame"), says (the exact words spoken in that block, copied from the clip's line in order; all of the line across the blocks, nothing added; empty when silent) and sound (only a sound the action makes).
- An action happens once, in its own clip, shown from its first movement to its result. A clip never starts an action a later clip performs.
- end: the exact last frame, in the same terms as start.
- check: per block, one yes/no question a single frame from that moment can answer about the hands and the product.
- State everything positively, as what is seen and heard. Plain words; one steady camera; no style labels.

Answer JSON only: {"clips":[{"shot":"...","start":"...","blocks":[{"when":"00:00-00:02","left":"...","right":"...","product":"...","face":"...","says":"","sound":"","check":"..."}],"end":"..."}]} with exactly one entry per clip, in order.
TXT;

    /**
     * One sheet per chunk, in order, or [] when the sheet could not be written (the take then falls back to the ready check).
     *
     * @param  list<array<string, mixed>>  $chunks  UgcOneShotCompiler output
     * @return list<array{shot: string, start: string, blocks: list<array{when: string, left: string, right: string, product: string, face: string, says: string, sound: string, check: string}>, end: string}>
     */
    public function write(array $chunks, string $product = ''): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || $chunks === []) return [];
        $clips = [];
        foreach (array_values($chunks) as $i => $c) {
            $scene = (string) (explode("\n\n", (string) ($c['prompt'] ?? ''))[1] ?? '');
            $clips[] = 'Clip '.($i + 1).' ('.(int) ($c['seconds'] ?? 0).' seconds'.(trim((string) ($c['dialogue'] ?? '')) !== '' ? ', its line: "'.trim((string) $c['dialogue']).'"' : ', silent').'). As planned: '
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
                $blocks[] = ['when' => $field('when', 40), 'left' => $field('left'), 'right' => $field('right'), 'product' => $field('product'),
                    'face' => $field('face'), 'says' => $field('says', 600), 'sound' => $field('sound', 120), 'check' => $field('check')];
            }
            $start = mb_substr(trim((string) ($clip['start'] ?? '')), 0, 400);
            if ($blocks === [] || $start === '') return [];
            $out[] = ['shot' => mb_substr(trim((string) ($clip['shot'] ?? '')), 0, 400), 'start' => $start, 'blocks' => $blocks, 'end' => mb_substr(trim((string) ($clip['end'] ?? '')), 0, 300)];
        }

        return count($out) === $count ? $out : [];
    }

    /**
     * The clip's whole prompt (owner, 2026-10-12; Veo's timestamp prompting and the short-prompt guides): one line for the
     * shot, the timed blocks with the words said in each, the last frame, the sound, and one positive line for what
     * holds throughout. No rulebook and no negatives ("no subtitles" reads as subtitles), and the presenter and product
     * are never described: their photos show them, and a description that differs makes them drift.
     */
    public static function prompt(array $sheet, string $dialogue = ''): string
    {
        $blocks = $sheet['blocks'];
        // The words are the script's: when the blocks do not say exactly the line, it is said whole instead.
        $verbatim = self::words(implode(' ', array_column($blocks, 'says'))) === self::words($dialogue);
        if (! $verbatim) $blocks = array_map(fn ($b) => ['says' => ''] + $b, $blocks);
        $lines = array_map(fn ($b) => '['.$b['when'].'] '.self::block($b), $blocks);
        $spoken = trim($dialogue) !== '';

        return trim(rtrim($sheet['shot'], '.').'.')."\n"
            .'First frame: '.rtrim($sheet['start'], '.').".\n"
            .implode("\n", $lines)."\n"
            .($sheet['end'] !== '' ? 'Last frame: '.rtrim($sheet['end'], '.').".\n" : '')
            .($spoken && ! $verbatim ? 'The presenter says: "'.trim($dialogue).'"'."\n" : '')
            .'Ambient noise: quiet room tone.'."\n"
            .'Throughout: the same person as in the presenter photo, in the same clothes, in the same room; exactly one product, its packaging exactly as in the product photo. '
            .($spoken ? 'The presenter speaks only the quoted words, naturally and once, then stays quiet. ' : 'The clip is silent apart from the sounds named. ')
            .'The only writing in the picture is the product\'s own label.';
    }

    /** One block in plain words: each hand, the product, the face, the words and the sound. */
    public static function block(array $b): string
    {
        $say = fn (string $label, string $v) => $v !== '' ? $label.': '.rtrim($v, '.').'. ' : '';

        return trim($say('Left hand', $b['left']).$say('Right hand', $b['right']).$say('Product', $b['product']).$say('Face', $b['face'])
            .(($b['says'] ?? '') !== '' ? 'The presenter says: "'.trim($b['says']).'" ' : '').(($b['sound'] ?? '') !== '' ? 'SFX: '.rtrim($b['sound'], '.').'.' : ''));
    }

    private static function words(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /** Each chunk's prompt replaced by its sheet; the sheet is kept on the chunk for the reviewer. */
    public static function apply(array $chunks, array $sheets): array
    {
        if (count($sheets) !== count($chunks)) return $chunks;
        foreach (array_values($chunks) as $i => $c) {
            $chunks[$i]['prompt'] = self::prompt($sheets[$i], (string) ($c['dialogue'] ?? ''));
            $chunks[$i]['sheet'] = $sheets[$i];
        }

        return $chunks;
    }
}
