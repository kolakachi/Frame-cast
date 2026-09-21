<?php
namespace Tests\Feature;

use App\Jobs\{BreakdownScenesJob, GenerateVisualBriefJob};
use App\Models\{Project, Scene};
use App\Services\{CreditService};
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis, Schema};
use Tests\TestCase;

class ScenePlanReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'database.default' => 'scene_review', 'database.connections.scene_review' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('scene_review'); Bus::fake(); Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);
        foreach ([
            'projects' => ['workspace_id', 'status', 'script_text', 'generation_status_json', 'duration_target_seconds', 'visual_generation_mode'],
            'scenes' => ['project_id', 'scene_order', 'scene_type', 'label', 'script_text', 'duration_seconds', 'voice_settings_json', 'visual_style', 'image_generation_settings_json', 'character_id', 'status'],
        ] as $table => $columns) Schema::create($table, function (Blueprint $t) use ($columns) {
            $t->id(); foreach ($columns as $column) $t->text($column)->nullable(); $t->timestamps();
        });
    }

    public function test_rejected_plan_preserves_existing_scenes_and_never_starts_media_or_charges(): void
    {
        $project = Project::create(['workspace_id' => 1, 'status' => 'generating', 'script_text' => 'Grind coffee beans.', 'duration_target_seconds' => 15]);
        $existing = Scene::create(['project_id' => $project->id, 'scene_order' => 1, 'script_text' => 'Original scene.']);
        $credits = $this->createMock(CreditService::class);
        $credits->expects($this->never())->method('deduct'); $this->app->instance(CreditService::class, $credits);
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->exactly(6))->method('generate')->willReturnCallback(fn ($template) => ['content' => $template === 'scene_breakdown'
            ? '{"scenes":[{"script_text":"Become more likable."}]}'
            : '{"decision":"repair","issues":["The topic changed from coffee."]}']);
        (new BreakdownScenesJob($project->id))->handle($ai);
        $this->assertSame('Original scene.', $existing->fresh()->script_text);
        $this->assertSame(1, Scene::count());
        $this->assertSame('failed', $project->fresh()->status);
        Bus::assertNotDispatched(GenerateVisualBriefJob::class);
    }

    public function test_valid_plan_proceeds_only_after_review(): void
    {
        $project = Project::create(['workspace_id' => 1, 'status' => 'generating', 'script_text' => 'Grind coffee beans.', 'duration_target_seconds' => 15]);
        $credits = $this->createMock(CreditService::class);
        $credits->expects($this->once())->method('deduct'); $this->app->instance(CreditService::class, $credits);
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->exactly(2))->method('generate')->willReturnOnConsecutiveCalls(
            ['content' => '{"scenes":[{"script_text":"Grind coffee beans."}]}'],
            ['content' => '{"decision":"pass","issues":[]}']);
        (new BreakdownScenesJob($project->id))->handle($ai);
        $this->assertSame('Grind coffee beans.', Scene::first()->script_text);
        Bus::assertDispatchedTimes(GenerateVisualBriefJob::class, 1);
    }
}
