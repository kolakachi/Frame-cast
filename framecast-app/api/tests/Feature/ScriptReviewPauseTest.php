<?php

namespace Tests\Feature;

use App\Jobs\GenerateProjectAIImagesJob;
use App\Jobs\MatchVisualsJob;
use App\Jobs\ScoreHooksJob;
use App\Models\Project;
use App\Services\CreditService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Redis, Schema};
use Tests\TestCase;

/** "Let me review the script first": the build stops before pictures and voice until approved (2026-10-28). */
class ScriptReviewPauseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'review_test', 'database.connections.review_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Bus::fake();
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex')->andReturn(true);
        Schema::create('projects', function (Blueprint $t) { $t->id(); foreach (['workspace_id', 'status', 'source_type', 'visual_generation_mode', 'visual_brief', 'generation_status_json'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
        Schema::create('project_hook_options', function (Blueprint $t) { $t->id(); foreach (['project_id', 'sort_order', 'hook_text', 'hook_score', 'hook_score_reason'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
        Schema::create('scenes', function (Blueprint $t) { $t->id(); foreach (['project_id', 'scene_order', 'scene_type', 'script_text'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
    }

    public function test_the_build_waits_for_the_script_and_goes_on_once_approved(): void
    {
        $project = Project::query()->create(['workspace_id' => 1, 'status' => 'generating', 'source_type' => 'prompt', 'visual_generation_mode' => 'ai_images', 'visual_brief' => ['script_review' => 'requested']]);
        (new ScoreHooksJob($project->id))->handle($this->createMock(AIGenerationAdapter::class));
        Bus::assertNotDispatched(GenerateProjectAIImagesJob::class);
        $this->assertSame('waiting', data_get($project->fresh()->visual_brief, 'script_review'));

        $project = $project->fresh();
        $project->forceFill(['visual_brief' => array_merge($project->visual_brief, ['script_review' => 'approved'])])->save();
        ScoreHooksJob::resumeAfterReview($project->fresh());
        Bus::assertDispatched(GenerateProjectAIImagesJob::class);
    }

    public function test_a_project_that_did_not_ask_goes_straight_on(): void
    {
        $project = Project::query()->create(['workspace_id' => 1, 'status' => 'generating', 'source_type' => 'prompt', 'visual_generation_mode' => 'stock']);
        (new ScoreHooksJob($project->id))->handle($this->createMock(AIGenerationAdapter::class));
        Bus::assertDispatched(MatchVisualsJob::class);
    }

    public function test_the_review_price_is_a_picture_and_a_voice_line_per_scene(): void
    {
        $per = app(CreditService::class)->perSceneCost('ai_images', 'Puck');
        $this->assertSame(app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null), $per['visual']);
        $this->assertSame(CreditService::TTS_GEMINI, $per['voice']);
    }
}
