<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\ScenePacing;
use App\Traits\RendersExportScenes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class VideoEndHoldTest extends TestCase
{
    public function test_short_video_holds_last_frame_without_truncating_voice(): void
    {
        $dir = sys_get_temp_dir().'/video-hold-'.bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            // Red first, blue last. A loop would turn red again at 1.2 seconds.
            $this->command(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=red:s=64x64:r=30:d=0.5',
                '-f', 'lavfi', '-i', 'color=blue:s=64x64:r=30:d=0.5', '-filter_complex',
                '[0:v][1:v]concat=n=2:v=1:a=0[v]', '-map', '[v]', '-c:v', 'libx264', $dir.'/source.mp4']);
            $this->command(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2.8', $dir.'/voice.wav']);
            $renderer = new class {
                use RendersExportScenes;
                protected function materializeAsset(Asset $asset, string $tempDir, string $prefix): string
                {
                    $path = $tempDir.'/'.$prefix.'.'.pathinfo($asset->storage_url, PATHINFO_EXTENSION);
                    copy($asset->storage_url, $path);
                    return $path;
                }
                public function render(string $dir, ?Asset $audio): string
                {
                    return $this->renderSceneSegment(new Project(), new Scene([
                        'duration_seconds' => 2.8, 'caption_settings_json' => ['enabled' => false],
                    ]), new Asset(['asset_type' => 'video', 'storage_url' => $dir.'/source.mp4']),
                        $audio, null, ['width' => 64, 'height' => 64], $dir, 0, 0, 2.8);
                }
            };
            foreach ([new Asset(['storage_url' => $dir.'/voice.wav', 'duration_seconds' => 2.8]), null] as $audio) {
                $output = $renderer->render($dir, $audio);
                $probe = json_decode($this->command(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,duration', '-of', 'json', $output]), true);
                foreach ($probe['streams'] as $stream) {
                    $this->assertEqualsWithDelta(2.8, (float) $stream['duration'], 0.1, $stream['codec_type']);
                }
                foreach ([1.2, 2.2] as $time) {
                    $pixel = $this->command(['ffmpeg', '-v', 'error', '-ss', (string) $time, '-i', $output,
                        '-frames:v', '1', '-vf', 'scale=1:1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1']);
                    $this->assertGreaterThan(150, ord($pixel[2]));
                    $this->assertLessThan(70, ord($pixel[0]), 'Animation restarted instead of holding blue.');
                }
            }
        } finally {
            foreach (glob($dir.'/*') as $path) unlink($path);
            rmdir($dir);
        }
    }

    public function test_animated_pacing_no_longer_assumes_replay(): void
    {
        $this->assertSame(12, ScenePacing::targetScenes(60, 'ai_video', true));
        $this->assertStringContainsString('Never assume the animation can loop', ScenePacing::guidance(60, 'ai_video', true));
    }

    private function command(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(30);
        $process->mustRun();
        return $process->getOutput();
    }
}
