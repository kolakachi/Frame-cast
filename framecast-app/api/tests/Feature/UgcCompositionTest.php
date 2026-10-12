<?php

namespace Tests\Feature;

use App\Services\Media\StorageService;
use App\Services\Ugc\UgcComposition;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Exercise the real compositor with synthetic media, never paid providers. */
class UgcCompositionTest extends TestCase
{
    public function test_green_screen_and_split_keep_real_pixels_and_audio(): void
    {
        $dir = sys_get_temp_dir().'/ugc-composition-'.bin2hex(random_bytes(5));
        mkdir($dir);
        $temps = [];
        try {
            $base = $dir.'/presenter.mp4';
            Process::timeout(30)->run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=0x00FF00:s=180x320:d=1,drawbox=x=60:y=80:w=60:h=160:color=red:t=fill', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $base])->throw();
            foreach (['blue', 'yellow'] as $color) Process::timeout(30)->run(['ffmpeg', '-y', '-f', 'lavfi', '-i', "color=c={$color}:s=360x1280", '-frames:v', '1', $dir.'/'.$color.'.png'])->throw();
            $storage = $this->createMock(StorageService::class);
            $storage->method('readStream')->willReturnCallback(fn ($url) => fopen($url, 'rb'));
            $service = new UgcComposition;
            $asset = fn ($color) => ['url' => $dir.'/'.$color.'.png', 'type' => 'image'];
            foreach (['green_screen' => [$asset('blue')], 'split' => [$asset('blue'), $asset('yellow')]] as $layout => $assets) {
                $out = $service->render($base, ['layout' => $layout, 'assets' => $assets], $storage, $temps);
                $probe = Process::run(['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $out])->throw();
                $info = json_decode($probe->output(), true);
                $this->assertEqualsWithDelta(1, (float) $info['format']['duration'], .15);
                $this->assertContains('audio', array_column($info['streams'], 'codec_type'));
                $video = collect($info['streams'])->firstWhere('codec_type', 'video');
                $this->assertSame([720, 1280], [$video['width'], $video['height']]);
                // Interior points, away from padded edges or compression boundaries.
                $pixel = function (int $x, int $y) use ($out) {
                    $p = Process::run(['ffmpeg', '-v', 'error', '-i', $out, '-vf', "format=rgb24,crop=2:2:{$x}:{$y},scale=1:1", '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1'])->throw();
                    return array_values(unpack('C*', $p->output()));
                };
                [$r, $g, $b] = $pixel(200, 300);
                $this->assertGreaterThan(180, $b, $layout.' preserves the blue uploaded panel');
                $this->assertLessThan(70, $r);
                if ($layout === 'split') {
                    [$r, $g, $b] = $pixel(540, 600);
                    $this->assertGreaterThan(180, $r);
                    $this->assertGreaterThan(180, $g);
                    $this->assertLessThan(70, $b);
                } else {
                    [$r, $g, $b] = $pixel(500, 850);
                    $this->assertGreaterThan(180, $r, 'presenter remains visible over the keyed background');
                    $this->assertLessThan(80, $g);
                }
            }
        } finally {
            foreach ([...$temps, ...glob($dir.'/*')] as $path) @unlink($path);
            @rmdir($dir);
        }
    }
    public function test_export_preserves_native_sound_of_a_wordless_ugc_clip(): void
    {
        $dir = sys_get_temp_dir().'/ugc-audio-'.bin2hex(random_bytes(5));
        mkdir($dir);
        try {
            $base = $dir.'/source.mp4';
            Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x112:d=1', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $base])->throw();
            $renderer = new class {
                use \App\Traits\RendersExportScenes { renderSceneSegment as public render; }
                protected function materializeAsset(\App\Models\Asset $asset, string $tempDir, string $prefix): string
                {
                    $path = $tempDir.'/'.$prefix.'.mp4';
                    copy($asset->storage_url, $path);
                    return $path;
                }
            };
            $scene = new \App\Models\Scene(['script_text' => '', 'duration_seconds' => 1, 'visual_type' => 'video',
                'caption_settings_json' => ['enabled' => false], 'image_generation_settings_json' => ['ugc_kind' => 'b_roll']]);
            $out = $renderer->render(new \App\Models\Project, $scene, new \App\Models\Asset(['asset_type' => 'video', 'storage_url' => $base]), null, null,
                ['width' => 64, 'height' => 112], $dir, 0, 0, 1);
            $volume = Process::run(['ffmpeg', '-i', $out, '-af', 'volumedetect', '-f', 'null', '-'])->throw();
            preg_match('/mean_volume: (-?[0-9.]+) dB/', $volume->errorOutput(), $match);
            $this->assertNotEmpty($match, 'sound must survive export');
            $this->assertGreaterThan(-40, (float) $match[1]);
        } finally {
            foreach (glob($dir.'/*') as $path) @unlink($path);
            @rmdir($dir);
        }
    }

}
