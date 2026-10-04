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
            'systems' => [['id' => 's1', 'name' => 'Colour card', 'look' => 'Full-frame flat colour', 'entry' => 'hard cut', 'active' => 'holds', 'hold' => '2 s', 'exit' => 'hard cut'], ['name' => '']],
            'moments' => [['start' => 0, 'end' => 2, 'kind' => 'hook', 'on_screen_text' => 'Grow fast', 'visual' => 'red card', 'motion' => 'none', 'transition_in' => 'none', 'spoken' => 'Grow fast', 'purpose' => 'sets up the promise', 'system' => 's1'],
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
        // Recurring systems are described once; moments point at them and say what they do for the viewer.
        $this->assertSame([['id' => 's1', 'name' => 'Colour card', 'look' => 'Full-frame flat colour', 'entry' => 'hard cut', 'active' => 'holds', 'hold' => '2 s', 'exit' => 'hard cut']], $study['systems']);
        $this->assertSame(['sets up the promise', 's1'], [$study['moments'][0]['purpose'], $study['moments'][0]['system']]);
        $this->assertSame('', $study['moments'][1]['system'], 'an unknown system is not invented');
        // Frames are labelled with the words being spoken then; the model is told so.
        $this->assertTrue($study['sheets'][0]['labelled']);
        $this->assertSame('Grow fast.', $study['sheets'][0]['labels'][array_search(collect($study['samples'])->first(fn ($t) => $t >= 0.2 && $t <= 1.4), $study['sheets'][0]['times'])] ?? null);
        Http::assertSent(fn ($req) => str_contains(json_encode($req['messages']), 'the words being spoken then'));
        $this->assertTrue(ReferenceStudy::reusable($study, str_repeat('a', 64)));
        $this->assertFalse(ReferenceStudy::reusable([...$study, 'moments_status' => 'failed'], str_repeat('a', 64)), 'a study whose moment list failed is made again');
        $this->assertFalse(ReferenceStudy::reusable($study, str_repeat('b', 64)), 'different bytes, new study');
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
    }

    public function test_every_look_coverage_takes_one_frame_per_distinct_look_with_no_cap(): void
    {
        Storage::fake('local');
        config(['create.reference_coverage' => 'every_look', 'create.mode' => 'fixture']);
        $this->assertSame('maximum', ReferenceStudy::coverageMode(), 'the calibration override still means every look');
        config(['create.reference_coverage' => '']);
        $this->assertSame('standard', ReferenceStudy::coverageMode(), 'standard by default outside local testing');
        $this->assertSame('high', ReferenceStudy::coverageMode('high'), 'the conversation chooses');
        config(['create.reference_coverage' => 'every_look']);
        $dir = sys_get_temp_dir().'/study-'.uniqid(); mkdir($dir);
        // 3 s at 10 fps: a held red second, a white square stepping across the second (ten looks), a held blue second.
        $r = Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=320x180:d=1:r=10', '-f', 'lavfi', '-i', 'color=c=black:s=320x180:d=1:r=10',
            '-f', 'lavfi', '-i', 'color=c=blue:s=320x180:d=1:r=10', '-f', 'lavfi', '-i', 'color=c=white:s=60x60:d=1:r=10',
            '-filter_complex', "[1:v][3:v]overlay=x='floor(t*10)*26':y=60[b];[0:v][b][2:v]concat=n=3:v=1:a=0[v]", '-map', '[v]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $dir.'/ref.mp4']);
        $this->assertTrue($r->successful(), $r->errorOutput());
        $study = app(ReferenceStudy::class)->study($dir.'/ref.mp4', str_repeat('c', 64), $dir);
        $this->assertSame('maximum', $study['coverage_mode']);
        $this->assertSame(30, $study['frames']);
        $this->assertGreaterThanOrEqual(11, $study['looks'], 'each step of the square is its own look');
        $this->assertLessThanOrEqual(14, $study['looks'], 'held seconds are one look each');
        $this->assertCount($study['looks'], $study['samples']);
        $this->assertSame(count($study['samples']), array_sum(array_map(fn ($s) => count($s['times']), $study['sheets'])));
        $this->assertTrue(ReferenceStudy::reusable([...$study, 'moments_status' => 'ok'], str_repeat('c', 64)));
        $this->assertTrue(ReferenceStudy::reusable([...$study, 'coverage_mode' => 'every_look', 'moments_status' => 'ok'], str_repeat('c', 64), 'maximum'), 'older every_look studies count as maximum');
        config(['create.reference_coverage' => 'standard']);
        $this->assertFalse(ReferenceStudy::reusable([...$study, 'moments_status' => 'ok'], str_repeat('c', 64)), 'a different coverage mode studies again');
        config(['create.reference_coverage' => '']);
        $high = app(ReferenceStudy::class)->study($dir.'/ref.mp4', str_repeat('d', 64), $dir, 'high');
        $this->assertSame('high', $high['coverage_mode']);
        $this->assertLessThanOrEqual($study['looks'], $high['looks'], 'High keeps fewer looks than Maximum');
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
    }

    public function test_the_reference_pulse_is_read_as_a_tempo_and_cuts_on_the_beat_are_counted(): void
    {
        $dir = sys_get_temp_dir().'/beat-'.uniqid(); mkdir($dir);
        // A kick every 0.5 s (120 beats a minute) for 8 s.
        $r = Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "aevalsrc='if(lt(mod(t,0.5),0.04),0.8*sin(2*PI*60*t),0)':s=16000:d=8", $dir.'/kick.wav']);
        $this->assertTrue($r->successful(), $r->errorOutput());
        $m = new \ReflectionMethod(ReferenceStudy::class, 'lowBand'); $m->setAccessible(true);
        $db = $m->invoke(app(ReferenceStudy::class), $dir.'/kick.wav');
        $this->assertGreaterThan(700, count($db), '10 ms windows');
        $map = ReferenceStudy::beatMap($db, [1.0, 2.5, 3.27]);
        $this->assertTrue($map['present']);
        $this->assertEqualsWithDelta(120, $map['tempo_bpm'], 3);
        $this->assertEqualsWithDelta(0.67, $map['cuts_on_beat'], 0.01, 'two of three cuts land on a beat');
        $this->assertFalse(ReferenceStudy::beatMap(array_fill(0, 800, -90.0), [])['present'], 'silence has no pulse');
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
    }

    public function test_maximum_reads_the_unclear_stretches_again_frame_by_frame(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/two-pass-'.uniqid(); mkdir($dir);
        $r = Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=320x180:d=3:r=24', '-f', 'lavfi', '-i', 'color=c=white:s=60x60:d=3:r=24',
            '-filter_complex', "[0:v][1:v]overlay=x='if(between(t,1,2),(t-1)*260,-80)':y=60[v]", '-map', '[v]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $dir.'/ref.mp4']);
        $this->assertTrue($r->successful(), $r->errorOutput());
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.agent_model' => 'claude-opus-5-5']);
        $first = ['summary' => 'A square crosses a blue card.', 'moments' => [['start' => 1, 'end' => 2, 'kind' => 'sticker', 'visual' => 'white square', 'motion' => 'moves', 'purpose' => 'adds motion']],
            'systems' => [], 'patterns' => [], 'open_questions' => [['start' => 1, 'end' => 9, 'question' => 'Does the square ease in or move at constant speed?']]];
        $second = ['summary' => 'A square glides across at constant speed.', 'moments' => [['start' => 1, 'end' => 2, 'kind' => 'sticker', 'visual' => 'white square', 'motion' => 'slides left to right at constant speed', 'purpose' => 'adds motion']], 'systems' => [], 'patterns' => []];
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['usage' => ['input_tokens' => 1000, 'output_tokens' => 100], 'content' => [['type' => 'text', 'text' => json_encode($first)]]])
            ->push(['usage' => ['input_tokens' => 2000, 'output_tokens' => 200], 'content' => [['type' => 'text', 'text' => json_encode($second)]]])]);
        $study = app(ReferenceStudy::class)->study($dir.'/ref.mp4', str_repeat('e', 64), $dir, 'maximum');
        $this->assertSame(2, $study['passes']);
        $this->assertSame([['start' => 1.0, 'end' => 3.0, 'question' => 'Does the square ease in or move at constant speed?']], $study['open_questions'], 'a stretch is at most 2.5 s and inside the video');
        $this->assertSame('slides left to right at constant speed', $study['moments'][0]['motion'], 'the close reading replaces the first');
        Storage::disk('local')->assertExists($study['closeup_sheets'][0]);
        $this->assertSame(3000 * 5 + 300 * 25, $study['cost_microusd'], 'both readings are counted');
        $sent = Http::recorded();
        $this->assertStringContainsString('open_questions', json_encode($sent[0][0]['messages']));
        $this->assertStringContainsString('Close-up of 1 to 3 s', json_encode($sent[1][0]['messages']));
        $this->assertStringContainsString('Your first reading', json_encode($sent[1][0]['messages']));
        Http::fake(['api.anthropic.com/*' => Http::response(['usage' => [], 'content' => [['type' => 'text', 'text' => json_encode($first)]]])]);
        $this->assertSame(1, app(ReferenceStudy::class)->study($dir.'/ref.mp4', str_repeat('f', 64), $dir, 'high')['passes'] ?? 1, 'High reads once');
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
    }
}
