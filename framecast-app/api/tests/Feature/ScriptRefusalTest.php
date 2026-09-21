<?php

namespace Tests\Feature;

use App\Jobs\BreakdownScenesJob;
use App\Jobs\GenerateScriptJob;
use App\Models\Project;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Media\MediaTranscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis, Schema};
use Tests\TestCase;

class ScriptRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['database.default' => 'refusal_test', 'database.connections.refusal_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('refusal_test');
        Bus::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            foreach ([
                'workspace_id', 'created_by_user_id', 'title', 'status', 'source_type',
                'source_content_raw', 'script_text', 'platform_target', 'tone',
                'primary_language', 'content_goal', 'niche_id', 'series_id',
                'generation_status_json', 'duration_target_seconds', 'allow_script_edit',
            ] as $c) {
                $t->text($c)->nullable();
            }
            $t->timestamps();
        });

        Schema::create('client_profiles', function (Blueprint $t) {
            $t->id();
            $t->text('workspace_id')->nullable();
            $t->text('brief')->nullable();
            $t->timestamps();
        });
    }

    private function project(): Project
    {
        return Project::query()->create([
            'workspace_id' => 1, 'created_by_user_id' => 1, 'title' => 'Set, so no title call',
            'status' => 'generating', 'source_type' => 'prompt',
            'source_content_raw' => 'Emma tells me she loves me and asks me to worship her feet.',
            'platform_target' => 'tiktok', 'duration_target_seconds' => 60, 'primary_language' => 'en',
        ]);
    }

    private function runJob(Project $project, string $modelOutput): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->method('generate')->willReturn(['content' => $modelOutput]);

        (new GenerateScriptJob($project->getKey()))
            ->handle($ai, $this->createMock(MediaTranscriptionService::class));
    }

    public function test_a_refusal_never_becomes_the_script(): void
    {
        $project = $this->project();

        $this->runJob($project, "I can't write this one — it reads as personalized romantic "
            ."roleplay content. If you'd like a TikTok script on being more likable, "
            ."here's an example of what that could look like…");

        $project->refresh();

        // The decline is not stored as the script, so nothing downstream can
        // build a video out of it.
        $this->assertNull($project->script_text);
        $this->assertSame('failed', $project->status);

        // And the pipeline stops here — no scenes, no TTS, no images.
        Bus::assertNotDispatched(BreakdownScenesJob::class);
    }

    public function test_the_user_is_told_instead_of_being_left_to_discover_it(): void
    {
        $project = $this->project();

        $this->runJob($project, "I'm not comfortable producing this script.");

        $stage = data_get($project->fresh()->generation_status_json, 'stages.script');

        $this->assertSame('failed', $stage['status']);
        $this->assertStringContainsString("couldn't write a script", $stage['message']);
    }

    public function test_a_normal_script_still_generates(): void
    {
        $project = $this->project();

        $this->runJob($project, "Here's your script: Three things likable people never do.");

        $project->refresh();

        // stripPreamble still trims the handover, the script is kept, and the
        // breakdown runs — the refusal guard is not in the ordinary path.
        $this->assertSame('Three things likable people never do.', $project->script_text);
        $this->assertNotSame('failed', $project->status);
        Bus::assertDispatched(BreakdownScenesJob::class);
    }
}
