<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Workspace\TeamController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agency\Allowance;
use App\Services\Agency\WorkspaceAccess;
use App\Services\CreditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Collaborators (phase 3, 2026-10-09): an agency's team members, spending the agency's credits in the agency and the
 * clients they were given, up to the monthly allowance the agency set. Fresh in-memory DB, never the app's.
 */
class CollaboratorTest extends TestCase
{
    private Workspace $agency;
    private Workspace $acme;
    private Workspace $other;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'collab_test', 'database.connections.collab_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'workspaces.client_tiers' => ['agency']]);
        DB::purge('collab_test');
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_workspace_id')->nullable();
            foreach (['client_label', 'name', 'plan_tier', 'plan_source', 'plan_status', 'status'] as $c) $t->string($c)->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->integer('credits_monthly')->default(0);
            $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0);
            $t->unsignedInteger('monthly_credit_cap')->nullable();
            $t->string('funding_mode')->default('pooled');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            foreach (['email', 'role', 'name', 'timezone', 'status'] as $c) $t->string($c)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            foreach (['spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $c) $t->unsignedBigInteger($c)->nullable();
            $t->string('operation');
            $t->integer('credits');
            $t->integer('balance_after')->nullable();
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->unsignedBigInteger('api_key_id')->nullable();
            $t->timestamps();
        });
        Schema::create('magic_link_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('email')->nullable();
            $t->string('token_hash');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('auth_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_18_110000_add_agency_workflows.php'))->up();
        (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
        (require database_path('migrations/2026_10_09_140000_add_collaborators.php'))->up();

        $this->agency = Workspace::query()->create(['name' => 'Northstar', 'status' => 'active']);
        $this->agency->forceFill(['plan_tier' => 'agency', 'credits_topup' => 10000])->save();
        $this->owner = User::query()->create(['email' => 'owner@agency.test', 'role' => 'owner', 'status' => 'active', 'workspace_id' => $this->agency->id]);
        $this->agency->forceFill(['owner_user_id' => $this->owner->id])->save();
        $this->acme = Workspace::query()->create(['name' => 'Acme', 'status' => 'active']);
        $this->acme->forceFill(['parent_workspace_id' => $this->agency->id])->save();
        $this->other = Workspace::query()->create(['name' => 'Other', 'status' => 'active']);
        $this->other->forceFill(['parent_workspace_id' => $this->agency->id])->save();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Context::flush();
        parent::tearDown();
    }

    private function as(User $u, array $body = [], string $method = 'POST'): Request
    {
        $r = Request::create('/api/v1/team', $method, $body);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    private function invite(?int $allowance = 100): User
    {
        $res = app(TeamController::class)->store($this->as($this->owner, ['email' => 'ada@team.test', 'name' => 'Ada', 'allowance' => $allowance, 'client_ids' => [$this->acme->id]]));
        $this->assertSame(201, $res->status());

        return User::query()->where('email', 'ada@team.test')->firstOrFail();
    }

    public function test_an_agency_invites_a_collaborator_into_the_clients_it_chooses(): void
    {
        $ada = $this->invite();
        $this->assertSame(User::ROLE_COLLABORATOR, $ada->role);
        $this->assertSame((int) $this->agency->id, (int) $ada->workspace_id);
        $this->assertSame(100, (int) $ada->monthly_credit_allowance);
        Mail::assertSent(\App\Mail\Workspace\CollaboratorInvite::class);
        $access = app(WorkspaceAccess::class);
        $this->assertSame('collaborator', $access->role($ada, $this->agency));
        $this->assertSame('collaborator', $access->role($ada, $this->acme));
        $this->assertNull($access->role($ada, $this->other), 'only the clients given');
        // Taking a client away, and pausing them, end their access at once.
        app(TeamController::class)->update($this->as($this->owner, ['client_ids' => []], 'PATCH'), $ada->id);
        $this->assertNull($access->role($ada, $this->acme));
        app(TeamController::class)->update($this->as($this->owner, ['status' => 'paused'], 'PATCH'), $ada->id);
        $this->assertNull($access->role($ada->fresh(), $this->agency));
    }

    public function test_a_collaborator_cannot_run_the_team(): void
    {
        $ada = $this->invite();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TeamController::class)->index($this->as($ada, [], 'GET'));
    }

    public function test_a_collaborator_spends_up_to_their_allowance_and_each_spend_names_them(): void
    {
        $ada = $this->invite(100);
        $credits = app(CreditService::class);
        Context::addHidden(Allowance::CONTEXT, $ada->id);
        $this->assertTrue($credits->deduct((int) $this->acme->id, 60, 'test'));
        $this->assertSame((int) $ada->id, (int) DB::table('credit_ledger')->latest('id')->value('user_id'));
        $this->assertFalse($credits->deduct((int) $this->acme->id, 50, 'test'), '60 + 50 is past the 100 allowance');
        $this->assertSame(9940, $credits->balance((int) $this->agency->id), 'the refused charge took nothing');
        $this->assertTrue($credits->deduct((int) $this->agency->id, 40, 'test'), 'exactly up to it, in the agency too');
        $this->assertSame(100, Allowance::spentThisMonth($ada->id));
        $this->assertStringContainsString('100-credit allowance', Allowance::message($ada->id));
        // The owner is never bound by anyone's allowance.
        Context::addHidden(Allowance::CONTEXT, $this->owner->id);
        $this->assertTrue($credits->deduct((int) $this->acme->id, 500, 'test'));
        // No allowance set means no limit.
        app(TeamController::class)->update($this->as($this->owner, ['allowance' => null], 'PATCH'), $ada->id);
        Context::addHidden(Allowance::CONTEXT, $ada->id);
        $this->assertTrue($credits->deduct((int) $this->acme->id, 500, 'test'));
    }

    public function test_held_credits_for_unfinished_work_count_against_the_allowance(): void
    {
        $ada = $this->invite(100);
        DB::table('api_operations')->insert(['id' => 'op_test', 'quote_id' => 'q', 'workspace_id' => $this->acme->id, 'pool_workspace_id' => $this->agency->id,
            'capacity_slots' => 1, 'authorized_credits' => 80, 'reserved_credits' => 80, 'status' => 'running', 'user_id' => $ada->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(80, Allowance::reserved($ada->id));
        $this->assertTrue(Allowance::wouldExceed($ada->id, 30, true));
        $this->assertFalse(Allowance::wouldExceed($ada->id, 20, true));
    }

    public function test_billing_keys_clients_and_the_team_are_refused_to_a_collaborator(): void
    {
        $deny = new \ReflectionMethod(\App\Http\Middleware\AuthenticateWithJwt::class, 'denyCollaborator');
        $mw = app(\App\Http\Middleware\AuthenticateWithJwt::class);
        foreach (['/api/v1/billing/status', '/api/v1/api-keys', '/api/v1/workspaces/clients', '/api/v1/team'] as $path) {
            $this->assertSame(403, $deny->invoke($mw, Request::create($path, 'GET'))?->status(), $path);
        }
        foreach (['/api/v1/create/conversations', '/api/v1/projects', '/api/v1/workspace-access/switch/5'] as $path) {
            $this->assertNull($deny->invoke($mw, Request::create($path, 'POST')), $path);
        }
    }
}
