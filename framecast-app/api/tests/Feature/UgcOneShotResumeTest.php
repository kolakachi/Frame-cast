<?php

namespace Tests\Feature;

use App\Jobs\GenerateOneShotUgcJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\Generation\Video\ReplicateVeoAdapter;
use App\Services\Media\StorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Process, Schema, Storage};
use Mockery;
use Tests\TestCase;

/** A one-take video that fails on a later clip resumes from that clip (owner, 2026-10-10), charged once on delivery. */
class UgcOneShotResumeTest extends TestCase
{
    private string $clip;
    private int $starts = 0;
    private ?int $failOn = null;
    private array $charged = [];
    private array $refunded = [];
    private string $tier = 'creator';
    private int $balance = 100000;
    private ?\Closure $onPoll = null;
    private array $startFrames = [];
    private array $askedSeconds = [];
    private array $shapes = [];

    private array $sentPrompts = [];
    private array $refs = [];
    private array $clipWords = ['transcript' => '', 'provider_key' => 'test', 'words' => []];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'oneshot_test', 'database.connections.oneshot_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('oneshot_test');
        Bus::fake();
        Storage::fake('local');
        Storage::fake('minio');
        foreach ([
            'projects' => ['workspace_id', 'created_by_user_id', 'title', 'aspect_ratio', 'duration_target_seconds', 'status', 'source_type', 'visual_brief'],
            'scenes' => ['project_id', 'scene_order', 'label', 'script_text', 'duration_seconds', 'visual_asset_id', 'status', 'image_generation_settings_json'],
            'assets' => ['workspace_id', 'asset_type', 'title', 'storage_url', 'mime_type', 'file_size_bytes', 'duration_seconds', 'tags', 'status', 'created_by_user_id', 'metadata_json', 'transcript_text', 'transcription_status', 'transcription_error'],
        ] as $table => $cols) {
            Schema::create($table, function (Blueprint $t) use ($cols) { $t->id(); foreach ($cols as $c) $t->text($c)->nullable(); $t->timestamps(); });
        }
        Schema::create('credit_ledger', function (Blueprint $t) { $t->id(); $t->integer('workspace_id')->nullable(); $t->integer('project_id')->nullable(); $t->string('operation')->nullable(); $t->integer('credits')->default(0); $t->decimal('upstream_cost_usd', 10, 6)->nullable(); $t->timestamps(); });
        Schema::create('api_usage_events', function (Blueprint $t) { $t->id(); $t->integer('project_id')->nullable(); $t->decimal('estimated_cost_usd', 12, 6)->default(0); });
        DB::table('projects')->insert(['id' => 7, 'workspace_id' => 3, 'title' => 'Crunchy Clouds', 'status' => 'generating', 'source_type' => 'ugc', 'visual_brief' => '{"ugc_format":"one_shot"}']);
        DB::table('scenes')->insert(['id' => 70, 'project_id' => 7, 'scene_order' => 1, 'label' => 'One-take ad', 'duration_seconds' => 18, 'image_generation_settings_json' => '{"in_progress":true}']);

        $this->clip = sys_get_temp_dir().'/oneshot-test-clip.mp4';
        if (! is_file($this->clip)) {
            Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x112:d=1', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
                '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $this->clip])->throw();
        }
        $veo = Mockery::mock(ReplicateVeoAdapter::class);
        $veo->shouldReceive('start')->andReturnUsing(function ($prompt, $seconds, $startFrame = null, $engine = null, $refs = [], $seed = null, $resolution = '720p', $videos = [], $aspect = '9:16') { $this->shapes[] = $aspect; $this->sentPrompts[] = $prompt; $this->askedSeconds[] = $seconds; $this->startFrames[] = $startFrame; $this->refs[] = $refs; return 'pred-'.(++$this->starts); });
        $veo->shouldReceive('pollUntilDone')->andReturnUsing(function ($id) {
            if ($this->failOn !== null && $id === 'pred-'.$this->failOn) throw new \RuntimeException('Veo generation failed: the model refused this clip');
            if ($this->onPoll) ($this->onPoll)();
            return $this->clip;
        });
        $this->app->instance(ReplicateVeoAdapter::class, $veo);
        $credits = Mockery::mock(CreditService::class)->shouldIgnoreMissing();
        $credits->shouldReceive('deduct')->andReturnUsing(function ($ws, $amount) { if ($amount > $this->balance) return false; $this->charged[] = $amount; return true; });
        $credits->shouldReceive('refund')->andReturnUsing(function ($ws, $amount) { $this->refunded[] = $amount; });
        $credits->shouldReceive('planTier')->andReturnUsing(fn () => $this->tier);
        $credits->shouldReceive('balance')->andReturnUsing(fn () => $this->balance);
        $this->app->instance(CreditService::class, $credits);
        $words = Mockery::mock(\App\Services\Media\MediaTranscriptionService::class);
        $words->shouldReceive('transcribeLocalMediaWithTimestamps')->andReturnUsing(fn () => $this->clipWords);
        $words->shouldReceive('transcribeAssetWithTimestamps')->andReturn(['transcript' => 'Okay so', 'provider_key' => 'test', 'model' => 'm',
            'words' => [['word' => 'Okay', 'start' => 0.1, 'end' => 0.4], ['word' => 'so', 'start' => 0.4, 'end' => 0.6]], 'segments' => []]);
        $this->app->instance(\App\Services\Media\MediaTranscriptionService::class, $words);
        $this->app->instance(\App\Services\UgcPassTakeService::class, Mockery::mock(\App\Services\UgcPassTakeService::class)->shouldIgnoreMissing());
    }

    private function job(?array $chunks = null, ?string $token = null): GenerateOneShotUgcJob
    {
        $chunks ??= [['prompt' => 'one', 'seconds' => 6], ['prompt' => 'two', 'seconds' => 8], ['prompt' => 'three', 'seconds' => 6]];
        return new GenerateOneShotUgcJob(7, 70, $chunks, 396, 'omni', ['data:image/png;base64,AAAA'], null, null, '720p', null, $token);
    }

    private function setToken(?string $token): void
    {
        $s = $this->settings();
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => json_encode(['oneshot_token' => $token] + $s)]);
    }

    private function run_(GenerateOneShotUgcJob $job): ?\Throwable
    {
        try { $job->handle(app(ReplicateVeoAdapter::class), app(StorageService::class), app(CreditService::class)); return null; }
        catch (\Throwable $e) { $job->failed($e); return $e; }
    }

    private function settings(): array
    {
        return Scene::query()->find(70)->image_generation_settings_json ?? [];
    }

    public function test_a_failed_take_resumes_from_the_clip_that_failed_and_is_charged_once(): void
    {
        $this->failOn = 3;
        $this->assertNotNull($this->run_($this->job()));
        $s = $this->settings();
        $this->assertStringContainsString('refused', $s['last_error']);
        $this->assertSame([0, 1], array_column($s['oneshot_parts'], 'i'), 'the two finished clips are kept');
        $this->assertSame([], $this->charged, 'a failure charges nothing');
        $this->assertCount(3, Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)), 'recipe and two clips');
        $this->assertSame([], Storage::disk('minio')->allFiles(), 'nothing of a run (the recipe holds the presenter\'s photo) goes near the public media bucket');

        $this->failOn = null;
        $this->assertSame('resumed', GenerateOneShotUgcJob::resume(Scene::query()->find(70)));
        $this->assertTrue($this->settings()['in_progress']);
        $resumed = null;
        Bus::assertDispatched(GenerateOneShotUgcJob::class, function ($job) use (&$resumed) { $resumed = $job; return true; });
        $this->assertSame([['prompt' => 'one', 'seconds' => 6], ['prompt' => 'two', 'seconds' => 8], ['prompt' => 'three', 'seconds' => 6]], $resumed->chunks);
        $this->assertSame(['data:image/png;base64,AAAA'], $resumed->referenceImages, 'the recipe is exact, images included');

        $startsBefore = $this->starts;
        $this->assertNull($this->run_($resumed));
        $this->assertSame(1, $this->starts - $startsBefore, 'only the clip that failed is made again');
        $this->assertSame([396], $this->charged, 'charged once, on delivery');
        $this->assertNotNull(Scene::query()->find(70)->visual_asset_id);
        $asset = \App\Models\Asset::query()->find(Scene::query()->find(70)->visual_asset_id);
        $this->assertSame('Okay', data_get($asset->metadata_json, 'caption_timing.words.0.word'), 'captions are timed from the video\'s own speech');
        $this->assertSame('ready_for_review', Project::query()->find(7)->status);
        $this->assertSame([], Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)), 'saved clips go once delivered');
        $this->assertArrayNotHasKey('oneshot_parts', $this->settings());
    }

    public function test_a_changed_recipe_never_reuses_old_clips(): void
    {
        $this->failOn = 2;
        $this->run_($this->job());
        $this->assertCount(1, $this->settings()['oneshot_parts']);

        $this->failOn = null;
        $startsBefore = $this->starts;
        $this->run_($this->job([['prompt' => 'new one', 'seconds' => 6], ['prompt' => 'new two', 'seconds' => 6]]));
        $this->assertSame(2, $this->starts - $startsBefore, 'a different script starts from the first clip');
    }

    public function test_what_cannot_resume_says_so(): void
    {
        $this->assertSame('running', GenerateOneShotUgcJob::resume(Scene::query()->find(70)), 'the first run is still going');
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => '{"last_error":"x"}']);
        $this->assertSame('not_resumable', GenerateOneShotUgcJob::resume(Scene::query()->find(70)), 'nothing saved yet');
        $this->failOn = 3;
        $this->run_($this->job());
        $this->balance = 10;
        $this->assertSame('short', GenerateOneShotUgcJob::resume(Scene::query()->find(70)), 'only resumes when the balance can still pay');
        $this->balance = 100000;
        $this->tier = 'ugc_pass';
        $this->assertSame('not_resumable', GenerateOneShotUgcJob::resume(Scene::query()->find(70)), 'a Test Pass take handed its reservation back');
    }

    public function test_deleting_the_take_or_a_week_passing_removes_saved_clips(): void
    {
        $this->failOn = 3;
        $this->run_($this->job());
        $this->artisan('ugc:prune-oneshot-parts')->assertSuccessful();
        $this->assertNotEmpty(Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)), 'kept within the week');

        DB::table('scenes')->where('id', 70)->update(['updated_at' => now()->subDays(GenerateOneShotUgcJob::PARTS_DAYS + 1)]);
        $this->artisan('ugc:prune-oneshot-parts')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)));

        $this->failOn = $this->starts + 3;
        $this->run_($this->job());
        $this->assertNotEmpty(Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)));
        Project::query()->find(7)->delete();
        $this->assertSame([], Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)));
    }

    public function test_two_retries_start_one_run_and_a_superseded_run_never_charges(): void
    {
        $this->setToken('first');
        $this->failOn = 3;
        $this->run_($this->job(null, 'first'));
        $this->assertSame('resumed', GenerateOneShotUgcJob::resume(Scene::query()->find(70)));
        $this->assertSame('running', GenerateOneShotUgcJob::resume(Scene::query()->find(70)), 'a second click while it runs');
        Bus::assertDispatchedTimes(GenerateOneShotUgcJob::class, 1);

        // The old run (or a redelivered copy of it) wakes up: it neither makes clips, marks the take failed, nor charges.
        $this->failOn = null;
        $startsBefore = $this->starts;
        $this->assertNull($this->run_($this->job(null, 'first')));
        $old = $this->job(null, 'first');
        $old->failed(new \RuntimeException('late failure'));
        $this->assertSame(0, $this->starts - $startsBefore);
        $this->assertSame([396], $this->charged, 'held once, by the resume; the superseded run charges nothing');
        $this->assertTrue($this->settings()['in_progress'], 'the new run keeps the take');
        $this->assertNull($this->settings()['last_error']);

        // A run superseded while it is making clips stops before the next one and never charges.
        $current = $this->settings()['oneshot_token'];
        $this->failOn = null;
        $this->onPoll = fn () => $this->setToken('newer');
        $startsBefore = $this->starts;
        $this->run_($this->job([['prompt' => 'x', 'seconds' => 6], ['prompt' => 'y', 'seconds' => 6]], $current));
        $this->assertSame([396], $this->charged, 'superseded mid-run: nothing beyond the one hold');
        $this->assertSame(1, $this->starts - $startsBefore, 'and it stops before making the next clip');
    }

    public function test_a_missing_middle_clip_is_made_again_with_everything_after_it(): void
    {
        $this->failOn = 3;
        $this->run_($this->job());
        $parts = $this->settings()['oneshot_parts'];
        Storage::disk('local')->delete(collect($parts)->firstWhere('i', 0)['url']);
        $this->failOn = null;
        $startsBefore = $this->starts;
        $this->run_($this->job());
        $this->assertSame(3, $this->starts - $startsBefore, 'clip 1 is not reused after clip 0 is remade');
        $this->assertSame([396], $this->charged);
    }

    public function test_clips_with_different_time_bases_join_at_their_real_length(): void
    {
        // Omni returned clips timed at 1/90000 and 1/12288 in one take; a copied join played 18 s as 6 s or 87 s.
        $clips = [];
        foreach ([90000, 12288, 90000] as $i => $scale) {
            $clips[$i] = sys_get_temp_dir()."/oneshot-tb-{$i}.mp4";
            Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=64x112:r=24:d=2', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
                '-t', '2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-video_track_timescale', (string) $scale, '-shortest', $clips[$i]])->throw();
        }
        $temps = [];
        $out = (fn () => $this->concat($clips, $temps))->call($this->job());
        $probe = fn ($stream) => (float) trim(Process::run(['ffprobe', '-v', 'error', '-select_streams', $stream, '-show_entries', 'stream=duration', '-of', 'csv=p=0', $out])->output());
        $this->assertEqualsWithDelta(6.0, $probe('v:0'), 0.2, 'the picture runs the real length');
        $this->assertEqualsWithDelta($probe('v:0'), $probe('a:0'), 0.2, 'sound and picture stay in step');
        foreach ([...$clips, ...$temps] as $f) @unlink($f);
    }

    public function test_a_new_shot_starts_from_the_photos_and_a_carried_on_shot_from_the_last_frame(): void
    {
        $this->run_($this->job([['prompt' => 'one', 'seconds' => 6, 'new_shot' => true], ['prompt' => 'cut to two', 'seconds' => 6, 'new_shot' => true],
            ['prompt' => 'two carries on', 'seconds' => 6, 'new_shot' => false]]));
        $this->assertNull($this->startFrames[0]);
        $this->assertNull($this->startFrames[1], 'a cut re-anchors on the reference photos');
        $this->assertSame('data:image/png;base64,AAAA', $this->refs[1][0], 'the presenter photo first');
        $this->assertStringStartsWith('data:image/png;base64,', $this->refs[1][1]);
        $this->assertNotSame('data:image/png;base64,AAAA', $this->refs[1][1], 'then the previous shot\'s last frame, for hair, clothes and setting');
        $this->assertStringStartsWith('data:image/png;base64,', (string) $this->startFrames[2], 'a carried-on shot starts from the last frame');
        $this->assertSame([396], $this->charged);
        $video = \App\Models\Asset::query()->find(Scene::query()->find(70)->visual_asset_id);
        $this->assertEqualsWithDelta(3 - 1 / 24, (float) $video->duration_seconds, 0.15, 'the duplicated first frame of the carried-on clip is dropped');
    }

    public function test_a_clip_is_kept_exactly_as_made_with_every_word_and_its_silences(): void
    {
        // Nothing is trimmed (owner, 2026-10-12): words after the line and the silence after the last word both stay.
        $long = sys_get_temp_dir().'/oneshot-test-long.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=green:s=64x112:r=24:d=4', '-f', 'lavfi', '-i', 'sine=frequency=300:sample_rate=48000',
            '-t', '4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $long])->throw();
        $this->clip = $long;
        $w = fn ($text, $start, $end) => ['text' => $text, 'start' => $start, 'end' => $end];
        $this->clipWords = ['transcript' => 'x', 'provider_key' => 'openai', 'words' => [
            $w('It', 0.1, 0.3), $w('soothes', 0.3, 0.8), $w('dry', 0.8, 1.1), $w('skin.', 1.1, 1.5), $w('One,', 1.7, 1.9), $w('two,', 1.9, 2.1), $w('three.', 2.1, 2.4)]];
        $this->run_($this->job([['prompt' => 'p', 'seconds' => 4, 'new_shot' => true, 'dialogue' => 'It soothes dry skin.']]));
        $video = \App\Models\Asset::query()->find(Scene::query()->find(70)->visual_asset_id);
        $this->assertEqualsWithDelta(4.0, (float) $video->duration_seconds, 0.15, 'the whole clip, as made');
        @unlink($long);
    }

    public function test_a_job_queued_before_run_tokens_still_runs_and_records_its_failure(): void
    {
        // A job serialized by the old code has no runToken in its payload.
        $job = $this->job();
        $payload = serialize($job);
        $old = unserialize(str_replace('s:8:"runToken";N;', '', $payload));
        $this->failOn = 1;
        $this->assertNotNull($this->run_($old));
        $this->assertStringContainsString('refused', $this->settings()['last_error'], 'its failure is recorded, not lost');
    }

    public function test_a_clip_without_sound_still_joins(): void
    {
        $mute = sys_get_temp_dir().'/oneshot-test-mute.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x112:r=24:d=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $mute])->throw();
        $temps = [];
        $clips = [$this->clip, $mute];
        $out = (fn () => $this->concat($clips, $temps))->call($this->job());
        $this->assertGreaterThan(2.5, (float) trim(Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $out])->output()));
        foreach ([$mute, ...$temps] as $f) @unlink($f);
    }

    public function test_a_delivered_take_never_resumes(): void
    {
        $this->run_($this->job());
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => json_encode(['last_error' => 'late timeout', 'oneshot_recipe' => 'x'] + $this->settings())]);
        $this->assertSame('not_resumable', GenerateOneShotUgcJob::resume(Scene::query()->find(70)));
    }
    public function test_a_line_heard_differently_is_kept_but_a_clip_without_speech_fails(): void
    {
        // #1 (2026-10-12): a transcript that does not match the line ("$29" heard as "twenty-nine dollars", a cut-off
        // ending) keeps the clip as made; failing it meant paying for clips a retry would fail the same way.
        $this->clipWords = ['provider_key' => 'test', 'words' => [
            ['text' => 'Try', 'start' => 0, 'end' => .2], ['text' => 'this', 'start' => .2, 'end' => .4],
        ]];
        $this->assertNull($this->run_($this->job([['prompt' => 'test', 'seconds' => 4, 'dialogue' => 'Try this product today.']])));
        $this->assertNotNull(Scene::find(70)->visual_asset_id, 'delivered uncut');
    }

    public function test_a_clip_without_speech_for_a_scripted_line_is_not_delivered_or_charged(): void
    {
        $this->clipWords = ['provider_key' => 'test', 'transcript' => '', 'words' => []];
        $error = $this->run_($this->job([['prompt' => 'test', 'seconds' => 4, 'dialogue' => 'Try this product today.']]));
        $this->assertNotNull($error);
        $this->assertStringContainsString('no speech', $error->getMessage());
        $this->assertSame([], $this->charged);
        $this->assertNull(Scene::find(70)->visual_asset_id);
    }

    public function test_a_clip_that_ignores_the_green_backdrop_is_remade_once_then_the_take_stops_uncharged(): void
    {
        // #5: the test clip is blue, never the green backdrop asked for.
        $job = new GenerateOneShotUgcJob(7, 70, [['prompt' => 'p', 'seconds' => 4, 'new_shot' => true]], 396, 'omni', ['data:image/png;base64,AAAA'], null, null, '720p', null, null, false,
            ['layout' => 'green_screen', 'key_color' => '0x00FF00']);
        $error = $this->run_($job);
        $this->assertStringContainsString('without the plain backdrop twice', (string) $error?->getMessage());
        $this->assertSame(2, $this->starts, 'one remake at our cost, then it stops instead of paying again');
        $this->assertSame([], $this->charged);
    }

    public function test_an_asmr_clip_that_comes_back_silent_is_remade_once_then_stops_uncharged(): void
    {
        // The test clip's sound track is silence: ASMR is its sound, so it is made once more, then the take stops.
        $error = $this->run_($this->job([['prompt' => 'Macro: the can cracks open.', 'seconds' => 4, 'requires_audio' => true, 'dialogue' => '']]));
        $this->assertStringContainsString('nearly silent twice', (string) $error?->getMessage());
        $this->assertSame(2, $this->starts, 'one remake at our cost, then it stops instead of paying again');
        $this->assertSame([], $this->charged);
    }

    public function test_an_asmr_clip_with_its_sound_and_nobody_talking_is_delivered(): void
    {
        $this->clip = sys_get_temp_dir().'/oneshot-asmr-clip.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x112:r=24:d=2', '-f', 'lavfi', '-i', 'anoisesrc=d=2:c=pink:a=0.2',
            '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $this->clip])->throw();
        $this->clipWords = ['transcript' => '', 'words' => [], 'segments' => [], 'provider_key' => 'test'];
        $error = $this->run_($this->job([['prompt' => 'Macro: the can cracks open.', 'seconds' => 2, 'requires_audio' => true, 'dialogue' => '']]));
        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertSame(1, $this->starts);
        $this->assertSame([396], $this->charged);
    }

    private function voiced(?\Closure $sync): array
    {
        $read = sys_get_temp_dir().'/oneshot-read.mp3';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'sine=f=220:d=2', '-c:a', 'libmp3lame', $read])->throw();
        Storage::disk('minio')->put('read.mp3', file_get_contents($read));
        $tts = Mockery::mock(\App\Services\Generation\TTS\TTSAdapter::class);
        $tts->shouldReceive('synthesize')->once()->withArgs(fn ($text, $lang, $voice, $speed, $opts) => $text === 'Okay so this works' && $voice === 'Puck' && $opts['voice_prompt'] === 'Excited')
            ->andReturn(['audio_url' => 'minio://read.mp3', 'duration_seconds' => 2.0, 'provider_key' => 'test', 'provider_voice_id' => 'Puck']);
        $this->app->instance(\App\Services\Generation\TTS\TTSAdapter::class, $tts);
        $calls = [];
        $lipsync = Mockery::mock(\App\Services\Generation\Video\ReplicateLipsyncAdapter::class);
        $lipsync->shouldReceive('sync')->andReturnUsing(function ($video, $audio) use (&$calls, $sync) { $calls[] = [$video, $audio]; return $sync ? $sync($video) : $video; });
        $this->app->instance(\App\Services\Generation\Video\ReplicateLipsyncAdapter::class, $lipsync);
        $this->clipWords = ['transcript' => 'Okay so this works', 'provider_key' => 'test', 'words' => [['word' => 'Okay', 'start' => 0.1, 'end' => 0.4], ['word' => 'so', 'start' => 0.4, 'end' => 0.6], ['word' => 'this', 'start' => 0.6, 'end' => 0.8], ['word' => 'works', 'start' => 0.8, 'end' => 1.2]]];
        $error = $this->run_($this->job([['prompt' => 'Talk to camera.', 'seconds' => 8, 'dialogue' => 'Okay so this works', 'voice' => ['id' => 'Puck', 'direction' => 'Excited', 'speed' => 1.0]]]));
        return [$error, $calls];
    }

    public function test_an_omni_line_is_read_once_and_synced_onto_a_clip_sized_to_the_read(): void
    {
        [$error, $calls] = $this->voiced(null);
        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertSame([3], $this->askedSeconds, 'the clip is asked for the read (2 s plus its tail), not the plan\'s 8 s');
        $this->assertCount(1, $calls);
        $this->assertStringEndsWith('.wav', $calls[0][1], 'Sync takes WAV');
        $this->assertSame([396], $this->charged);
    }

    public function test_a_failed_lip_sync_keeps_the_clip_with_omnis_own_voice(): void
    {
        [$error, $calls] = $this->voiced(fn () => throw new \RuntimeException('Lip-sync failed: busy'));
        $this->assertNull($error, (string) $error?->getMessage());
        $this->assertCount(1, $calls);
        $this->assertSame([396], $this->charged, 'a worse take, not a lost one');
    }

    public function test_a_line_without_a_voice_is_never_read_or_synced(): void
    {
        $this->app->instance(\App\Services\Generation\TTS\TTSAdapter::class, Mockery::mock(\App\Services\Generation\TTS\TTSAdapter::class)->shouldNotReceive('synthesize')->getMock());
        $this->app->instance(\App\Services\Generation\Video\ReplicateLipsyncAdapter::class, Mockery::mock(\App\Services\Generation\Video\ReplicateLipsyncAdapter::class)->shouldNotReceive('sync')->getMock());
        $this->clipWords = ['transcript' => 'Okay so', 'provider_key' => 'test', 'words' => [['word' => 'Okay', 'start' => 0.1, 'end' => 0.4], ['word' => 'so', 'start' => 0.4, 'end' => 0.6]]];
        $this->assertNull($this->run_($this->job([['prompt' => 'Talk.', 'seconds' => 6, 'dialogue' => 'Okay so']])));
        $this->assertSame([6], $this->askedSeconds);
    }

    public function test_skit_photos_travel_as_ids_and_are_read_only_from_the_workspace(): void
    {
        Storage::disk('minio')->put('ada.png', 'ada-bytes');
        DB::table('assets')->insert([['id' => 5, 'workspace_id' => 3, 'asset_type' => 'image', 'storage_url' => 'minio://ada.png', 'mime_type' => 'image/png'],
            ['id' => 6, 'workspace_id' => 99, 'asset_type' => 'image', 'storage_url' => 'minio://ada.png', 'mime_type' => 'image/png']]);
        $this->assertNull($this->run_($this->job([['prompt' => 'Ada', 'seconds' => 4, 'new_shot' => true, 'speaker' => 1, 'reference_asset_ids' => [5, 6]]])));
        $this->assertSame([['data:image/png;base64,'.base64_encode('ada-bytes')]], $this->refs, 'its own photo only; another workspace\'s is never read');
    }

    public function test_a_pov_hook_take_has_its_headlines_burned_in_at_their_times(): void
    {
        $this->clip = sys_get_temp_dir().'/oneshot-pov-clip.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=gray:s=180x320:r=24:d=4', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
            '-t', '4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $this->clip])->throw();
        $plain = sys_get_temp_dir().'/oneshot-pov-plain.png';
        Process::run(['ffmpeg', '-y', '-ss', '1', '-i', $this->clip, '-frames:v', '1', $plain])->throw();
        $error = $this->run_($this->job([['prompt' => 'A knowing look.', 'seconds' => 4, 'dialogue' => '',
            'headlines' => [['text' => 'POV: you found it', 'start' => 0, 'end' => 4]]]]));
        $this->assertNull($error, (string) $error?->getMessage());
        $asset = \App\Models\Asset::query()->where('tags', 'like', '%ugc_oneshot%')->latest('id')->first();
        $video = sys_get_temp_dir().'/oneshot-pov-made.mp4';
        file_put_contents($video, app(StorageService::class)->get((string) $asset->storage_url));
        $made = sys_get_temp_dir().'/oneshot-pov-made.png';
        Process::run(['ffmpeg', '-y', '-ss', '1', '-i', $video, '-frames:v', '1', $made])->throw();
        $this->assertNotSame(md5_file($plain), md5_file($made), 'the headline is drawn on the picture');
        $this->assertSame([396], $this->charged);
    }

    public function test_a_cast_presenter_s_face_close_up_rides_second_and_the_prompt_names_it(): void
    {
        $crop = Mockery::mock(\App\Services\Ugc\UgcFaceCrop::class);
        $crop->shouldReceive('crop')->once()->with('data:image/png;base64,FACE')->andReturn('face-close-up');
        $this->app->instance(\App\Services\Ugc\UgcFaceCrop::class, $crop);
        $job = new GenerateOneShotUgcJob(7, 70, [['prompt' => 'one', 'seconds' => 6], ['prompt' => 'two', 'seconds' => 6]], 396, 'omni',
            ['data:image/png;base64,FACE', 'product-a', 'product-b'], null, null, '720p', null, null, true);
        $this->assertNull($this->run_($job));
        $this->assertSame(['data:image/png;base64,FACE', 'face-close-up', 'product-a'], $this->refs[0], 'the face goes ahead of a second product angle');
        $this->assertStringContainsString('close-up of the presenter\'s face', $this->sentPrompts[0]);
    }

    public function test_saved_clips_of_a_take_that_no_longer_exists_are_swept(): void
    {
        Storage::disk('local')->put(GenerateOneShotUgcJob::partsDir(3, 999).'/abc/0.mp4', 'clip');
        Storage::disk('local')->put(GenerateOneShotUgcJob::partsDir(3, 70).'/abc/0.mp4', 'clip');
        $this->artisan('ugc:prune-oneshot-parts')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 999)), 'scene 999 is gone');
        $this->assertNotEmpty(Storage::disk('local')->allFiles(GenerateOneShotUgcJob::partsDir(3, 70)), 'a take that exists keeps its clips');
    }

    public function test_a_take_held_at_the_start_is_never_charged_again_and_its_charge_gets_the_real_cost(): void
    {
        DB::table('credit_ledger')->insert(['workspace_id' => 3, 'project_id' => 7, 'operation' => 'ugc_oneshot', 'credits' => 396]);
        DB::table('api_usage_events')->insert(['project_id' => 7, 'estimated_cost_usd' => 2.5]);
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => json_encode(['in_progress' => true, 'oneshot_held' => 396])]);
        $this->assertNull($this->run_($this->job()));
        $this->assertSame([], $this->charged, 'paid when it started');
        $this->assertEquals(2.5, (float) DB::table('credit_ledger')->where('project_id', 7)->value('upstream_cost_usd'));
        $this->assertArrayNotHasKey('oneshot_held', $this->settings());
    }

    public function test_a_held_take_that_fails_gives_its_credits_back_once_and_holds_them_again_to_resume(): void
    {
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => json_encode(['in_progress' => true, 'oneshot_held' => 396])]);
        $this->failOn = 2;
        $job = $this->job();
        $this->assertNotNull($this->run_($job));
        $job->failed(new \RuntimeException('again'));
        $this->assertSame([396], $this->refunded, 'given back once');
        $this->balance = 10;
        $this->assertSame('short', GenerateOneShotUgcJob::resume(Scene::query()->find(70)));
        $this->balance = 100000;
        $this->assertSame('resumed', GenerateOneShotUgcJob::resume(Scene::query()->find(70)));
        $this->assertSame([396], $this->charged, 'held again as it resumes');
        $this->assertSame(396, $this->settings()['oneshot_held']);
    }

    public function test_a_take_deleted_before_it_delivers_gives_its_held_credits_back(): void
    {
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => json_encode(['in_progress' => true, 'oneshot_held' => 396])]);
        Project::query()->find(7)->delete();
        $this->assertSame([396], $this->refunded);
    }

    public function test_every_clip_is_made_in_the_take_s_shape(): void
    {
        $job = new GenerateOneShotUgcJob(7, 70, [['prompt' => 'one', 'seconds' => 4], ['prompt' => 'two', 'seconds' => 4]], 396, 'seedance25', [], null, null, '720p', null, null, false, [], false, [], '16:9');
        $this->assertNull($this->run_($job));
        $this->assertSame(['16:9', '16:9'], $this->shapes);
    }

    public function test_every_finished_or_failed_take_is_queued_for_review(): void
    {
        $this->assertNull($this->run_($this->job()));
        Bus::assertDispatched(\App\Jobs\ReviewUgcTakeJob::class, fn ($j) => $j->take['outcome'] === 'delivered' && $j->take['project_id'] === 7 && $j->take['video_url'] !== null && count($j->take['clips']) === 3);
        $this->failOn = $this->starts + 1;
        DB::table('scenes')->where('id', 70)->update(['status' => 'draft', 'visual_asset_id' => null]);
        $this->assertNotNull($this->run_($this->job([['prompt' => 'again', 'seconds' => 4]])));
        Bus::assertDispatched(\App\Jobs\ReviewUgcTakeJob::class, fn ($j) => $j->take['outcome'] === 'failed' && str_contains((string) $j->take['error'], 'refused'));
    }

    public function test_the_charge_carries_what_the_take_really_cost(): void
    {
        DB::table('api_usage_events')->insert([['project_id' => 7, 'estimated_cost_usd' => 1.2], ['project_id' => 7, 'estimated_cost_usd' => 0.66], ['project_id' => 8, 'estimated_cost_usd' => 9]]);
        $this->assertSame(1.86, GenerateOneShotUgcJob::realCost(7));
        $this->assertNull(GenerateOneShotUgcJob::realCost(99));
    }

    public function test_skit_speakers_carry_the_place_photo_after_their_own(): void
    {
        Storage::disk('minio')->put('ada.png', 'ada-bytes');
        DB::table('assets')->insert(['id' => 5, 'workspace_id' => 3, 'asset_type' => 'image', 'storage_url' => 'minio://ada.png', 'mime_type' => 'image/png']);
        $job = new GenerateOneShotUgcJob(7, 70, [['prompt' => 'Ada', 'seconds' => 4, 'new_shot' => true, 'speaker' => 1, 'reference_asset_ids' => [5], 'place_at' => 1]],
            396, 'omni', ['product-photo', 'place-photo']);
        $this->assertNull($this->run_($job));
        $this->assertSame([['data:image/png;base64,'.base64_encode('ada-bytes'), 'place-photo']], $this->refs);
    }

    public function test_skit_uses_each_speakers_own_reference_without_the_other_speakers_tail(): void
    {
        $this->assertNull($this->run_($this->job([
            ['prompt' => 'Ada', 'seconds' => 4, 'new_shot' => true, 'reference_images' => ['ada-photo']],
            ['prompt' => 'Ben', 'seconds' => 4, 'new_shot' => true, 'reference_images' => ['ben-photo']],
        ])));
        $this->assertSame([['ada-photo'], ['ben-photo']], $this->refs);
        $this->assertSame([null, null], $this->startFrames);
    }

    public function test_uploaded_silent_footage_does_not_call_the_video_provider(): void
    {
        Storage::disk('minio')->put('source.mp4', file_get_contents($this->clip));
        $error = $this->run_($this->job([['prompt' => '', 'seconds' => 1, 'dialogue' => '',
            'source_media' => ['url' => 'minio://source.mp4', 'type' => 'video'], 'source_offset' => 0, 'requires_audio' => true]]));
        $this->assertNull($error, $error?->getMessage() ?? '');
        $this->assertSame(0, $this->starts);
        $this->assertSame('ready_for_review', Project::find(7)->status);
    }

    public function test_retry_keeps_the_composition_recipe(): void
    {
        $job = $this->job();
        $job->composition = ['layout' => 'split', 'assets' => [['id' => 1, 'url' => 'minio://left.png', 'type' => 'image'], ['id' => 2, 'url' => 'minio://right.png', 'type' => 'image']]];
        $this->failOn = 2;
        $this->assertNotNull($this->run_($job));
        $this->assertSame('resumed', GenerateOneShotUgcJob::resume(Scene::find(70)));
        Bus::assertDispatched(GenerateOneShotUgcJob::class, fn ($resumed) => $resumed->composition === $job->composition);
    }

    public function test_a_storyboard_take_starts_each_clip_from_its_still_and_a_faulted_clip_is_made_once_more(): void
    {
        $board = Mockery::mock(\App\Services\Ugc\UgcStoryboard::class);
        $board->notes = [0 => ['first' => ['ok' => true, 'problems' => []]]];
        $board->shouldReceive('make')->once()->andReturn(['data:image/png;base64,S0', 'data:image/png;base64,S1', 'data:image/png;base64,S2']);
        $this->app->instance(\App\Services\Ugc\UgcStoryboard::class, $board);
        $review = Mockery::mock(\App\Services\Ugc\UgcReview::class);
        // Clip 2 never opened the pouch; its remake did.
        $review->shouldReceive('clip')->andReturn(['ok' => true, 'problems' => []], ['ok' => false, 'problems' => ['the pouch must be opened']], ['ok' => true, 'problems' => []], ['ok' => true, 'problems' => []]);
        $this->app->instance(\App\Services\Ugc\UgcReview::class, $review);
        $chunks = [['prompt' => 'one', 'seconds' => 6, 'new_shot' => true], ['prompt' => 'two', 'seconds' => 6, 'new_shot' => true], ['prompt' => 'three', 'seconds' => 6, 'new_shot' => true]];
        $job = new GenerateOneShotUgcJob(7, 70, $chunks, 396, 'omni', ['data:image/png;base64,AAAA'], null, null, '720p', null, null, false, [], true);
        config(['services.ugc.review_remake' => true]);

        $this->assertNull($this->run_($job));
        $this->assertSame(['data:image/png;base64,S0', 'data:image/png;base64,S1', 'data:image/png;base64,S1', 'data:image/png;base64,S2'], $this->startFrames,
            'each clip starts from its own still, and the faulted clip is made again from the same still');
        $this->assertSame([396], $this->charged, 'the remake is ours: the take is charged its quote once');
        $s = $this->settings();
        $this->assertCount(3, $s['storyboard_stills']);
        $this->assertSame(['the pouch must be opened'], $s['clip_reviews']['2']['first']['problems']);
        $this->assertTrue($s['clip_reviews']['2']['remade']['ok']);
        $this->assertNull($s['last_error'] ?? null, 'no pause: the take runs straight through');
    }

    public function test_a_clip_with_a_timed_sheet_is_reviewed_and_by_default_only_recorded(): void
    {
        $review = Mockery::mock(\App\Services\Ugc\UgcReview::class);
        $review->shouldReceive('sheet')->once()->andReturn(['ok' => false, 'problems' => ['Show this happening: Right hand: pulls the seal open.'], 'answers' => []]);
        $this->app->instance(\App\Services\Ugc\UgcReview::class, $review);
        $sheet = ['start' => 'Sealed', 'blocks' => [['when' => '0-2s', 'left' => 'Holds the base', 'right' => 'Pulls the seal open', 'product' => 'Opening', 'face' => 'Not in frame', 'sound' => '', 'check' => 'Is it open?']], 'end' => ''];

        $this->assertNull($this->run_($this->job([['prompt' => 'one', 'seconds' => 6, 'new_shot' => true, 'sheet' => $sheet, 'trace' => true]])));
        $this->assertSame(1, $this->starts, 'no remake while the reviewer only records');
        $trace = $this->settings()['trace']['1'];
        $this->assertSame('one', $trace['prompt'], 'the trace keeps what the clip was asked');
        $this->assertStringContainsString('/ugc-trace/70/', $trace['raw_clip']);
        $this->assertNotNull($trace['seconds_kept']);
        $this->assertSame(['Show this happening: Right hand: pulls the seal open.'], $this->settings()['clip_reviews']['1']['first']['problems']);
        $this->assertSame([396], $this->charged);
    }

    public function test_a_take_with_a_look_dresses_the_presenter_once_and_every_clip_uses_that_picture(): void
    {
        $look = Mockery::mock(\App\Services\Ugc\UgcLook::class);
        $look->shouldReceive('make')->once()->with('data:image/png;base64,FACE', 'data:image/png;base64,PLACE', 'a rust sweater', 'a home desk', '9:16')
            ->andReturn(['uri' => 'data:image/png;base64,DRESSED', 'score' => 88]);
        $this->app->instance(\App\Services\Ugc\UgcLook::class, $look);
        $chunks = [['prompt' => 'one', 'seconds' => 6, 'new_shot' => true], ['prompt' => 'two', 'seconds' => 6, 'new_shot' => true]];
        $refs = ['data:image/png;base64,FACE', 'data:image/png;base64,PACK', 'data:image/png;base64,PLACE'];
        $this->failOn = 2;
        $job = new GenerateOneShotUgcJob(7, 70, $chunks, 396, 'omni', $refs, null, null, '720p', null, null, false, [], false, ['wardrobe' => 'a rust sweater', 'place' => 'a home desk', 'plate_at' => 2]);

        $this->assertNotNull($this->run_($job), 'clip 2 fails the first run');
        $this->assertSame(['data:image/png;base64,DRESSED', 'data:image/png;base64,PACK'], $this->refs[0], 'the dressed presenter in place of the cast photo, the place photo gone');
        $this->assertSame(88, $this->settings()['look']['face_score']);

        $this->failOn = null;
        $this->assertSame('resumed', GenerateOneShotUgcJob::resume(Scene::query()->find(70)));
        $resumed = null;
        Bus::assertDispatched(GenerateOneShotUgcJob::class, function ($j) use (&$resumed) { $resumed = $j; return true; });
        $this->assertSame(['wardrobe' => 'a rust sweater', 'place' => 'a home desk', 'plate_at' => 2], $resumed->look);
        $this->assertNull($this->run_($resumed));
        $last = end($this->refs);
        $this->assertSame('data:image/png;base64,DRESSED', $last[0], 'the resumed clip uses the same picture, made once');
        $this->assertStringStartsWith('data:image/png;base64,', $last[1]);
        $this->assertNotSame('data:image/png;base64,PACK', $last[1], 'then the last frame of the clip before: the room and hands as they are now');
        $this->assertSame('data:image/png;base64,PACK', $last[2], 'then the product');
        $this->assertSame([396], $this->charged);
    }

    public function test_a_clip_that_acts_after_its_last_words_keeps_its_tail(): void
    {
        $long = sys_get_temp_dir().'/oneshot-test-tail.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=green:s=64x112:r=24:d=4', '-f', 'lavfi', '-i', 'sine=frequency=300:sample_rate=48000',
            '-t', '4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $long])->throw();
        $this->clip = $long;
        $w = fn ($text, $start, $end) => ['text' => $text, 'start' => $start, 'end' => $end];
        $this->clipWords = ['transcript' => 'x', 'provider_key' => 'openai', 'words' => [$w('It', 0.1, 0.3), $w('soothes', 0.3, 0.8), $w('skin.', 0.8, 1.2)]];
        $sheet = ['start' => 's', 'end' => '', 'blocks' => [
            ['when' => '00:00-00:02', 'view' => '', 'left' => 'holds the camera', 'right' => 'holds the bottle', 'says' => 'It soothes skin.'],
            ['when' => '00:02-00:04', 'view' => 'the view tips down and settles on the desk', 'left' => 'sets the camera down', 'right' => 'holds the bottle', 'says' => ''],
        ]];
        $review = Mockery::mock(\App\Services\Ugc\UgcReview::class);
        $review->shouldReceive('sheet')->andReturn(null);
        $this->app->instance(\App\Services\Ugc\UgcReview::class, $review);
        $e = $this->run_($this->job([['prompt' => 'p', 'seconds' => 4, 'new_shot' => true, 'dialogue' => 'It soothes skin.', 'sheet' => $sheet]]));
        $this->assertNull($e, (string) $e?->getMessage());
        $video = \App\Models\Asset::query()->find(Scene::query()->find(70)->visual_asset_id);
        $this->assertEqualsWithDelta(4.0, (float) $video->duration_seconds, 0.15, 'the setting down after the words is kept');
        @unlink($long);
    }

    public function test_the_follow_on_is_recorded_and_only_applied_in_apply_mode(): void
    {
        $review = Mockery::mock(\App\Services\Ugc\UgcReview::class);
        $review->shouldReceive('sheet')->andReturn(null);
        $this->app->instance(\App\Services\Ugc\UgcReview::class, $review);
        $sheet = fn ($says) => ['camera' => 'selfie_arm', 'shot' => 's', 'start' => 'a', 'end' => '', 'blocks' => [['when' => '00:00-00:04', 'view' => '', 'left' => 'x', 'right' => 'y', 'product' => '', 'face' => '', 'says' => $says, 'sound' => '', 'check' => '']]];
        $adapted = ['camera' => 'selfie_arm', 'shot' => 'ADAPTED SHOT', 'start' => 'as it really ended', 'end' => '', 'blocks' => [['when' => '00:00-00:04', 'view' => '', 'left' => 'x', 'right' => 'y', 'product' => '', 'face' => '', 'says' => 'Okay so', 'sound' => '', 'check' => '']]];
        $follow = Mockery::mock(\App\Services\Ugc\UgcFollowOn::class);
        $follow->shouldReceive('follow')->andReturn(['close' => true, 'why' => 'kept to plan', 'ended' => [], 'next' => $adapted, 'valid' => true]);
        $this->app->instance(\App\Services\Ugc\UgcFollowOn::class, $follow);
        $this->clipWords = ['transcript' => 'Okay so', 'provider_key' => 'openai', 'words' => [['text' => 'Okay', 'start' => 0.1, 'end' => 0.4], ['text' => 'so', 'start' => 0.4, 'end' => 0.6]]];
        $chunks = [['prompt' => 'one', 'seconds' => 4, 'new_shot' => true, 'dialogue' => 'Okay so', 'sheet' => $sheet('Okay so')],
            ['prompt' => 'two', 'seconds' => 4, 'new_shot' => true, 'dialogue' => 'Okay so', 'sheet' => $sheet('Okay so')]];
        config(['services.ugc.follow_on' => 'record']);

        $e = $this->run_($this->job($chunks));
        $this->assertNull($e, (string) $e?->getMessage());
        $this->assertSame('two', $this->sentPrompts[1], 'recording only: the next clip is made as planned');
        $this->assertSame('record', $this->settings()['trace']['1']['follow_on']['mode']);

        config(['services.ugc.follow_on' => 'apply']);
        DB::table('scenes')->where('id', 70)->update(['image_generation_settings_json' => json_encode([])]);
        $this->sentPrompts = [];
        $this->run_($this->job($chunks));
        $this->assertStringContainsString('ADAPTED SHOT', $this->sentPrompts[1], 'apply: the next clip continues from how this one really ended');
        $this->assertSame('kept to plan', $this->settings()['trace']['1']['follow_on']['why']);
    }
}
