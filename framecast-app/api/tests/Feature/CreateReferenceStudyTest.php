<?php

namespace Tests\Feature;

use App\Services\Create\PlanService;
use App\Services\Create\References\ReferenceStudy;
use Illuminate\Support\Facades\{Http, Process, Storage};
use Tests\TestCase;

class CreateReferenceStudyTest extends TestCase
{
    public function test_shots_change_windows_and_samples_cover_every_shot_and_short_moments(): void
    {
        $scores = [];
        for ($t = 0.0; $t < 7.0; $t += 0.1) {
            $v = 0.001;
            if (abs($t - 2.0) < 0.05 || abs($t - 5.0) < 0.05) $v = 0.6;      // two hard cuts
            if ($t > 3.0 && $t < 3.6) $v = 0.03;                              // something moving inside the second shot
            $scores[] = [round($t, 2), $v];
        }
        $cuts = ReferenceStudy::cuts($scores);
        $this->assertSame([2.0, 5.0], $cuts);
        $shots = ReferenceStudy::shots($cuts, 7.0);
        $this->assertSame([[0.0, 2.0], [2.0, 5.0], [5.0, 7.0]], $shots);
        $windows = ReferenceStudy::changeWindows($scores, $shots);
        $this->assertCount(1, $windows);
        $this->assertEqualsWithDelta(3.0, $windows[0]['start'], 0.15);
        $samples = ReferenceStudy::samples($shots, $windows, 7.0);
        foreach ($shots as [$s, $e]) $this->assertNotEmpty(array_filter($samples, fn ($t) => $t >= $s && $t < $e), 'every shot is sampled');
        $this->assertGreaterThanOrEqual(5, count(array_filter($samples, fn ($t) => $t >= $windows[0]['start'] && $t <= $windows[0]['end'])), 'the moment of change is looked at closely');
        $this->assertSame($samples, array_values(array_unique($samples)));
    }

    public function test_a_real_video_is_studied_into_sheets_speech_timing_and_a_timed_list_of_moments(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/study-'.uniqid(); mkdir($dir);
        // Three shots; a white square slides across the middle one between 3 and 4 seconds; a tone for audio.
        $r = Process::run(['ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=c=red:s=320x180:d=2:r=24', '-f', 'lavfi', '-i', 'color=c=blue:s=320x180:d=3:r=24', '-f', 'lavfi', '-i', 'color=c=green:s=320x180:d=2:r=24',
            '-f', 'lavfi', '-i', 'sine=frequency=300:sample_rate=16000:duration=7', '-f', 'lavfi', '-i', 'color=c=white:s=60x60:d=3:r=24',
            '-filter_complex', "[1:v][4:v]overlay=x='if(between(t,1,2),(t-1)*260,-80)':y=60[b];[0:v][b][2:v]concat=n=3:v=1:a=0[v]",
            '-map', '[v]', '-map', '3:a', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $dir.'/ref.mp4']);
        $this->assertTrue($r->successful(), $r->errorOutput());
        $stt = \Mockery::mock(\App\Services\Media\MediaTranscriptionService::class);
        $stt->shouldReceive('transcribeLocalMediaWithTimestamps')->once()->andReturn(['provider_key' => 'openai', 'transcript' => 'Grow fast. Here is how.',
            'words' => [['text' => 'Grow', 'start' => 0.2, 'end' => 0.5], ['text' => 'fast.', 'start' => 0.5, 'end' => 0.9], ['text' => 'Here', 'start' => 2.3, 'end' => 2.5], ['text' => 'is', 'start' => 2.5, 'end' => 2.6], ['text' => 'how.', 'start' => 2.6, 'end' => 2.9]]]);
        $this->app->instance(\App\Services\Media\MediaTranscriptionService::class, $stt);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.agent_model' => 'claude-opus-5-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['usage' => ['input_tokens' => 1000, 'output_tokens' => 200], 'content' => [['type' => 'text', 'text' => json_encode([
            'summary' => 'Three colour cards with a sliding badge.',
            'moments' => [['start' => 0, 'end' => 2, 'kind' => 'hook', 'on_screen_text' => 'Grow fast', 'visual' => 'red card', 'motion' => 'none', 'transition_in' => 'none', 'spoken' => 'Grow fast'],
                ['start' => 3, 'end' => 4, 'kind' => 'sticker', 'on_screen_text' => '', 'visual' => 'white badge slides across', 'motion' => 'slides left to right', 'transition_in' => 'none', 'spoken' => ''],
                ['start' => 2.4, 'end' => 5, 'kind' => 'text', 'on_screen_text' => 'Here is how', 'visual' => 'blue card', 'motion' => 'none', 'transition_in' => 'cut', 'spoken' => 'Here is how']],
            'patterns' => ['text_reveal' => 'text lands with the spoken words', 'signature' => 'the sliding badge']])]]])]);
        $study = app(ReferenceStudy::class)->study($dir.'/ref.mp4', str_repeat('a', 64), $dir);
        $this->assertSame(3, count($study['shots']), 'three shots: '.json_encode($study['shots']));
        $this->assertNotEmpty($study['change_windows'], 'the slide is found inside the blue shot');
        $this->assertTrue(collect($study['change_windows'])->contains(fn ($w) => $w['start'] >= 2.9 && $w['start'] <= 4.0));
        $this->assertNotEmpty($study['sheets']);
        Storage::disk('local')->assertExists($study['sheets'][0]['path']);
        $this->assertSame(count($study['samples']), array_sum(array_map(fn ($s) => count($s['times']), $study['sheets'])));
        // Speech timing and pacing from the transcript.
        $this->assertSame([['at' => 0.9, 'seconds' => 1.4, 'before' => 'Here']], $study['speech']['pauses']);
        $this->assertSame(3, $study['pacing']['shots']);
        $this->assertNotNull($study['pacing']['words_per_second']);
        // Moments come back ordered with ids, and text moments know when their words were spoken.
        $this->assertSame(['m1', 'm2', 'm3'], array_column($study['moments'], 'id'));
        $this->assertSame('sticker', $study['moments'][2]['kind']);
        $here = collect($study['moments'])->firstWhere('on_screen_text', 'Here is how');
        $this->assertEqualsWithDelta(0.1, $here['text_after_spoken_seconds'], 0.01, 'the text appears 0.1 s after "Here" is said');
        $this->assertSame(['text_reveal' => 'text lands with the spoken words', 'signature' => 'the sliding badge'], $study['patterns']);
        Http::assertSent(fn ($req) => collect($req['messages'][0]['content'])->where('type', 'image')->count() === count($study['sheets']));
        $this->assertSame('ok', $study['moments_status']);
        $this->assertTrue(ReferenceStudy::reusable($study, str_repeat('a', 64)));
        $this->assertFalse(ReferenceStudy::reusable([...$study, 'moments_status' => 'failed'], str_repeat('a', 64)), 'a study whose moment list failed is made again');
        $this->assertFalse(ReferenceStudy::reusable($study, str_repeat('b', 64)), 'different bytes, new study');
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
    }

}
