<?php

namespace Tests\Feature;

use App\Jobs\GenerateOneShotUgcJob;
use App\Models\Scene;
use App\Support\SceneRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** QUEUE-REAP (2026-10-12): work is timed from when its job starts, not from when it was queued. */
class SceneRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'run_test', 'database.connections.run_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('run_test');
        Schema::create('scenes', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('project_id')->nullable(); $t->text('image_generation_settings_json')->nullable(); $t->timestamps(); });
    }

    public function test_queued_work_is_waiting_until_its_job_starts(): void
    {
        $this->travelTo('2026-10-12 10:00:00');
        DB::table('scenes')->insert(['id' => 7, 'project_id' => 1, 'image_generation_settings_json' => json_encode(['in_progress' => true, 'generation_started_at' => now()->toIso8601String(), 'generation_token' => 'tok'])]);
        $scene = Scene::query()->find(7);
        $this->assertNull(SceneRun::runningSince($scene->image_generation_settings_json), 'queued, not started');

        $this->travelTo('2026-10-12 10:40:00'); // waited 40 minutes behind a long take
        SceneRun::started($scene->fresh(), SceneRun::IMAGE, 'stale-token');
        $this->assertNull(SceneRun::runningSince($scene->fresh()->image_generation_settings_json), 'a job a newer request replaced marks nothing');
        SceneRun::started($scene->fresh(), SceneRun::IMAGE, 'tok');
        $cfg = $scene->fresh()->image_generation_settings_json;
        $this->assertSame('2026-10-12T10:40:00+00:00', SceneRun::runningSince($cfg)?->toIso8601String());
        $this->assertSame($cfg['running_since'], $cfg['generation_started_at'], 'the timeout counts from the run, not the queue');

        // A new request later: the old run's mark no longer counts.
        $this->travelTo('2026-10-12 11:00:00');
        $cfg['generation_started_at'] = now()->toIso8601String();
        $this->assertNull(SceneRun::runningSince($cfg));
    }

    public function test_a_one_take_heartbeat_keeps_it_running_and_animations_have_their_own_clock(): void
    {
        $cfg = ['generation_started_at' => '2026-10-12T10:00:00+00:00', 'running_since' => '2026-10-12T10:00:00+00:00'];
        $this->travelTo('2026-10-12 10:20:00');
        $cfg = array_merge($cfg, SceneRun::beat());
        $this->assertSame('2026-10-12T10:20:00+00:00', SceneRun::runningSince($cfg)?->toIso8601String());
        $this->assertNull(SceneRun::runningSince(['animation_started_at' => '2026-10-12T10:20:00+00:00'] + $cfg, SceneRun::ANIMATION));
        $this->assertSame('ugc', (new GenerateOneShotUgcJob(1, 1, [['prompt' => 'x', 'seconds' => 8]], 0))->queue, 'one-takes run on their own worker');
    }
}
