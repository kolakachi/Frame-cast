<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\Process;

/**
 * Source footage is made renderable once, when it arrives: a screen recording with keyframes seconds apart, or at 60
 * fps, makes the renderer's seeks fail on clips cut from deep inside it. Such a file is re-encoded at 30 fps with a
 * keyframe every second; a silent video stays silent. Files already fine are left untouched.
 */
class VideoIntake
{
    public const MAX_GAP = 2.0;
    public const MAX_FPS = 30.5;

    /** What needs fixing: ['gap' => largest keyframe gap in seconds, 'fps' => frame rate, 'fix' => bool]. */
    public static function inspect(string $path): array
    {
        $kf = Process::timeout(120)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-skip_frame', 'nokey', '-show_entries', 'frame=pts_time', '-of', 'csv=p=0', $path]);
        $times = array_values(array_filter(array_map('floatval', preg_split('/\s+/', trim($kf->output()))), fn ($t) => $t >= 0));
        $dur = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output());
        $gap = 0.0;
        foreach ($times as $i => $t) if ($i > 0) $gap = max($gap, $t - $times[$i - 1]);
        if ($times) $gap = max($gap, $dur - end($times));
        [$n, $d] = array_map('floatval', explode('/', trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=avg_frame_rate', '-of', 'csv=p=0', $path])->output()).'/1'));
        $fps = $d > 0 ? $n / $d : 0.0;
        return ['gap' => round($gap, 2), 'fps' => round($fps, 2), 'fix' => $kf->successful() && ($gap > self::MAX_GAP || $fps > self::MAX_FPS)];
    }

    /** The renderable file: $path itself when fine, else a re-encoded copy next to it. */
    public static function prepare(string $path): string
    {
        $info = self::inspect($path);
        if (! $info['fix']) return $path;
        $out = $path.'.renderable.mp4';
        $r = Process::timeout(600)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $path, '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2', '-r', $info['fps'] > self::MAX_FPS ? '30' : (string) max(1, round($info['fps'])),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-g', '30', '-keyint_min', '30', '-sc_threshold', '0',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $out]);
        if (! $r->successful() || ! is_file($out) || filesize($out) === 0) throw new \RuntimeException('That video could not be prepared for editing. Try exporting it again as an MP4.');
        return $out;
    }
}
