<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The reviewer (owner, 2026-10-12: "Omni again with a reviewer"): a cheap vision pass that checks a storyboard still
 * before it is animated, and each clip after it is made, against what the shot asked for. It names what is wrong as
 * short fixes, so one remake can be told what to change. Take 287 is why: its pouch was never opened in the clip, and
 * its first clip changed clothes. Null when the check could not run: that is never a pass or a fail.
 */
class UgcReview
{
    /**
     * @param  array{scene: string, action: string, staging: string}  $shot  UgcStoryboard::shot()
     * @return array{ok: bool, problems: list<string>}|null
     */
    public function still(array $shot, string $wardrobe, string $still, ?string $product, ?string $presenter, ?string $opening, bool $face): ?array
    {
        $images = [];
        $list = [];
        $n = 0;
        if ($product !== null) { $images[] = $product; $list[] = 'Image '.(++$n).': the product photo.'; }
        if ($presenter !== null && $face) { $images[] = $presenter; $list[] = 'Image '.(++$n).': the presenter\'s photo.'; }
        if ($opening !== null) { $images[] = $opening; $list[] = 'Image '.(++$n).': the opening frame of this video.'; }
        $images[] = $still;
        $list[] = 'Image '.(++$n).': THE FRAME TO REVIEW.';

        $checks = array_values(array_filter([
            $product !== null ? 'The pack is the product photo\'s exact printed packaging: same logo, lettering, illustration and colours, never redrawn or retyped; exactly one pack.' : null,
            $face && $presenter !== null ? 'It is the same person as the presenter\'s photo.' : null,
            $wardrobe !== '' ? 'The presenter wears '.$wardrobe.' (not the clothes in their photo).' : null,
            $opening !== null ? 'The room, furniture and light are the opening frame\'s.' : null,
            $shot['action'] !== '' ? 'It shows the moment BEFORE this action, with nothing of it done yet: '.$shot['action'] : null,
            $shot['staging'] !== '' ? 'The hands, phone and product follow: '.$shot['staging'] : null,
            'Physics: two hands at most, a hand holding the phone holds nothing else, nothing floats, no phone in a hand when both hands are busy.',
        ]));

        return $this->ask(implode(' ', $list)."\n\nThe shot: ".$shot['scene']."\n\nCheck:\n- ".implode("\n- ", $checks), $images);
    }

    /**
     * @param  array{scene: string, action: string, staging: string}  $shot
     * @return array{ok: bool, problems: list<string>}|null
     */
    public function clip(array $shot, string $wardrobe, string $clipPath, ?string $still, ?string $product): ?array
    {
        $frames = $this->frames($clipPath, 4);
        if ($frames === []) return null;
        $images = array_merge($frames, array_values(array_filter([$still, $product])));
        $list = 'Images 1 to '.count($frames).': frames from a generated video clip, in order.'
            .($still !== null ? ' Image '.(count($frames) + 1).': the frame the clip started from.' : '')
            .($product !== null ? ' Image '.(count($images)).': the product photo.' : '');
        $checks = array_values(array_filter([
            $shot['action'] !== '' ? 'The action happens in the clip, once, and is completed: '.$shot['action'] : null,
            $wardrobe !== '' ? 'The presenter wears '.$wardrobe.' in every frame: no change of clothes.' : ($still !== null ? 'The presenter wears the same clothes as the starting frame in every frame.' : null),
            'The same person and the same room in every frame: no cut to another place.',
            $product !== null ? 'The pack keeps the product photo\'s printed packaging; exactly one pack.' : null,
            'Physics: two hands at most, nothing floats, objects keep their size.',
        ]));

        return $this->ask($list."\n\nThe clip was asked for: ".$shot['scene']."\n\nCheck:\n- ".implode("\n- ", $checks), $images);
    }

    /**
     * A clip checked against its timed sheet: one frame from each block's moment, each asked its yes/no question, plus
     * the same three questions for every clip (another place, words or camera UI on screen, a change of clothes).
     * Narrow questions, not "find problems": take 288's open review faulted every clip and missed what was wrong.
     *
     * @param  array{start: string, blocks: list<array<string, string>>, end: string}  $sheet
     * @return array{ok: bool, problems: list<string>, answers: list<array{q: string, yes: bool}>}|null
     */
    public function sheet(array $sheet, string $clipPath, string $wardrobe = ''): ?array
    {
        $len = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $clipPath])->output());
        if ($len <= 0) return null;
        $blocks = array_values(array_filter($sheet['blocks'], fn ($b) => $b['check'] !== ''));
        $times = self::moments($blocks, $len, $this->heard($clipPath));
        $frames = [];
        $questions = [];
        $actions = [];
        foreach ($blocks as $k => $b) {
            $frame = $this->frameAt($clipPath, max(0.0, min($len - 0.1, $times[$k])));
            if ($frame === null) continue;
            $frames[] = $frame;
            $questions[] = 'Image '.count($frames).': '.$b['check'];
            $actions[] = \App\Services\Ugc\UgcActionSheet::block($b);
        }
        $last = $this->frameAt($clipPath, max(0.0, $len - 0.3));
        if ($last !== null) $frames[] = $last;
        if ($frames === []) return null;
        $all = count($frames);
        $questions[] = 'Across all images: is it the same place in every image (no cut to another room or setting)?';
        $questions[] = 'Across all images: is the picture free of added words, subtitles, a recording dot or any camera or phone interface (the product\'s own label is fine)?';
        $questions[] = 'Across all images: is there exactly one of the product in each image where it is seen?';
        $questions[] = 'Across all images: '.($wardrobe !== '' ? 'does the presenter wear '.$wardrobe.' in every image where they are seen?' : 'does the presenter wear the same clothes in every image where they are seen?');

        $content = [['type' => 'text', 'text' => 'Images 1 to '.$all.' are frames, in order, from one generated video clip. Answer each question with yes or no, judging only what is visible; when the thing asked about is not in the frame, answer no.'
            ."\n\n".implode("\n", array_map(fn ($q, $n) => ($n + 1).'. '.$q, $questions, array_keys($questions)))
            ."\n\nAnswer JSON only: {\"answers\": [true or false, one per question, in order]}"]];
        foreach ($frames as $f) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($f)]];
        $key = (string) config('services.anthropic.key');
        if ($key === '') return null;
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 300, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) { Log::warning('ugc review: the sheet check failed', ['status' => $r->status()]); return null; }
            $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        } catch (\Throwable $e) {
            Log::warning('ugc review: the sheet check could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }

        return self::answers($text, $questions, $actions);
    }

    /**
     * The verdict from yes/no answers: every "no" is a problem, a block's "no" named by the action it should show.
     *
     * @return array{ok: bool, problems: list<string>, answers: list<array{q: string, yes: bool}>}|null
     */
    public static function answers(string $text, array $questions, array $actions): ?array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        $said = is_array($json['answers'] ?? null) ? array_values($json['answers']) : null;
        if ($said === null || count($said) !== count($questions)) return null;
        $answers = [];
        $problems = [];
        foreach ($questions as $k => $q) {
            $yes = $said[$k] === true || (is_string($said[$k]) && strtolower($said[$k]) === 'yes');
            $answers[] = ['q' => $q, 'yes' => $yes];
            if (! $yes) $problems[] = isset($actions[$k]) ? 'Show this happening: '.$actions[$k] : 'No to: '.preg_replace('/^Across all images:\s*/', '', $q);
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'answers' => $answers];
    }

    /**
     * When each block happens in the made clip: a block with words at the middle of those words as heard (take 289's
     * evenly spaced frames missed the bite between two lines), else its timestamps scaled to the clip's real length
     * (Omni picks its own length).
     *
     * @param  list<array{text: string, start: float, end: float}>  $heard
     * @return list<float>
     */
    public static function moments(array $blocks, float $len, array $heard): array
    {
        $norm = fn ($t) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $t));
        $spans = [];
        foreach ($blocks as $b) {
            $spans[] = preg_match('/(\d+):(\d+(?:\.\d+)?)\s*-\s*(\d+):(\d+(?:\.\d+)?)/', (string) $b['when'], $m)
                ? [(int) $m[1] * 60 + (float) $m[2], (int) $m[3] * 60 + (float) $m[4]] : null;
        }
        $planned = max(1.0, (float) max(array_map(fn ($s) => $s[1] ?? 0, $spans) ?: [0]));
        $scale = $planned > 1.0 ? $len / $planned : 1.0;
        $times = [];
        $cursor = 0;
        foreach ($blocks as $k => $b) {
            $at = $spans[$k] !== null ? ($spans[$k][0] + $spans[$k][1]) / 2 * $scale : $len * ($k + 0.5) / max(1, count($blocks));
            $said = array_values(array_filter(array_map($norm, preg_split('/\s+/u', (string) ($b['says'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));
            if ($said !== []) {
                $first = $last = null;
                for ($j = $cursor; $j < count($heard); $j++) {
                    if ($first === null && $norm($heard[$j]['text'] ?? '') === $said[0]) $first = $j;
                    if ($first !== null && $norm($heard[$j]['text'] ?? '') === end($said)) { $last = $j; break; }
                }
                if ($first !== null) {
                    $last ??= $first;
                    $at = ((float) $heard[$first]['start'] + (float) $heard[$last]['end']) / 2;
                    $cursor = $last + 1;
                }
            }
            $times[] = max(0.0, min($len - 0.1, $at));
        }

        return $times;
    }

    /** The clip's words with their times, or [] when it could not be transcribed. */
    private function heard(string $clipPath): array
    {
        try {
            $t = app(\App\Services\Media\MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($clipPath, 'video/mp4');
            return ($t['provider_key'] ?? '') === 'local_fallback' ? [] : array_values(array_filter((array) ($t['words'] ?? []), fn ($w) => isset($w['start'], $w['end'])));
        } catch (\Throwable) {
            return [];
        }
    }

    private function frameAt(string $clipPath, float $at): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'rvs-').'.jpg';
        Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', sprintf('%.2f', $at), '-i', $clipPath, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', $file]);
        $bytes = is_file($file) ? (string) file_get_contents($file) : '';
        @unlink($file);

        return $bytes !== '' ? $bytes : null;
    }

    /** One vision call; the images are sent small. */
    private function ask(string $text, array $images): ?array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') return null;
        $content = [['type' => 'text', 'text' => "You review frames for a short creator video ad. Be strict about what a viewer would notice, and ignore anything else.\n\n".$text
            ."\n\nAnswer JSON only: {\"ok\": true or false, \"problems\": [\"each problem as a short fix to make, e.g. 'the cap must come off: her fingers twist it open'\"]}; problems is empty when ok."]];
        foreach ($images as $img) {
            $jpeg = self::jpeg($img);
            if ($jpeg === null) return null;
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]];
        }
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 600, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) {
                Log::warning('ugc review: the vision call failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                return null;
            }
            $answer = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        } catch (\Throwable $e) {
            Log::warning('ugc review: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        $verdict = self::parse($answer);
        if ($verdict === null) Log::warning('ugc review: the answer was not a verdict', ['answer' => mb_substr($answer, 0, 300)]);

        return $verdict;
    }

    /** @return array{ok: bool, problems: list<string>}|null */
    public static function parse(string $text): ?array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json) || ! is_bool($json['ok'] ?? null)) return null;
        $problems = array_values(array_filter(array_map(fn ($p) => mb_substr(trim((string) $p), 0, 200), (array) ($json['problems'] ?? []))));

        return ['ok' => $json['ok'] && $problems === [], 'problems' => array_slice($problems, 0, 5)];
    }

    /** A data URI or raw image bytes as a small JPEG. */
    private static function jpeg(string $image): ?string
    {
        $bytes = str_starts_with($image, 'data:') ? base64_decode(substr($image, strpos($image, ',') + 1)) : $image;
        $in = tempnam(sys_get_temp_dir(), 'rv-');
        $out = $in.'.jpg';
        file_put_contents($in, $bytes);
        Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-i', $in, '-vf', 'scale=640:-2', '-q:v', '4', $out]);
        $jpeg = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($in); @unlink($out);

        return $jpeg !== '' ? $jpeg : null;
    }

    /** Frames spread across the clip, as JPEG bytes. */
    private function frames(string $clipPath, int $count): array
    {
        $len = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $clipPath])->output());
        if ($len <= 0) return [];
        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $file = tempnam(sys_get_temp_dir(), 'rvf-').'.jpg';
            Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', sprintf('%.2f', $len * ($k + 0.5) / $count), '-i', $clipPath, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', $file]);
            if (is_file($file) && filesize($file)) $out[] = (string) file_get_contents($file);
            @unlink($file);
        }

        return $out;
    }
}
