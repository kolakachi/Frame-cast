<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWithJwt;
use App\Jobs\GenerateScriptJob;
use App\Models\ApiKey;
use App\Models\ApiQuote;
use App\Models\AuthSession;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\JwtService;
use App\Services\WorkspaceUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis, Schema};
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The developer API, end to end over HTTP: an API key reaches exactly this
 * namespace and nothing else; a video is quoted, created against the quote,
 * polled and fetched; and every way a quote can be misused is refused
 * without spending anything.
 *
 * Deliberately uses a fresh in-memory DB, never the configured application database.
 */
class DeveloperApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'dev_api_test', 'database.connections.dev_api_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('dev_api_test');
        config(['cache.default' => 'array', 'app.frontend_url' => 'https://app.test', 'services.posthog.key' => '']);
        Bus::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'plan_tier', 'plan_status', 'plan_source', 'funding_mode'] as $c) $t->string($c)->nullable();
            $t->string('status')->default('active');
            $t->unsignedBigInteger('parent_workspace_id')->nullable();
            $t->integer('credits_monthly')->default(0);
            $t->integer('credits_topup')->default(0);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            foreach (['email', 'name', 'role', 'status'] as $c) $t->string($c)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('auth_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('refresh_token_hash')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('name'); $t->string('prefix'); $t->string('token_hash');
            $t->timestamp('last_used_at')->nullable(); $t->timestamp('revoked_at')->nullable(); $t->timestamps();
        });
        Schema::create('api_quotes', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('api_key_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->text('payload_json');
            $t->unsignedInteger('credits_min'); $t->unsignedInteger('credits_max');
            $t->timestamp('expires_at'); $t->timestamp('consumed_at')->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            foreach ((new Project)->getFillable() as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        Schema::create('export_jobs', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'project_id', 'variant_id', 'aspect_ratio', 'language', 'file_name', 'watermark_enabled',
                'status', 'progress_percent', 'priority', 'failure_reason', 'output_asset_id', 'queued_at', 'started_at', 'completed_at'] as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'asset_type', 'storage_url', 'duration_seconds', 'mime_type', 'file_name'] as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $c) $t->unsignedBigInteger($c)->nullable();
            $t->string('operation')->nullable(); $t->integer('credits')->default(0); $t->integer('balance_after')->nullable();
            $t->decimal('upstream_cost_usd', 10, 4)->nullable(); $t->text('metadata')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_18_110000_add_agency_workflows.php'))->up();

        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('hasExceededApiBudget')->willReturn(false);
        $usage->method('exportsRemaining')->willReturn(10);
        $this->instance(WorkspaceUsageService::class, $usage);
    }

    /** @return array{0: Workspace, 1: User, 2: string} workspace, owner, plaintext key */
    private function tenant(string $tier = 'creator', int $credits = 500): array
    {
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => $tier, 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => $credits]);
        $u = User::query()->create(['email' => uniqid().'@acme.test', 'name' => 'Owner', 'role' => 'owner', 'status' => 'active']);
        $u->forceFill(['workspace_id' => $ws->getKey()])->save();
        DB::table('workspace_memberships')->insert(['workspace_id' => $ws->id, 'user_id' => $u->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        [, $plain] = ApiKey::issue((int) $ws->getKey(), (int) $u->getKey(), 'Test');

        return [$ws->fresh(), $u->fresh(), $plain];
    }

    private function sessionToken(User $u, Workspace $ws): string
    {
        $session = AuthSession::query()->create(['user_id' => $u->getKey(), 'workspace_id' => $ws->getKey()]);

        return app(JwtService::class)->issue($u, $ws, $session);
    }

    private function quote(string $key, array $overrides = []): TestResponse
    {
        return $this->withToken($key)->postJson('/api/developer/v1/quotes', $overrides + [
            'source_type' => 'prompt',
            'content' => 'Three reasons a standing desk pays for itself within a month, told as a story.',
            'visual_mode' => 'stock',
            'duration_seconds' => 30,
        ]);
    }

    private function create(string $key, string $quoteId, string $idem = 'req-1'): TestResponse
    {
        return $this->withToken($key)->postJson('/api/developer/v1/videos', ['quote_id' => $quoteId, 'idempotency_key' => $idem]);
    }

    public function test_a_key_reaches_the_developer_namespace_and_nothing_else(): void
    {
        [, , $key] = $this->tenant();

        foreach (['/api/v1/projects', '/api/v1/me', '/api/v1/billing/status', '/api/v1/api-keys', '/api/v1/social/accounts'] as $path) {
            $this->withToken($key)->getJson($path)->assertStatus(403)->assertJsonPath('error.code', 'api_key_forbidden_path');
        }
        foreach (['/api/v1/cruise/apply', '/api/v1/api-keys', '/api/v1/projects/1/share'] as $path) {
            $this->withToken($key)->postJson($path)->assertStatus(403)->assertJsonPath('error.code', 'api_key_forbidden_path');
        }
        $this->withToken($key)->deleteJson('/api/v1/me')->assertStatus(403);

        $this->withToken($key)->getJson('/api/developer/v1/capabilities')
            ->assertOk()
            ->assertJsonPath('data.plan', 'creator')
            ->assertJsonPath('data.credits.balance', 500)
            ->assertJsonPath('data.video.source_types', ['prompt', 'script'])
            ->assertJsonMissingPath('data.billing');
    }

    public function test_a_browser_session_can_call_the_developer_namespace(): void
    {
        [$ws, $u] = $this->tenant();
        $this->withToken($this->sessionToken($u, $ws))->getJson('/api/developer/v1/capabilities')->assertOk();
    }

    public function test_a_plan_without_api_access_is_refused_at_the_door(): void
    {
        [, , $key] = $this->tenant('free');
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertStatus(403)->assertJsonPath('error.code', 'api_access_not_on_plan');
    }

    public function test_quote_create_poll_and_fetch(): void
    {
        [$ws, , $key] = $this->tenant();

        $quote = $this->quote($key)->assertStatus(201)
            ->assertJsonPath('data.can_afford', true)
            ->assertJsonPath('data.request.visual_mode', 'stock');
        $quoteId = $quote->json('data.quote_id');
        $this->assertStringStartsWith('q_', $quoteId);
        $this->assertGreaterThan(0, $quote->json('data.credits.max'));
        $this->assertSame(0, Project::query()->count(), 'quoting must not create anything');

        $created = $this->create($key, $quoteId)->assertStatus(202)
            ->assertJsonPath('data.video.status', 'generating')
            ->assertJsonPath('data.video.quote_id', $quoteId)
            ->assertJsonPath('data.video.credits.authorized_max', $quote->json('data.credits.max'));
        $id = $created->json('data.video.id');
        $this->assertSame('https://app.test/projects/'.$id.'/editor', $created->json('data.video.project_url'));

        $project = Project::query()->findOrFail($id);
        $this->assertSame((int) $ws->getKey(), (int) $project->workspace_id);
        $this->assertSame('prompt', $project->source_type);
        $this->assertSame('stock', $project->visual_generation_mode);
        $this->assertSame(30, (int) $project->duration_target_seconds);
        $this->assertNotNull($project->api_key_id, 'the creating key is recorded on the project');
        Bus::assertDispatched(GenerateScriptJob::class);
        $this->assertNotNull(ApiQuote::query()->findOrFail($quoteId)->consumed_at);

        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}")->assertOk()
            ->assertJsonPath('data.video.status', 'generating')
            ->assertJsonPath('data.video.retry_after_seconds', 15)
            ->assertJsonPath('data.video.credits.spent', 0);
        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/result")->assertStatus(409)
            ->assertJsonPath('error.code', 'not_ready')->assertJsonPath('error.context.status', 'generating');

        // Generation finishes and the automatic export runs.
        Project::withoutEvents(fn () => $project->forceFill(['status' => 'ready_for_review'])->save());
        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}")->assertOk()->assertJsonPath('data.video.status', 'exporting');

        DB::table('credit_ledger')->insert([
            ['workspace_id' => $ws->id, 'project_id' => $id, 'operation' => 'tts', 'credits' => 9, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws->id, 'project_id' => $id, 'operation' => 'refund:tts', 'credits' => -3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $assetId = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://b2/x.mp4', 'duration_seconds' => 29.6, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('export_jobs')->insert(['workspace_id' => $ws->id, 'project_id' => $id, 'aspect_ratio' => '9:16', 'file_name' => 'video.mp4', 'status' => 'completed', 'output_asset_id' => $assetId, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}")->assertOk()
            ->assertJsonPath('data.video.status', 'completed')
            ->assertJsonPath('data.video.retry_after_seconds', null)
            ->assertJsonPath('data.video.credits.spent', 6);

        $result = $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/result")->assertOk()
            ->assertJsonPath('data.video.status', 'completed')
            ->assertJsonPath('data.video.duration_seconds', 29.6)
            ->assertJsonPath('data.video.credits.spent', 6);
        $url = $result->json('data.video.download_url');
        $this->assertStringContainsString("/media/assets/{$assetId}?download=1", $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertNull($project->fresh()->share_token, 'fetching the result never makes the project public');
    }

    public function test_replaying_the_same_idempotency_key_returns_the_same_video(): void
    {
        [, , $key] = $this->tenant();
        $quoteId = $this->quote($key)->json('data.quote_id');
        $first = $this->create($key, $quoteId, 'retry-me')->assertStatus(202)->json('data.video.id');
        $again = $this->create($key, $quoteId, 'retry-me')->assertStatus(200)->json('data.video.id');
        $this->assertSame($first, $again);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_consumed_quote_cannot_be_used_for_a_second_video(): void
    {
        [, , $key] = $this->tenant();
        $quoteId = $this->quote($key)->json('data.quote_id');
        $this->create($key, $quoteId, 'one')->assertStatus(202);
        $this->create($key, $quoteId, 'two')->assertStatus(409)->assertJsonPath('error.code', 'quote_consumed');
        $this->assertSame(1, Project::query()->count());
    }

    public function test_an_idempotency_key_cannot_be_reused_for_a_different_quote(): void
    {
        [, , $key] = $this->tenant();
        $this->create($key, $this->quote($key)->json('data.quote_id'), 'same')->assertStatus(202);
        $this->create($key, $this->quote($key)->json('data.quote_id'), 'same')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(1, Project::query()->count());
    }

    public function test_an_expired_quote_is_refused(): void
    {
        [, , $key] = $this->tenant();
        $quoteId = $this->quote($key)->json('data.quote_id');
        ApiQuote::query()->whereKey($quoteId)->update(['expires_at' => now()->subMinute()]);
        $this->create($key, $quoteId)->assertStatus(410)->assertJsonPath('error.code', 'quote_expired');
        $this->assertNull(ApiQuote::query()->findOrFail($quoteId)->consumed_at);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_a_quote_belongs_to_one_workspace(): void
    {
        [, , $keyA] = $this->tenant();
        [, , $keyB] = $this->tenant();
        $quoteId = $this->quote($keyA)->json('data.quote_id');
        $this->create($keyB, $quoteId)->assertStatus(404)->assertJsonPath('error.code', 'quote_not_found');
        $this->assertSame(0, Project::query()->count());
    }

    public function test_create_needs_the_balance_to_cover_the_quoted_maximum(): void
    {
        [$ws, , $key] = $this->tenant();
        $quote = $this->quote($key);
        $max = (int) $quote->json('data.credits.max');
        $ws->forceFill(['credits_monthly' => $max - 1])->save();

        $this->create($key, $quote->json('data.quote_id'))->assertStatus(402)
            ->assertJsonPath('error.code', 'insufficient_credits')
            ->assertJsonPath('error.context.shortage', 1);
        $this->assertNull(ApiQuote::query()->findOrFail($quote->json('data.quote_id'))->consumed_at, 'a refused create gives the quote back');
        $this->assertSame(0, Project::query()->count());
        Bus::assertNotDispatched(GenerateScriptJob::class);
    }

    public function test_a_video_belongs_to_one_workspace(): void
    {
        [, , $keyA] = $this->tenant();
        [, , $keyB] = $this->tenant();
        $id = $this->create($keyA, $this->quote($keyA)->json('data.quote_id'))->json('data.video.id');
        $this->withToken($keyB)->getJson("/api/developer/v1/videos/{$id}")->assertStatus(404);
        $this->withToken($keyB)->getJson("/api/developer/v1/videos/{$id}/result")->assertStatus(404);
    }

    public function test_the_plan_duration_cap_applies_when_quoting(): void
    {
        [, , $key] = $this->tenant('creator'); // caps at 300s
        $this->quote($key, ['duration_seconds' => 400])->assertStatus(422)
            ->assertJsonPath('error.code', 'plan_duration_exceeded')
            ->assertJsonPath('error.context.max_duration_seconds', 300);
        $this->assertSame(0, ApiQuote::query()->count());
    }

    public function test_quote_validation_uses_the_api_envelope(): void
    {
        [, , $key] = $this->tenant();
        $this->quote($key, ['visual_mode' => 'ai_video'])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['context' => ['errors' => ['animate_tier']]]]);
        $this->quote($key, ['visual_mode' => 'stock', 'animate_tier' => 'quick'])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
        $this->quote($key, ['content' => 'short'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_source_content');
        $this->create($key, 'q_nope')->assertStatus(404);
        $this->withToken($key)->postJson('/api/developer/v1/videos', ['quote_id' => 'q_x'])->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_an_ai_video_quote_prices_the_animation(): void
    {
        [, , $key] = $this->tenant();
        $stock = (int) $this->quote($key)->json('data.credits.max');
        $ai = (int) $this->quote($key, ['visual_mode' => 'ai_video', 'animate_tier' => 'quick'])->assertStatus(201)->json('data.credits.max');
        $this->assertGreaterThan($stock, $ai);
    }
}
