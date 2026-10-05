<?php

namespace Tests\Feature;

use App\Jobs\AnimateSceneJob;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CruiseControl\CruiseActionRunService;
use App\Services\Generation\Video\I2VAdapter;
use App\Services\Generation\Video\PredictionStillRunning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Broadcast, DB, Event, Http, Redis, Schema};
use RuntimeException;
use Tests\TestCase;

/**
 * A slow video model is not a failed one. A backed-up Seedance Pro took 71–96
 * minutes; giving up at six refunded the customer, started duplicate
 * predictions, and left the clips we were billed for uncollected. These pin
 * the replacement: keep the prediction and the charge while it runs, collect
 * it later, refund exactly once if it really fails or passes the ceiling, and
 * never let a progress broadcast fail paid work.
 */
class SlowAnimationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['database.default' => 'slow_test', 'database.connections.slow_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('slow_test');
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex')->andReturn(true);
        foreach ([
            'workspaces' => ['name', 'plan_tier', 'status'],
            'projects'   => ['workspace_id', 'channel_id', 'created_by_user_id', 'aspect_ratio', 'title', 'status'],
            'scenes'     => ['project_id', 'scene_order', 'visual_asset_id', 'visual_style', 'image_generation_settings_json'],
            'assets'     => ['workspace_id', 'channel_id', 'asset_type', 'title', 'description', 'storage_url',
                             'thumbnail_url', 'duration_seconds', 'dimensions_json', 'mime_type', 'tags', 'usage_count', 'status', 'created_by_user_id', 'source'],
            'users'      => ['workspace_id', 'email'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $c) { $t->text($c)->nullable(); }
                $t->timestamps();
            });
        }
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('operation');
            foreach (['spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
            }
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->integer('credits'); $t->integer('balance_after')->nullable();
            $t->json('metadata')->nullable(); $t->timestamps();
        });
        Schema::table('workspaces', fn (Blueprint $t) => $t->integer('credits_topup')->default(0));
        Schema::table('workspaces', fn (Blueprint $t) => $t->integer('credits_monthly')->default(0));
        $this->app->instance(CruiseActionRunService::class, \Mockery::mock(CruiseActionRunService::class)->shouldIgnoreMissing());
    }

    /** @return array{0:Scene,1:int} */
    private function scene(array $settings = []): array
    {
        $ws = DB::table('workspaces')->insertGetId(['name' => 'W', 'plan_tier' => 'starter', 'status' => 'active', 'credits_topup' => 1000]);
        $project = Project::query()->create(['workspace_id' => $ws, 'aspect_ratio' => '9:16', 'title' => 'Ad', 'status' => 'ready_for_review']);
        $still = Asset::query()->create(['workspace_id' => $ws, 'asset_type' => 'image', 'title' => 'still',
            'storage_url' => 'https://cdn.test/still.png', 'mime_type' => 'image/png']);
        $scene = Scene::query()->create(['project_id' => $project->id, 'scene_order' => 1, 'visual_asset_id' => $still->id,
            'image_generation_settings_json' => $settings]);

        return [$scene, $ws];
    }

    private function credits(int $ws): int
    {
        return (int) DB::table('workspaces')->where('id', $ws)->value('credits_topup');
    }

    private function adapter(?\Closure $animate = null, ?\Closure $poll = null): object
    {
        $a = new class($animate, $poll) implements I2VAdapter {
            public array $cancelled = [];
            public function __construct(private ?\Closure $animate, private ?\Closure $poll) {}
            public function animate(string $imageUrl, string $prompt, string $tier = 'quick', int $durationSeconds = 6, array $options = []): array
            {
                return ($this->animate)($options);
            }
            public function providerKey(): string { return 'fake'; }
            public function pollExisting(string $predictionId): ?string { return ($this->poll)($predictionId); }
            public function cancel(string $predictionId): void { $this->cancelled[] = $predictionId; }
        };
        $this->app->instance(I2VAdapter::class, $a);

        return $a;
    }

    public function test_a_slow_clip_keeps_its_prediction_and_charge_instead_of_failing(): void
    {
        Event::fake([\App\Events\GenerationProgressed::class]);
        [$scene, $ws] = $this->scene();
        $this->adapter(function (array $o) {
            ($o['on_prediction_created'])('pred-slow');
            throw new PredictionStillRunning('pred-slow', 'bytedance/seedance-1-pro');
        });

        app()->call([new AnimateSceneJob($scene->id, $scene->project_id, 'seedance_lite', 5, 'push in'), 'handle']);

        $s = $scene->fresh()->image_generation_settings_json;
        $this->assertTrue($s['animation_in_progress'], 'still running, so still in progress');
        $this->assertTrue($s['animation_still_rendering']);
        $this->assertSame('pred-slow', $s['animation_prediction_id'], 'kept for the reaper to collect');
        $this->assertNull($s['animation_last_error']);
        $this->assertSame(1000 - 30, $this->credits($ws), 'charged once, not refunded');
    }

    public function test_a_resumed_clip_that_fails_is_refunded_exactly_once(): void
    {
        Event::fake([\App\Events\GenerationProgressed::class]);
        [$scene, $ws] = $this->scene(['animation_in_progress' => true, 'animation_prediction_id' => 'pred-1', 'animation_cost' => 30,
            'animation_tier' => 'seedance_lite', 'animation_still_rendering' => true, 'animation_prediction_started_at' => now()->subMinutes(40)->toIso8601String()]);
        DB::table('workspaces')->where('id', $ws)->update(['credits_topup' => 970]);
        $this->adapter(null, fn () => throw new RuntimeException('Replicate i2v failed: Prediction interrupted'));

        foreach ([1, 2] as $attempt) {
            try {
                app()->call([new AnimateSceneJob($scene->id, $scene->project_id, 'seedance_lite', 5, null, 'pred-1'), 'handle']);
                $this->fail('a failed resume must surface');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('interrupted', $e->getMessage());
            }
        }

        $s = $scene->fresh()->image_generation_settings_json;
        $this->assertSame(1000, $this->credits($ws), 'the original charge comes back once, not twice');
        $this->assertFalse($s['animation_in_progress']);
        $this->assertNull($s['animation_prediction_id'], 'nothing left for the reaper to resume');
    }

    public function test_a_clip_past_the_ceiling_is_cancelled_and_refunded(): void
    {
        Event::fake([\App\Events\GenerationProgressed::class]);
        [$scene, $ws] = $this->scene(['animation_in_progress' => true, 'animation_prediction_id' => 'pred-old', 'animation_cost' => 30,
            'animation_tier' => 'seedance_lite', 'animation_prediction_started_at' => now()->subHours(4)->toIso8601String()]);
        DB::table('workspaces')->where('id', $ws)->update(['credits_topup' => 970]);
        $fake = $this->adapter(null, fn () => $this->fail('past the ceiling it must not wait again'));

        try {
            app()->call([new AnimateSceneJob($scene->id, $scene->project_id, 'seedance_lite', 5, null, 'pred-old'), 'handle']);
        } catch (RuntimeException) {
        }

        $this->assertSame(['pred-old'], $fake->cancelled);
        $this->assertSame(1000, $this->credits($ws));
    }

    public function test_a_resumed_clip_that_lands_finishes_without_a_second_charge(): void
    {
        Event::fake([\App\Events\GenerationProgressed::class]);
        [$scene, $ws] = $this->scene(['animation_in_progress' => true, 'animation_prediction_id' => 'pred-ok', 'animation_cost' => 30,
            'animation_tier' => 'seedance_lite', 'animation_prediction_started_at' => now()->subMinutes(80)->toIso8601String()]);
        $this->adapter(null, fn () => 'https://replicate.test/clip.mp4');
        Http::fake(['replicate.test/*' => Http::response('mp4-bytes')]);
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('put')->andReturn('b2://clip.mp4');
        $storage->shouldReceive('extractPath')->andReturn(null);
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);

        app()->call([new AnimateSceneJob($scene->id, $scene->project_id, 'seedance_lite', 5, null, 'pred-ok'), 'handle']);

        $s = $scene->fresh()->image_generation_settings_json;
        $this->assertFalse($s['animation_in_progress']);
        $this->assertNull($s['animation_prediction_id']);
        $this->assertSame(1000, $this->credits($ws), 'a resume never charges');
        $this->assertSame('video', Asset::query()->find($scene->fresh()->visual_asset_id)->asset_type);
    }

    public function test_an_unreachable_socket_server_cannot_fail_paid_work(): void
    {
        Broadcast::shouldReceive('queue')->andThrow(new RuntimeException('Pusher error: cURL error 7: Failed to connect to reverb'));

        \App\Events\GenerationProgressed::dispatch(1, 'animation', 'processing');

        $this->assertTrue(true, 'the broadcast failure did not escape');
    }

    public function test_a_provider_that_cannot_fetch_our_image_gets_it_through_its_own_file_store(): void
    {
        $prod = "Replicate i2v failed: HTTPSConnectionPool(host='s3.us-east-005.backblazeb2.com', port=443): Max retries exceeded with url: /frame-cast/workspaces/75/assets/ai-images/aa.png (Caused by NewConnectionError(...))";
        $this->assertTrue(AnimateSceneJob::inputFetchFailed($prod));
        $this->assertFalse(AnimateSceneJob::inputFetchFailed('Replicate i2v failed: Prediction failed: ModelError: The input or output was flagged as sensitive. (E005)'), 'a refusal is not a fetch failure');
        Event::fake([\App\Events\GenerationProgressed::class]);
        [$scene, $ws] = $this->scene();
        $urls = [];
        $this->adapter(function (array $o) use (&$urls) { throw new PredictionStillRunning('p', 'm'); });
        $fake = new class($urls) implements I2VAdapter {
            public function __construct(private array &$urls) {}
            public function animate(string $imageUrl, string $prompt, string $tier = 'quick', int $durationSeconds = 6, array $options = []): array {
                $this->urls[] = $imageUrl;
                if (count($this->urls) === 1) throw new RuntimeException("Replicate i2v failed: HTTPSConnectionPool(host='s3.us-east-005.backblazeb2.com', port=443): Max retries exceeded (Caused by NewConnectionError)");
                ($options['on_prediction_created'])('p2'); throw new PredictionStillRunning('p2', 'm');
            }
            public function providerKey(): string { return 'fake'; }
            public function pollExisting(string $predictionId): ?string { return null; }
        };
        $this->app->instance(I2VAdapter::class, $fake);
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('extractPath')->andReturn(null);
        $storage->shouldReceive('get')->andReturn('png-bytes');
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        Http::fake(['api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/abc']])]);
        app()->call([new AnimateSceneJob($scene->id, $scene->project_id, 'seedance_lite', 5, 'push'), 'handle']);
        $this->assertSame(['https://cdn.test/still.png', 'https://api.replicate.com/v1/files/abc'], $urls, 'the second start uses the provider\'s own copy');
        $this->assertSame(1000 - 30, $this->credits($ws), 'still charged once');
    }
}
