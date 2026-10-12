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
    /**
     * The camera setups a clip may use (owner, 2026-10-12: a library of camera angles). Each is worded so the camera is
     * the point of view, never an object in the picture: take 289's sheets said "left hand holds the phone" and "phone
     * propped on the desk", and every clip showed a second phone. Only the mirror selfie shows the phone, in the mirror.
     */
    public const CAMERAS = [
        'selfie_arm' => ['use' => 'talking to camera, one hand free', 'shot' => 'Selfie video the presenter films at arm\'s length: the camera is in their left hand, so their left arm reaches toward the lens at the edge of the frame and their right hand is free.'],
        'selfie_close' => ['use' => 'a close reaction or a line, face and shoulders', 'shot' => 'Close selfie video the presenter films at arm\'s length: their face and shoulders fill the frame, their left arm reaching toward the lens at the edge of the frame.'],
        'propped_front' => ['use' => 'talking or showing with both hands free', 'shot' => 'The camera stands on the desk facing the presenter at chest height, filming them from the waist up; both of their hands are free.'],
        'propped_hands' => ['use' => 'a two-handed action: opening, pouring, applying', 'shot' => 'The camera stands on the desk close to the presenter\'s hands, looking slightly down: only their hands, forearms and the product are in the frame.'],
        'top_down' => ['use' => 'hands working on the desk, seen from above', 'shot' => 'The camera looks straight down at the desk from above: the presenter\'s hands and the product are in the frame.'],
        'mirror_selfie' => ['use' => 'an outfit or a full-body look', 'shot' => 'A mirror selfie: the presenter holds their phone in front of a mirror, and the phone is seen in the mirror the whole clip.'],
    ];

    private const PROMPT = <<<'TXT'
You direct a short creator video ad that a video model makes as separate clips, each from its own text and the reference photos of the presenter and the product. For every clip below, in order, write a complete timed sheet that leaves nothing to guess. It is the clip's whole prompt.

Rules:
- camera: one key from the camera list below. A two-handed action (opening, tearing, pouring into a hand, unscrewing, applying) uses propped_hands, top_down or propped_front.
- shot: one sentence after the camera: the framing within it, the place and its light, and the presenter's clothes, named exactly as given below (never "the outfit in the photo"). Call them "the presenter" and "the product"; never describe their looks: the photos show them.
- The camera is the point of view and is never in the picture: never write "phone". In a selfie the left hand holds the camera the whole clip (write "left hand: holds the camera, out of view") and only the right hand is free; on a propped camera no hand touches it.
- For an opening, tearing or pouring action, each block says what is seen changing in the product (the seal parts, the top gapes open, peanuts show at the top), not only what the hands try.
- start: the exact first frame: what the left hand and the right hand each hold and where, and the product's state, carried on from the end of the clip before (opened stays opened; eaten is gone; set down stays down).
- blocks: timed blocks covering the clip from 00:00 to its length, each "00:00-00:02" style and 1 to 3 seconds long, each one simple visible movement. Every block states left (what the left hand holds and does), right (the same for the right hand), product (where it is and its state), face (where the eyes look and the expression, or "not in frame"), says (the exact words spoken in that block, copied from the clip's line in order; all of the line across the blocks, nothing added; empty when silent) and sound (only a sound the action makes).
- An action happens once, in its own clip, shown from its first movement to its result. A clip never starts an action a later clip performs.
- end: the exact last frame, in the same terms as start.
- check: per block, one yes/no question a single frame from that moment can answer about the hands and the product.
- State everything positively, as what is seen and heard. Plain words; one steady camera; no style labels.

Cameras:
{{cameras}}

Answer JSON only: {"clips":[{"camera":"selfie_arm","shot":"...","start":"...","blocks":[{"when":"00:00-00:02","left":"...","right":"...","product":"...","face":"...","says":"","sound":"","check":"..."}],"end":"..."}]} with exactly one entry per clip, in order.
TXT;

    /**
     * One sheet per chunk, in order, or [] when the sheet could not be written (the take then falls back to the ready check).
     *
     * @param  list<array<string, mixed>>  $chunks  UgcOneShotCompiler output
     * @return list<array{shot: string, start: string, blocks: list<array{when: string, left: string, right: string, product: string, face: string, says: string, sound: string, check: string}>, end: string}>
     */
    public function write(array $chunks, string $product = '', string $wardrobe = '', string $place = ''): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || $chunks === []) return [];
        $clips = [];
        foreach (array_values($chunks) as $i => $c) {
            $scene = (string) (explode("\n\n", (string) ($c['prompt'] ?? ''))[1] ?? '');
            $clips[] = 'Clip '.($i + 1).' ('.(int) ($c['seconds'] ?? 0).' seconds'.(trim((string) ($c['dialogue'] ?? '')) !== '' ? ', its line: "'.trim((string) $c['dialogue']).'"' : ', silent').'). As planned: '
                .mb_substr(trim((string) preg_replace('/\s*(The presenter says[^"]*"[^"]*"\.?|They say exactly these words.*$|No speech, narration.*$)/su', '', $scene)), 0, 1200);
        }
        $content = str_replace('{{cameras}}', implode("\n", array_map(fn ($k, $c) => $k.': '.$c['use'], array_keys(self::CAMERAS), self::CAMERAS)), self::PROMPT)."\n\nProduct: ".($product !== '' ? $product : 'the product')
            .($wardrobe !== '' ? "\nThe presenter wears: ".$wardrobe : '').($place !== '' ? "\nThe place: ".$place : '')."\n\n".implode("\n\n", $clips);
        $sheets = $this->ask($content, count($chunks));
        // One more try, told exactly what was wrong (2026-10-12: a run split a clip's line with altered words).
        $faults = $sheets !== [] ? self::faults($sheets, $chunks) : [];
        if ($sheets === [] || $faults !== []) {
            $again = $this->ask($content.($faults !== [] ? "\n\nYour last sheet had these faults; fix them: ".implode(' ', $faults) : ''), count($chunks));
            if ($again !== [] && ($sheets === [] || count(self::faults($again, $chunks)) <= count($faults))) $sheets = $again;
        }

        return $sheets;
    }

    /** What a sheet gets wrong that a retry can fix: words that are not the clip's line, blocks that stop short of its end. */
    public static function faults(array $sheets, array $chunks): array
    {
        $faults = [];
        foreach (array_values($chunks) as $i => $c) {
            $sheet = $sheets[$i] ?? null;
            if (! $sheet) continue;
            $line = (string) ($c['dialogue'] ?? '');
            if (trim($line) !== '' && self::words(implode(' ', array_column($sheet['blocks'], 'says'))) !== self::words($line)) {
                $faults[] = 'Clip '.($i + 1).': the blocks\' "says" together must be exactly "'.trim($line).'", in order, nothing added or left out.';
            }
            if (($sheet['camera'] ?? '') === '') $faults[] = 'Clip '.($i + 1).': camera must be one key from the camera list.';
            $last = end($sheet['blocks']);
            $ends = preg_match('/-\s*(\d+):(\d+(?:\.\d+)?)\s*$/', (string) ($last['when'] ?? ''), $m) ? (int) $m[1] * 60 + (float) $m[2] : null;
            $seconds = (float) ($c['seconds'] ?? 0);
            if ($ends !== null && $seconds > 0 && $ends < $seconds - 1) {
                $faults[] = 'Clip '.($i + 1).': the blocks must run to 00:'.str_pad((string) (int) $seconds, 2, '0', STR_PAD_LEFT).'.';
            }
        }

        return $faults;
    }

    private function ask(string $content, int $count): array
    {
        $key = (string) config('services.anthropic.key');
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
        $sheets = self::parse($text, $count);
        if ($sheets === []) Log::warning('ugc action sheet: the answer was not one sheet per clip', ['clips' => $count, 'answer' => mb_substr($text, 0, 300)]);

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
            $camera = (string) ($clip['camera'] ?? '');
            $out[] = ['camera' => isset(self::CAMERAS[$camera]) ? $camera : '', 'shot' => mb_substr(trim((string) ($clip['shot'] ?? '')), 0, 400), 'start' => $start, 'blocks' => $blocks, 'end' => mb_substr(trim((string) ($clip['end'] ?? '')), 0, 300)];
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
        $spoken = trim($dialogue) !== '';
        $camera = ($sheet['camera'] ?? '') !== '' ? $sheet['camera'] : ($spoken ? 'selfie_arm' : 'propped_hands');
        $mirror = $camera === 'mirror_selfie';
        // The camera is never an object in the picture (take 289): "phone" in the sheet becomes the camera, out of view.
        $unseen = fn (string $t) => $mirror ? $t : (string) preg_replace('/\b(?:the |a |her |his |their )?phone\b/iu', 'the camera', $t);
        $blocks = array_map(fn ($b) => array_combine(array_keys($b), array_map(fn ($k, $v) => $k !== 'says' && is_string($v) ? $unseen($v) : $v, array_keys($b), $b)), $blocks);
        $lines = array_map(fn ($b) => '['.$b['when'].'] '.self::block($b), $blocks);

        return self::CAMERAS[$camera]['shot'].' '.trim($unseen(rtrim($sheet['shot'], '.')).'.')."\n"
            .'First frame: '.$unseen(rtrim($sheet['start'], '.')).".\n"
            .implode("\n", $lines)."\n"
            .($sheet['end'] !== '' ? 'Last frame: '.$unseen(rtrim($sheet['end'], '.')).".\n" : '')
            .($spoken && ! $verbatim ? 'The presenter says: "'.trim($dialogue).'"'."\n" : '')
            .'Ambient noise: quiet room tone.'."\n"
            .'Throughout: the same person as in the presenter photo, in the same clothes, in the same room; exactly one product, its packaging exactly as in the product photo. '
            .($spoken ? 'The presenter speaks only the quoted words, naturally and once, then stays quiet. ' : 'The clip is silent apart from the sounds named. ')
            .'The only writing in the picture is the product\'s own label.'
            .($mirror ? '' : ' The camera filming this is the presenter\'s own phone, so no phone is ever seen in the picture.');
    }

    /** One block in plain words: each hand, the product, the face, the words and the sound. */
    public static function block(array $b): string
    {
        $say = fn (string $label, string $v) => $v !== '' ? $label.': '.rtrim($v, '.').'. ' : '';

        return trim($say('Left hand', $b['left']).$say('Right hand', $b['right']).$say('Product', $b['product']).$say('Face', $b['face'])
            .(($b['says'] ?? '') !== '' ? 'The presenter says: "'.trim($b['says']).'" ' : '').(($b['sound'] ?? '') !== '' ? 'SFX: '.rtrim($b['sound'], '.').'.' : ''));
    }

    public static function words(string $text): string
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
