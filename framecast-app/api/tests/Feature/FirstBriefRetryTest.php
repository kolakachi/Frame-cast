<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Jobs\GenerateScriptJob;
use App\Models\{Project, User, Workspace};
use App\Services\WorkspaceUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/** #11 (2026-10-12): a first brief that failed at the script has a way forward from the progress screen. */
class FirstBriefRetryTest extends TestCase
{
    use BuildsDeveloperSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'brief_retry_test', 'database.connections.brief_retry_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array', 'services.posthog.key' => '']);
        DB::purge('brief_retry_test');
        Bus::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex')->andReturn(true);
        $this->buildDeveloperSchema();
        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('hasExceededApiBudget')->willReturn(false);
        $this->instance(WorkspaceUsageService::class, $usage);
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => 'creator', 'status' => 'active']);
        $this->user = User::query()->create(['email' => 'o@acme.test', 'name' => 'Owner', 'role' => 'owner', 'status' => 'active']);
        $this->user->forceFill(['workspace_id' => $ws->id])->save();
    }

    private function retry(Project $p, array $body)
    {
        $r = Request::create('/x', 'POST', $body);
        $r->setUserResolver(fn () => $this->user);

        return app(ProjectController::class)->retryGeneration($r, $p->id);
    }

    public function test_an_unreadable_link_is_made_from_the_text_they_paste(): void
    {
        $p = Project::query()->create(['workspace_id' => $this->user->workspace_id, 'source_type' => 'url', 'source_content_raw' => 'https://www.instagram.com/reel/x', 'status' => 'failed']);
        $this->assertSame(422, $this->retry($p, ['source_type' => 'prompt'])->getStatusCode(), 'switching needs the text');
        $this->assertSame(200, $this->retry($p, ['source_type' => 'prompt', 'source_content_raw' => 'Northside Roasters: fresh coffee beans roasted weekly, delivered every two weeks.'])->getStatusCode());
        $p->refresh();
        $this->assertSame(['prompt', 'generating'], [$p->source_type, $p->status]);
        $this->assertStringStartsWith('Northside Roasters', $p->source_content_raw);
        Bus::assertDispatched(GenerateScriptJob::class);
    }

    public function test_a_brief_the_writer_could_not_match_can_be_used_word_for_word(): void
    {
        $p = Project::query()->create(['workspace_id' => $this->user->workspace_id, 'source_type' => 'prompt', 'source_content_raw' => 'Sabe quantas vezes por dia perguntavam no WhatsApp da minha Lotérica?', 'status' => 'failed']);
        $this->assertSame(200, $this->retry($p, ['source_type' => 'script'])->getStatusCode());
        $this->assertSame(['script', 'generating'], [$p->fresh()->source_type, $p->fresh()->status]);
        $this->expectException(\Illuminate\Validation\ValidationException::class); // only text sources
        $this->retry(Project::query()->create(['workspace_id' => $this->user->workspace_id, 'source_type' => 'prompt', 'status' => 'failed']), ['source_type' => 'url']);
    }
}
