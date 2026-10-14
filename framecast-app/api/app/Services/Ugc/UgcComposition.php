<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Models\User;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/** Real uploaded pixels, composited only after native speech/video generation. */
final class UgcComposition
{
    /** Where the presenter sits over a green-screen background (2026-10-14), and how large. Side and bubble ignore size. */
    public const PLACEMENTS = ['bottom_right', 'bottom_left', 'bottom_center', 'side_left', 'side_right', 'bubble_right', 'bubble_left'];
    public const SIZES = ['small', 'medium', 'large'];

    /**
     * The placement a brief asks for in plain words ("put me on the left", "small in the corner", "half the screen"),
     * so nobody fills a form. Nothing said: bottom right, medium (what every take had before).
     *
     * @return array{placement: string, size: string}
     */
    public static function placementFrom(string $text): array
    {
        $t = ' '.mb_strtolower($text).' ';
        $side = preg_match('/\bleft\b/', $t) ? 'left' : 'right';
        $placement = match (true) {
            (bool) preg_match('/\b(bubble|circle|round|loom)\b/', $t) => 'bubble_'.$side,
            (bool) preg_match('/\b(half (of )?the (screen|frame)|side by side|on the side|one side)\b/', $t) => 'side_'.$side,
            (bool) preg_match('/\b(cent(er|re)|middle)\b/', $t) && ! preg_match('/\b(left|right)\b/', $t) => 'bottom_center',
            default => 'bottom_'.$side,
        };
        $size = preg_match('/\b(small|smaller|tiny|little)\b/', $t) ? 'small' : (preg_match('/\b(large|larger|big|bigger)\b/', $t) ? 'large' : 'medium');
        return ['placement' => $placement, 'size' => $size];
    }

    public static function resolve(User $user, array $input): array
    {
        if ($input === []) return [];
        $layout = $input['layout'] ?? '';
        $keys = match ($layout) {
            'green_screen' => ['background_asset_id'],
            'split' => ['left_asset_id', 'right_asset_id'],
            default => throw ValidationException::withMessages(['composition' => 'Choose a supported composition.']),
        };
        $out = ['layout' => $layout, 'assets' => []];
        if ($layout === 'green_screen') {
            $placement = (string) ($input['placement'] ?? 'bottom_right'); $size = (string) ($input['size'] ?? 'medium');
            if (! in_array($placement, self::PLACEMENTS, true) || ! in_array($size, self::SIZES, true)) {
                throw ValidationException::withMessages(['composition.placement' => 'Choose a supported presenter position and size.']);
            }
            $out += ['placement' => $placement, 'size' => $size];
        }
        foreach ($keys as $key) {
            $asset = Asset::query()->whereKey((int) ($input[$key] ?? 0))->where('workspace_id', $user->workspace_id)
                ->whereIn('asset_type', ['image', 'video'])->whereNotNull('storage_url')->first();
            if (! $asset) throw ValidationException::withMessages(['composition.'.$key => 'Select an accessible image or video for every composition panel.']);
            $out['assets'][] = ['id' => (int) $asset->id, 'url' => (string) $asset->storage_url, 'type' => $asset->asset_type];
        }
        return $out;
    }

    /** Refuse a scene that ignored the key background instead of exporting a green rectangle. */
    /**
     * Whether a clip kept the plain key-colour backdrop it was asked for: three frames across the clip (one tiny frame at
     * 0.2 s let a clip that drew scenery later pass, and failed one whose first frame was a fade), two must show it.
     */
    public static function hasGreenBackground(string $path, string $key = '0x00FF00'): bool
    {
        $length = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output());
        $times = $length > 1 ? [$length * 0.2, $length * 0.5, $length * 0.8] : [0.2];
        $passed = 0;
        foreach ($times as $at) if (self::keyedFrame($path, $at, $key)) $passed++;

        return $passed >= min(2, count($times));
    }

    private static function keyedFrame(string $path, float $at, string $key): bool
    {
        $frame = Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-ss', sprintf('%.2f', $at), '-i', $path, '-vf', 'scale=32:32', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
        $bytes = $frame->output();
        if (! $frame->successful() || strlen($bytes) !== 32 * 32 * 3) return false;
        $green = 0;
        for ($i = 0; $i < strlen($bytes); $i += 3) {
            [$r, $g, $b] = [ord($bytes[$i]), ord($bytes[$i + 1]), ord($bytes[$i + 2])];
            $matches = match ($key) {
                '0x0000FF' => $b > 100 && $b > 1.5 * $r && $b > 1.5 * $g,
                '0xFF00FF' => $r > 100 && $b > 100 && $r > 1.5 * $g && $b > 1.5 * $g,
                default => $g > 100 && $g > 1.5 * $r && $g > 1.5 * $b,
            };
            if ($matches) $green++;
        }

        return $green / (32 * 32) >= 0.25;
    }

    public static function graph(string $layout, float $seconds = 0, string $key = '0x00FF00', string $placement = 'bottom_right', string $size = 'medium', ?float $bgAspect = null): string
    {
        if (! in_array($key, ['0x00FF00', '0x0000FF', '0xFF00FF'], true)) throw new \InvalidArgumentException('Unsupported chroma key.');
        $fit = fn ($w, $h) => "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=24,setpts=PTS-STARTPTS,tpad=stop_mode=clone:stop_duration={$seconds}";
        if ($layout === 'green_screen') {
            $hold = "fps=24,setpts=PTS-STARTPTS,tpad=stop_mode=clone:stop_duration={$seconds}";
            // The background fills the frame: cropped to 9:16 when it is near that shape (a phone recording loses its
            // status bar, not its middle); a wide page or desktop recording sits whole over a blurred copy of itself,
            // never between black bars.
            $bg = $bgAspect !== null && $bgAspect > 0.75
                ? "[1:v]split[bwide][bfit];[bwide]scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280,boxblur=20:2,eq=brightness=-0.06[bblur];"
                    ."[bfit]scale=720:1280:force_original_aspect_ratio=decrease[bfront];[bblur][bfront]overlay=(W-w)/2:(H-h)/2,setsar=1,{$hold}[bg]"
                : "[1:v]scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280,setsar=1,{$hold}[bg]";
            $keyed = "[0:v]setpts=PTS-STARTPTS,chromakey={$key}:0.18:0.08";
            if (str_starts_with($placement, 'bubble')) {
                // Head and shoulders in a circle above the captions, like a screen recording with a camera bubble.
                $x = $placement === 'bubble_left' ? '28' : 'W-w-28';
                return $bg.';'.$keyed.",crop=iw*0.62:iw*0.62:(iw-ow)/2:ih*0.06,scale=300:300[face];color=c=0x1c1c22:s=300x300:r=24[disc];"
                    ."[disc][face]overlay=0:0:shortest=1,format=rgba,geq=r='r(X,Y)':g='g(X,Y)':b='b(X,Y)':a='if(lte(hypot(X-150,Y-150),148),255,0)'[bubble];"
                    ."[bg][bubble]overlay={$x}:H-h-170:shortest=1,format=yuv420p[outv]";
            }
            $f = str_starts_with($placement, 'side') ? 0.8 : (['small' => 0.4, 'medium' => 0.6, 'large' => 0.75][$size] ?? 0.6);
            $w = 2 * (int) round(360 * $f); $h = 2 * (int) round(640 * $f);
            $x = match ($placement) { 'bottom_left', 'side_left' => '0', 'bottom_center' => '(W-w)/2', default => 'W-w' };
            return $bg.';'.$keyed.",scale={$w}:{$h}:force_original_aspect_ratio=decrease[person];[bg][person]overlay={$x}:H-h:shortest=1,format=yuv420p[outv]";
        }
        if ($layout === 'replace') return '[1:v]'.$fit(720, 1280).',format=yuv420p[outv]';
        if ($layout === 'split') {
            return '[1:v]'.$fit(360, 1280).'[left];[2:v]'.$fit(360, 1280).'[right];[left][right]hstack=inputs=2:shortest=1,format=yuv420p[outv]';
        }
        throw new \InvalidArgumentException('Unsupported UGC composition.');
    }

    public function source(array $asset, float $seconds, float $offset, StorageService $storage, array &$temps, bool $requireAudio = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ugc-source-');
        $temps[] = $path;
        $stream = $storage->readStream($asset['url']);
        if (! is_resource($stream)) throw new \RuntimeException('The selected UGC footage is unavailable.');
        try { file_put_contents($path, $stream); } finally { fclose($stream); }
        $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
        $hasAudio = $asset['type'] === 'video' && $probe->successful() && trim($probe->output()) !== '';
        if ($requireAudio && ! $hasAudio) throw new \RuntimeException('The selected ASMR footage has no audio. Supply a clip with its original sound.');
        $input = $asset['type'] === 'image' ? ['-loop', '1', '-i', $path] : ['-ss', (string) $offset, '-i', $path];
        if (! $hasAudio) array_push($input, '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo');
        $out = tempnam(sys_get_temp_dir(), 'ugc-source-render-').'.mp4';
        $temps[] = $out;
        $run = Process::timeout(300)->run(['ffmpeg', '-y', ...$input,
            '-vf', 'setpts=PTS-STARTPTS,scale=720:1280:force_original_aspect_ratio=decrease,pad=720:1280:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=24,tpad=stop_mode=clone:stop_duration='.$seconds,
            '-af', 'asetpts=PTS-STARTPTS,apad', '-map', '0:v:0', '-map', $hasAudio ? '0:a:0' : '1:a:0', '-t', (string) $seconds,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'aac', $out]);
        if (! $run->successful() || ! filesize($out)) throw new \RuntimeException('The selected footage could not be rendered at the planned duration.');
        return $out;
    }

    public function render(string $base, array $composition, StorageService $storage, array &$temps): string
    {
        $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $base]);
        $seconds = (float) trim($probe->output());
        if (! $probe->successful() || $seconds <= 0) throw new \RuntimeException('Could not measure the UGC take for compositing.');
        $inputs = ['-i', $base];
        $bgAspect = null;
        foreach ($composition['assets'] as $asset) {
            $path = tempnam(sys_get_temp_dir(), 'ugc-panel-');
            $temps[] = $path;
            $stream = $storage->readStream($asset['url']);
            if (! is_resource($stream)) throw new \RuntimeException('A composition file could not be read. Retry after restoring the file.');
            try { file_put_contents($path, $stream); } finally { fclose($stream); }
            if (! filesize($path)) throw new \RuntimeException('A composition file was empty.');
            if ($bgAspect === null) {
                [$bw, $bh] = array_map('intval', explode(',', trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $path])->output()).',0'));
                if ($bw > 0 && $bh > 0) $bgAspect = $bw / $bh;
            }
            $inputs = [...$inputs, ...($asset['type'] === 'image' ? ['-loop', '1'] : []), ...(! empty($asset['offset']) && $asset['type'] === 'video' ? ['-ss', (string) $asset['offset']] : []), '-i', $path];
        }
        $out = tempnam(sys_get_temp_dir(), 'ugc-composite-').'.mp4';
        $temps[] = $out;
        $run = Process::timeout(600)->run(['ffmpeg', '-y', ...$inputs, '-filter_complex', self::graph($composition['layout'], $seconds, $composition['key_color'] ?? '0x00FF00', (string) ($composition['placement'] ?? 'bottom_right'), (string) ($composition['size'] ?? 'medium'), $bgAspect),
            '-map', '[outv]', '-map', '0:a?', '-t', sprintf('%.3f', $seconds), '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'aac', '-movflags', '+faststart', $out]);
        if (! $run->successful() || ! filesize($out)) throw new \RuntimeException('The UGC composition failed. Retry to reuse the finished generation.');
        return $out;
    }
}
