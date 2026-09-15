<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Workspace\ClientWorkspaceController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class ClientWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'cw_test', 'database.connections.cw_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'workspaces.client_tiers' => ['agency', 'lifetime_agency', 'appsumo_agency'], 'workspaces.max_clients' => 3]);
        DB::purge('cw_test');

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('parent_workspace_id')->nullable();
            $t->string('client_label')->nullable(); $t->string('name')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('plan_tier')->nullable(); $t->string('plan_source')->nullable();
            $t->string('plan_status')->nullable(); $t->string('status')->nullable();
            $t->integer('credits_monthly')->default(0); $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email')->nullable(); $t->string('role')->nullable(); $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable(); $t->timestamps();
        });
        // The ledger write is rescued, so a missing column fails silently —
        // mirror the real columns or these assertions test nothing.
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id')->nullable(); $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('scene_id')->nullable();
            $t->string('operation'); $t->integer('credits'); $t->integer('balance_after')->nullable();
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->json('metadata')->nullable(); $t->timestamps();
        });
    }

    private function agency(string $tier = 'agency', int $credits = 20000): Workspace
    {
        $w = Workspace::query()->create(['name' => 'Northstar', 'status' => 'active']);
        $w->forceFill(['plan_tier' => $tier, 'plan_source' => 'lifetime',
            'credits_topup' => $credits])->save();

        return $w->fresh();
    }

    private function userFor(Workspace $w): User
    {
        $u = User::query()->create(['email' => 'a@b.test', 'role' => 'owner']);
        $u->forceFill(['workspace_id' => $w->getKey()])->save();

        return $u->fresh();
    }

    private function req(User $u, array $body = [], string $method = 'POST'): Request
    {
        $r = Request::create('/clients', $method, $body);
        $r->setUserResolver(fn () => $u);

        return $r;
    }

    private function ctrl(): ClientWorkspaceController
    {
        return app(ClientWorkspaceController::class);
    }

    public function test_an_agency_can_create_a_client_workspace(): void
    {
        $a = $this->agency();
        $res = $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'Acme Skincare']));

        $this->assertSame(201, $res->status());
        $client = Workspace::query()->where('name', 'Acme Skincare')->firstOrFail();
        $this->assertSame((int) $a->getKey(), (int) $client->parent_workspace_id);
    }

    public function test_a_client_workspace_spends_the_agency_pool(): void
    {
        // The whole point of one pool: the child has no balance of its own.
        $a = $this->agency(credits: 20000);
        $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $credits = app(CreditService::class);
        $this->assertSame(20000, $credits->balance((int) $client->getKey()));

        $this->assertTrue($credits->deduct((int) $client->getKey(), 5000, 'test'));
        $this->assertSame(15000, $credits->balance((int) $a->getKey()), 'the agency paid for it');
        $this->assertSame(0, (int) $client->fresh()->credits_topup, 'the client never holds credits');
    }

    public function test_a_grant_to_a_client_lands_on_the_agency(): void
    {
        $a = $this->agency(credits: 0);
        $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        app(CreditService::class)->grant((int) $client->getKey(), 1000, 'test');

        $this->assertSame(1000, (int) $a->fresh()->credits_topup);
        $this->assertSame(0, (int) $client->fresh()->credits_topup);
    }

    public function test_the_ledger_names_the_client_that_spent(): void
    {
        // "Which of my clients burned the pool this month" is the first thing a
        // shared pool invites, and it is unanswerable if only the agency is
        // recorded.
        $a = $this->agency(credits: 20000);
        $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        app(CreditService::class)->deduct((int) $client->getKey(), 500, 'render');

        $row = DB::table('credit_ledger')->where('operation', 'render')->firstOrFail();
        $this->assertSame((int) $a->getKey(), (int) $row->workspace_id, 'the money moved on the agency');
        $this->assertSame((int) $client->getKey(),
            (int) (json_decode((string) $row->metadata, true)['spent_by_workspace_id'] ?? 0),
            'and the client is named');
    }

    public function test_an_ordinary_workspace_ledger_gains_no_extra_field(): void
    {
        $a = $this->agency(credits: 5000);
        app(CreditService::class)->deduct((int) $a->getKey(), 100, 'render');

        $row = DB::table('credit_ledger')->where('operation', 'render')->firstOrFail();
        $meta = json_decode((string) $row->metadata, true);
        $this->assertArrayNotHasKey('spent_by_workspace_id', (array) $meta);
    }

    public function test_tiers_below_agency_cannot_create_clients(): void
    {
        foreach (['free', 'starter', 'creator', 'pro', 'lifetime_creator', 'appsumo_creator'] as $tier) {
            $a = $this->agency($tier);
            $res = $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'Acme']));
            $this->assertSame(403, $res->status(), "{$tier} must not own clients");
        }
    }

    public function test_both_agency_tiers_can(): void
    {
        foreach (['agency', 'lifetime_agency', 'appsumo_agency'] as $tier) {
            $a = $this->agency($tier);
            $this->assertSame(201, $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'C-'.$tier]))->status());
        }
    }

    public function test_a_client_cannot_itself_own_clients(): void
    {
        // Otherwise an agency switched into a client could nest indefinitely.
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $this->assertFalse($client->canOwnClients());

        // Acting inside the client, a create still attaches to the agency.
        $u->forceFill(['workspace_id' => $client->getKey()])->save();
        $this->ctrl()->store($this->req($u->fresh(), ['name' => 'Nested']));

        $this->assertSame((int) $a->getKey(),
            (int) Workspace::query()->where('name', 'Nested')->firstOrFail()->parent_workspace_id);
    }

    public function test_the_client_limit_is_enforced(): void
    {
        $a = $this->agency();
        $u = $this->userFor($a);
        foreach (['One', 'Two', 'Three'] as $n) {
            $this->assertSame(201, $this->ctrl()->store($this->req($u, ['name' => $n]))->status());
        }

        $this->assertSame(422, $this->ctrl()->store($this->req($u, ['name' => 'Four']))->status());
    }

    public function test_an_agency_cannot_touch_another_agencys_client(): void
    {
        $mine = $this->agency();
        $theirs = $this->agency();
        $this->ctrl()->store($this->req($this->userFor($theirs), ['name' => 'Not Mine']));
        $stranger = Workspace::query()->where('name', 'Not Mine')->firstOrFail();

        $me = $this->userFor($mine);
        $this->assertSame(404, $this->ctrl()->update($this->req($me, ['name' => 'Hijacked'], 'PATCH'), (int) $stranger->getKey())->status());
        $this->assertSame(404, $this->ctrl()->destroy($this->req($me), (int) $stranger->getKey())->status());
        $this->assertSame(404, $this->ctrl()->switch($this->req($me), (int) $stranger->getKey())->status());
        $this->assertSame('Not Mine', $stranger->fresh()->name);
    }

    public function test_listing_shows_the_pool_once_and_the_clients_under_it(): void
    {
        $a = $this->agency(credits: 18000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $this->ctrl()->store($this->req($u, ['name' => 'Beta']));

        $d = $this->ctrl()->index($this->req($u, [], 'GET'))->getData(true)['data'];

        $this->assertTrue($d['can_own_clients']);
        $this->assertSame(18000, $d['shared_credits']);
        $this->assertCount(2, $d['clients']);
        $this->assertTrue($d['agency']['is_agency']);
    }

    public function test_removing_a_client_archives_rather_than_deletes(): void
    {
        // The work inside belongs to the agency's client; a mis-click must not
        // be the end of it.
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $this->ctrl()->destroy($this->req($u), (int) $client->getKey());

        $this->assertSame('archived', $client->fresh()->status);
        $this->assertNotNull(Workspace::find($client->getKey()), 'still there');
    }
}
