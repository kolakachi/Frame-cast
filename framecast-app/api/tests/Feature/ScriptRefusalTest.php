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
        config(['cache.default' => 'array', 'services.ai.review_retry_delay_ms' => 0]);
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
        $ai->method('generate')->willReturnCallback(fn ($template) => ['content' => $template === 'content_fidelity_review' ? json_encode(['decision' => 'pass', 'issues' => []]) : $modelOutput]);

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
        $this->assertStringContainsString("couldn't generate this requested content", $stage['message']);
    }

    public function test_a_normal_script_still_generates(): void
    {
        $project = $this->project();

        $project->update(['source_content_raw' => 'Write a video about being likable.']);
        $this->runJob($project, "Here's your script: Three things likable people never do.");

        $project->refresh();

        // stripPreamble still trims the handover, the script is kept, and the
        // breakdown runs — the refusal guard is not in the ordinary path.
        $this->assertSame('Three things likable people never do.', $project->script_text);
        $this->assertNotSame('failed', $project->status);
        Bus::assertDispatched(BreakdownScenesJob::class);
    }
    public function test_unrelated_drafts_are_bounded_and_never_charged_or_dispatched(): void
    {
        $project = $this->project();
        $credits = $this->createMock(\App\Services\CreditService::class);
        $credits->expects($this->never())->method('deduct');
        $this->app->instance(\App\Services\CreditService::class, $credits);
        $ai = $this->createMock(AIGenerationAdapter::class);
        $drafts = 0;
        $ai->method('generate')->willReturnCallback(function ($template) use (&$drafts) {
            if ($template === 'content_fidelity_review') return ['content' => json_encode(['decision' => 'repair', 'issues' => ['The topic was replaced.']])];
            $drafts++;
            return ['content' => 'Three things likable people never do.'];
        });
        (new GenerateScriptJob($project->id))->handle($ai, $this->createMock(MediaTranscriptionService::class));
        $this->assertSame(3, $drafts);
        $this->assertNull($project->fresh()->script_text);
        $this->assertSame('failed', $project->fresh()->status);
        Bus::assertNotDispatched(BreakdownScenesJob::class);
    }

    public function test_unavailable_reviewer_does_not_authorize_production(): void
    {
        $project = $this->project();
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->exactly(4))->method('generate')->willReturnOnConsecutiveCalls(
            ['content' => 'A plausible candidate.'], ['content' => 'not JSON'], ['content' => 'not JSON'], ['content' => 'not JSON']);
        (new GenerateScriptJob($project->id))->handle($ai, $this->createMock(MediaTranscriptionService::class));
        $this->assertSame('unavailable', data_get($project->fresh()->generation_status_json, 'stages.script.validation_reason'));
        Bus::assertNotDispatched(BreakdownScenesJob::class);
    }

    public function test_repaired_script_is_reviewed_before_it_is_saved(): void
    {
        $project = $this->project();
        $project->update(['source_content_raw' => 'Explain coffee brewing.']);
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->exactly(4))->method('generate')->willReturnOnConsecutiveCalls(
            ['content' => 'Unrelated draft.'], ['content' => '{"decision":"repair","issues":["Explain coffee instead."]}'],
            ['content' => 'Grind your coffee beans.'], ['content' => '{"decision":"pass","issues":[]}']);
        (new GenerateScriptJob($project->id))->handle($ai, $this->createMock(MediaTranscriptionService::class));
        $this->assertSame('Grind your coffee beans.', $project->fresh()->script_text);
        Bus::assertDispatchedTimes(BreakdownScenesJob::class, 1);
    }

    public function test_structured_refusal_stops_even_without_refusal_text(): void
    {
        $project = $this->project();
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->once())->method('generate')->willReturn(['content' => 'An unrelated alternative.', 'refusal' => 'Declined']);
        (new GenerateScriptJob($project->id))->handle($ai, $this->createMock(MediaTranscriptionService::class));
        $this->assertNull($project->fresh()->script_text);
        Bus::assertNotDispatched(BreakdownScenesJob::class);
    }

    public function test_verbatim_user_script_is_not_rewritten(): void
    {
        $project = $this->project();
        $project->update(['source_type' => 'script', 'allow_script_edit' => false, 'source_content_raw' => 'Keep these exact words.']);
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->never())->method('generate');
        (new GenerateScriptJob($project->id))->handle($ai, $this->createMock(MediaTranscriptionService::class));
        $this->assertSame('Keep these exact words.', $project->fresh()->script_text);
        Bus::assertDispatchedTimes(BreakdownScenesJob::class, 1);
    }

    public function test_reviewer_retry_keeps_the_cleaned_draft_without_regeneration(): void
    {
        $project = $this->project();
        $project->update(['source_content_raw' => 'Explain coffee brewing.']);
        $ai = $this->createMock(AIGenerationAdapter::class);
        $drafts = 0; $reviews = 0;
        $ai->method('generate')->willReturnCallback(function ($template, $variables) use (&$drafts, &$reviews) {
            if ($template !== 'content_fidelity_review') {
                $drafts++;
                return ['content' => "Here's your script: Grind coffee beans."];
            }
            $reviews++;
            $this->assertSame('Grind coffee beans.', json_decode($variables['payload'], true)['candidate']);
            if ($reviews === 1) throw new \RuntimeException('timeout');
            return ['content' => '{"decision":"pass","issues":[]}'];
        });
        (new GenerateScriptJob($project->id))->handle($ai, $this->createMock(MediaTranscriptionService::class));
        $this->assertSame(1, $drafts);
        $this->assertSame(2, $reviews);
        $this->assertSame('Grind coffee beans.', $project->fresh()->script_text);
        Bus::assertDispatchedTimes(BreakdownScenesJob::class, 1);
    }

}
