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
    private string $tier = 'creator';
    private ?\Closure $onPoll = null;
    private array $startFrames = [];
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
        DB::table('projects')->insert(['id' => 7, 'workspace_id' => 3, 'title' => 'Crunchy Clouds', 'status' => 'generating', 'source_type' => 'ugc', 'visual_brief' => '{"ugc_format":"one_shot"}']);
        DB::table('scenes')->insert(['id' => 70, 'project_id' => 7, 'scene_order' => 1, 'label' => 'One-take ad', 'duration_seconds' => 18, 'image_generation_settings_json' => '{"in_progress":true}']);

        $this->clip = sys_get_temp_dir().'/oneshot-test-clip.mp4';
        if (! is_file($this->clip)) {
            Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x112:d=1', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono',
                '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $this->clip])->throw();
        }
        $veo = Mockery::mock(ReplicateVeoAdapter::class);
        $veo->shouldReceive('start')->andReturnUsing(function ($prompt, $seconds, $startFrame = null, $engine = null, $refs = []) { $this->startFrames[] = $startFrame; $this->refs[] = $refs; return 'pred-'.(++$this->starts); });
        $veo->shouldReceive('pollUntilDone')->andReturnUsing(function ($id) {
            if ($this->failOn !== null && $id === 'pred-'.$this->failOn) throw new \RuntimeException('Veo generation failed: the model refused this clip');
            if ($this->onPoll) ($this->onPoll)();
            return $this->clip;
        });
        $this->app->instance(ReplicateVeoAdapter::class, $veo);
        $credits = Mockery::mock(CreditService::class)->shouldIgnoreMissing();
        $credits->shouldReceive('deduct')->andReturnUsing(function ($ws, $amount) { $this->charged[] = $amount; return true; });
        $credits->shouldReceive('planTier')->andReturnUsing(fn () => $this->tier);
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
        $this->assertSame([], $this->charged);
        $this->assertTrue($this->settings()['in_progress'], 'the new run keeps the take');
        $this->assertNull($this->settings()['last_error']);

        // A run superseded while it is making clips stops before the next one and never charges.
        $current = $this->settings()['oneshot_token'];
        $this->failOn = null;
        $this->onPoll = fn () => $this->setToken('newer');
        $startsBefore = $this->starts;
        $this->run_($this->job([['prompt' => 'x', 'seconds' => 6], ['prompt' => 'y', 'seconds' => 6]], $current));
        $this->assertSame([], $this->charged, 'superseded mid-run: no charge');
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

    public function test_a_clip_is_cut_after_its_last_scripted_word_so_joins_never_repeat(): void
    {
        // The model said its line, then ad-libbed "one two three" (2026-10-10 listicle): the clip ends after "skin".
        $long = sys_get_temp_dir().'/oneshot-test-long.mp4';
        Process::run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=green:s=64x112:r=24:d=4', '-f', 'lavfi', '-i', 'sine=frequency=300:sample_rate=48000',
            '-t', '4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $long])->throw();
        $this->clip = $long;
        $w = fn ($text, $start, $end) => ['text' => $text, 'start' => $start, 'end' => $end];
        $this->clipWords = ['transcript' => 'x', 'provider_key' => 'openai', 'words' => [
            $w('It', 0.1, 0.3), $w('soothes', 0.3, 0.8), $w('dry', 0.8, 1.1), $w('skin.', 1.1, 1.5), $w('One,', 2.0, 2.3), $w('two,', 2.4, 2.7), $w('three.', 2.8, 3.2)]];
        $this->run_($this->job([['prompt' => 'p', 'seconds' => 4, 'new_shot' => true, 'dialogue' => 'It soothes dry skin.']]));
        $video = \App\Models\Asset::query()->find(Scene::query()->find(70)->visual_asset_id);
        $this->assertEqualsWithDelta(2.5, (float) $video->duration_seconds, 0.15, 'the ad ends a second after "skin", the counting is gone');
        @unlink($long);
    }
}
