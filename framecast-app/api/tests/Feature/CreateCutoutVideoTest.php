<?php

namespace Tests\Feature;

use App\Services\Create\{CapabilityCatalogue, PlanMediaExecutor};
use Illuminate\Support\Facades\{Http, Process};
use Tests\TestCase;

/** A presenter cut out of a video's background: a VP9 WebM with a see-through background and its own sound. */
class CreateCutoutVideoTest extends TestCase
{
    public function test_a_take_comes_back_see_through_with_its_sound_and_bad_inputs_are_refused_before_any_provider(): void
    {
        $dir = sys_get_temp_dir().'/cutvid-'.uniqid(); mkdir($dir);
        // A 1 s portrait take taller than 720 px with sound, and the mask the model would return: white (kept) on the left half, black on the right.
        Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=s=96x1440:d=1:r=12', '-f', 'lavfi', '-i', 'sine=d=1', '-shortest', '-pix_fmt', 'yuv420p', $dir.'/take.mp4'])->throw();
        Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', "color=c=black:s=96x1440:d=1:r=12,geq=lum='if(lt(X,48),255,0)':cb=128:cr=128", '-pix_fmt', 'yuv420p', $dir.'/maskfile.mp4'])->throw();
        config(['services.replicate.api_token' => 't']);
        Http::fake([
            'https://api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/take']]),
            'https://api.replicate.com/v1/models/arielreplicate/robust_video_matting/predictions' => Http::response(['id' => 'p1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/mask.mp4']),
            'https://replicate.delivery/mask.mp4' => Http::response((string) file_get_contents($dir.'/maskfile.mp4')),
        ]);
        $r = app(PlanMediaExecutor::class)->produce('cutout_video', 'take.mp4 the presenter', ['cutout_files' => [['name' => 'take.mp4', 'path' => $dir.'/take.mp4']]], $dir);
        $this->assertSame('video/webm', $r['mime']);
        $probe = Process::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name:stream_tags=alpha_mode', '-of', 'compact', $r['path']])->output();
        $this->assertStringContainsString('codec_name=vp9|tag:alpha_mode=1', $probe, 'the video keeps an alpha channel');
        $this->assertStringContainsString('codec_name=opus', $probe, 'the take keeps its sound');
        $this->assertSame('48x720', trim(Process::run(['ffprobe', '-v', 'error', '-select_streams', 'v', '-show_entries', 'stream=width,height', '-of', 'csv=s=x:p=0', $r['path']])->output()), 'a tall take comes back at most 720 px tall, keeping its shape');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'robust_video_matting') && $req['input']['output_type'] === 'alpha-mask');
        $this->assertSame(CapabilityCatalogue::CUTOUT_VIDEO_CREDITS, collect(CapabilityCatalogue::forWorkspace(0))->firstWhere('kind', 'cutout_video')['credits']);

        // Nothing to cut out, or a still image: refused before any provider is called.
        Http::fake();
        $this->assertThrows(fn () => app(PlanMediaExecutor::class)->produce('cutout_video', 'missing.mp4', ['cutout_files' => [], 'cutout_latest_video' => null], $dir), \InvalidArgumentException::class);
        file_put_contents($dir.'/still.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABpfZFQAAAAABJRU5ErkJggg=='));
        $this->assertThrows(fn () => app(PlanMediaExecutor::class)->produce('cutout_video', 'still.png', ['cutout_files' => [['name' => 'still.png', 'path' => $dir.'/still.png']]], $dir), \InvalidArgumentException::class);
        Http::assertNothingSent();
    }
}
