<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Jobs\AnimateSceneJob;
use App\Models\{Project, Scene, User, Workspace};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Bus, DB, Schema};
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/** "Resume failed" never resends work that cannot succeed, and never twice (ws45 scene 920, 2026-10-05 and 10-12). */
class ResumeFailedTest extends TestCase
{
    use BuildsDeveloperSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'resume_test', 'database.connections.resume_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'cache.default' => 'array']);
        DB::purge('resume_test');
        Bus::fake();
        $this->buildDeveloperSchema();
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 5000]);
        $this->user = User::query()->create(['email' => 'o@acme.test', 'name' => 'Owner', 'role' => 'owner', 'status' => 'active']);
        $this->user->forceFill(['workspace_id' => $ws->id])->save();
        DB::table('assets')->insert([['id' => 1, 'workspace_id' => $ws->id, 'asset_type' => 'video', 'mime_type' => 'video/mp4', 'storage_url' => 'v.mp4'],
            ['id' => 2, 'workspace_id' => $ws->id, 'asset_type' => 'image', 'mime_type' => 'image/png', 'storage_url' => 'i.png']]);
        $p = Project::query()->create(['workspace_id' => $ws->id, 'status' => 'failed', 'source_type' => 'prompt']);
        DB::table('scenes')->insert([
            ['id' => 920, 'project_id' => $p->id, 'scene_order' => 1, 'visual_asset_id' => 1, 'image_generation_settings_json' => json_encode(['animation_last_error' => 'Animation needs a still image, but this scene has only a video.'])],
            ['id' => 921, 'project_id' => $p->id, 'scene_order' => 2, 'visual_asset_id' => 2, 'image_generation_settings_json' => json_encode(['animation_last_error' => 'Replicate timed out'])],
        ]);
    }

    private function resume(): \Illuminate\Http\JsonResponse
    {
        $r = Request::create('/x', 'POST');
        $r->setUserResolver(fn () => $this->user);

        return app(ProjectController::class)->resumeFailed($r, (int) Project::query()->value('id'));
    }

    public function test_a_video_scene_is_not_resent_and_loses_its_stale_error(): void
    {
        $this->assertSame(1, $this->resume()->getData(true)['data']['resumed']);
        Bus::assertDispatchedTimes(AnimateSceneJob::class, 1);
        Bus::assertDispatched(AnimateSceneJob::class, fn ($job) => $job->sceneId === 921);
        $this->assertArrayNotHasKey('animation_last_error', array_filter(Scene::query()->find(920)->image_generation_settings_json));
    }

    public function test_a_second_click_does_not_send_it_twice(): void
    {
        $this->resume();
        $this->assertSame(409, $this->resume()->getStatusCode());
        Bus::assertDispatchedTimes(AnimateSceneJob::class, 1);
    }
}
