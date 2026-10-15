<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * A close-up of the presenter's face, cut from their own photo, sent to the video model beside the dressed picture
 * (owner, 2026-10-15: "get the images to a close match"). In the dressed waist-up picture the face is a small part of
 * the frame, and clips drifted to "a similar person" (scores 72–78). A face that fills its own reference gives the
 * model the detail to copy. Found by OpenCV's face detector in the extract service (a vision model's boxes were loose);
 * null when there is no face, the photo is already a close-up, or the step fails (the take goes on without it).
 */
class UgcFaceCrop
{
    /** A photo whose face already fills this much of it needs no close-up. */
    private const ALREADY_CLOSE = 0.35;

    public function crop(string $photoDataUri): ?string
    {
        if (! preg_match('#^data:(image/[a-z+]+);base64,(.+)$#s', $photoDataUri, $m)) return null;
        $box = $this->find($m[1], $m[2]);
        if ($box === null) return null;
        [$x0, $y0, $x1, $y1] = $box;
        if (($x1 - $x0) * ($y1 - $y0) >= self::ALREADY_CLOSE) return null;

        $in = tempnam(sys_get_temp_dir(), 'facecrop-');
        $out = $in.'.jpg';
        try {
            file_put_contents($in, base64_decode($m[2]));
            $size = trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', $in])->output());
            [$w, $h] = array_map('intval', explode('x', $size) + [1 => 0]);
            if ($w <= 0 || $h <= 0) return null;
            [$cx, $cy, $side] = self::square($box, $w, $h);
            $run = Process::timeout(60)->run(['ffmpeg', '-v', 'error', '-y', '-i', $in,
                '-vf', sprintf('crop=%d:%d:%d:%d,scale=768:768:flags=lanczos', $side, $side, $cx, $cy), '-frames:v', '1', '-q:v', '2', $out]);
            $bytes = $run->successful() && is_file($out) ? (string) file_get_contents($out) : '';
            return $bytes !== '' ? 'data:image/jpeg;base64,'.base64_encode($bytes) : null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * The square to cut, in pixels: the face with room for hair and chin (1.8x its larger side), centred a little above
     * the face's centre, kept inside the photo.
     *
     * @return array{0: int, 1: int, 2: int} left, top, side
     */
    public static function square(array $box, int $w, int $h): array
    {
        [$x0, $y0, $x1, $y1] = $box;
        $side = (int) min($w, $h, round(max(($x1 - $x0) * $w, ($y1 - $y0) * $h) * 1.8));
        $cx = ($x0 + $x1) / 2 * $w;
        $cy = (($y0 + $y1) / 2 - ($y1 - $y0) * 0.1) * $h;
        $left = (int) max(0, min($w - $side, round($cx - $side / 2)));
        $top = (int) max(0, min($h - $side, round($cy - $side / 2)));
        return [$left, $top, $side];
    }

    /** The face's box as fractions of the photo [left, top, right, bottom], from the extract service's face detector; null without one. */
    private function find(string $mime, string $data): ?array
    {
        $base = rtrim((string) config('services.extract.url', ''), '/');
        if ($base === '') return null;
        try {
            $r = Http::timeout(30)->attach('file', base64_decode($data), 'presenter.'.(str_contains($mime, 'png') ? 'png' : 'jpg'), ['Content-Type' => $mime])
                ->post($base.'/face/box');
            if (! $r->successful()) return null;
        } catch (\Throwable $e) {
            Log::warning('ugc face crop: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        return self::parse($r->body());
    }

    public static function parse(string $text): ?array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        $box = is_array($json) && ($json['face'] ?? null) === true ? ($json['box'] ?? null) : null;
        if (! is_array($box) || count($box) !== 4 || array_filter($box, fn ($v) => ! is_numeric($v))) return null;
        [$x0, $y0, $x1, $y1] = array_map('floatval', $box);
        if ($x0 < 0 || $y0 < 0 || $x1 > 1 || $y1 > 1 || $x1 - $x0 < 0.03 || $y1 - $y0 < 0.03) return null;
        return [$x0, $y0, $x1, $y1];
    }
}
