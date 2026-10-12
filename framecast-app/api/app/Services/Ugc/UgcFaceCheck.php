<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Is the presenter in a clip the person in their photo? (owner, 2026-10-11: "at least 99% success in resemblance".)
 * A cheap vision pass on one frame from the middle of the clip against the presenter's reference photo. Clothes,
 * light, angle and expression are ignored; only who it is counts. Null when there is no face to judge (hands only,
 * a product shot) or the check could not run: neither is a pass or a fail.
 */
class UgcFaceCheck
{
    /** Below this the clip is made again once. */
    public const PASS = 70;

    public function score(string $presenterDataUri, string $clipPath): ?int
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || ! preg_match('#^data:(image/[a-z+]+);base64,(.+)$#s', $presenterDataUri, $m)) return null;
        $frame = $this->middleFrame($clipPath);
        if ($frame === null) return null;
        return $this->compare($presenterDataUri, $frame);
    }

    /** The same check for a still image (a data URI), such as the take's dressed reference (UgcLook). */
    public function scoreImage(string $presenterDataUri, string $imageDataUri): ?int
    {
        if (! preg_match('#^data:image/[a-z+]+;base64,(.+)$#s', $imageDataUri, $m)) return null;
        $in = tempnam(sys_get_temp_dir(), 'facecheck-img-');
        $out = $in.'.jpg';
        file_put_contents($in, base64_decode($m[1]));
        Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-i', $in, '-vf', 'scale=640:-2', '-q:v', '4', $out]);
        $frame = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($in); @unlink($out);

        return $frame !== '' ? $this->compare($presenterDataUri, $frame) : null;
    }

    private function compare(string $presenterDataUri, string $frame): ?int
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '' || ! preg_match('#^data:(image/[a-z+]+);base64,(.+)$#s', $presenterDataUri, $m)) return null;
        $content = [
            ['type' => 'text', 'text' => 'Image 1 is a reference photo of a person. Image 2 is a frame from a video that should show that same person. '
                .'Judge identity only: face shape, eyes, nose, mouth, skin tone, age, hairline and hair. Ignore clothes, lighting, angle, expression and background. '
                .'Answer JSON only: {"face": true or false (is a person\'s face clearly visible in image 2), "score": 0-100 (how sure you are it is the same person; 50 means unsure)}.'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[2]]],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($frame)]],
        ];
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 200, 'messages' => [['role' => 'user', 'content' => $content]]]);
            $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            if (! $r->successful()) {
                \Illuminate\Support\Facades\Log::warning('ugc face check: the vision call failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                return null;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ugc face check: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        return self::parse($text);
    }

    /** The score from the model's answer; null when it saw no face or answered something else. */
    public static function parse(string $text): ?int
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json) || ($json['face'] ?? null) !== true || ! is_numeric($json['score'] ?? null)) return null;
        return (int) max(0, min(100, (int) $json['score']));
    }

    private function middleFrame(string $clipPath): ?string
    {
        $len = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $clipPath])->output());
        $base = tempnam(sys_get_temp_dir(), 'facecheck-');
        $out = $base.'.jpg';
        Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', sprintf('%.2f', max(0, $len / 2)), '-i', $clipPath, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', $out]);
        $bytes = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($out);
        @unlink($base);
        return $bytes !== '' ? $bytes : null;
    }
}
