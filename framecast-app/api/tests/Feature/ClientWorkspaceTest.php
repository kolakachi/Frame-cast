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
            $t->id();
            $t->unsignedBigInteger('parent_workspace_id')->nullable();
            $t->string('client_label')->nullable();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('plan_tier')->nullable();
            $t->string('plan_source')->nullable();
            $t->string('plan_status')->nullable();
            $t->string('status')->nullable();
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
            $t->string('email')->nullable();
            $t->string('role')->nullable();
            $t->string('name')->nullable();
            $t->string('timezone')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('primary_language')->nullable();
            $t->timestamps();
        });
        // The ledger write is rescued, so a missing column fails silently —
        // mirror the real columns or these assertions test nothing.
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('spent_by_workspace_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('scene_id')->nullable();
            $t->string('operation');
            $t->integer('credits');
            $t->integer('balance_after')->nullable();
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('scenes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->float('duration_seconds')->default(0);
            $t->timestamps();
        });
        Schema::create('export_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('channels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('voice_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->boolean('is_cloned')->default(false);
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->timestamps();
        });
        Schema::create('brand_kits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('name')->nullable();
            $t->string('primary_color')->nullable();
            $t->string('secondary_color')->nullable();
            $t->string('accent_color')->nullable();
            $t->string('font_primary')->nullable();
            $t->string('font_secondary')->nullable();
            $t->unsignedBigInteger('logo_asset_id')->nullable();
            $t->string('default_caption_style')->nullable();
            $t->unsignedBigInteger('default_voice_profile_id')->nullable();
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
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_18_110000_add_agency_workflows.php'))->up();
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
        $u->setRawAttributes(array_merge($u->getAttributes(), ['workspace_id' => $client->id]), true);
        $this->ctrl()->store($this->req($u, ['name' => 'Nested']));

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

    // ── Per-client spend ────────────────────────────────────────────────

    public function test_usage_says_which_client_spent_the_pool(): void
    {
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $this->ctrl()->store($this->req($u, ['name' => 'Borealis']));
        $acme = Workspace::query()->where('name', 'Acme')->firstOrFail();
        $bor  = Workspace::query()->where('name', 'Borealis')->firstOrFail();

        $credits = app(CreditService::class);
        $credits->deduct((int) $acme->getKey(), 300, 'render');
        $credits->deduct((int) $bor->getKey(), 100, 'render');
        $credits->deduct((int) $a->getKey(), 600, 'render');

        $res = $this->ctrl()->usage($this->req($u, [], 'GET'));
        $body = $res->getData(true)['data'];

        $by = collect($body['usage'])->keyBy('workspace_id');
        $this->assertSame(1000, $body['total']);
        $this->assertSame(300, $by[(int) $acme->getKey()]['credits']);
        $this->assertSame(100, $by[(int) $bor->getKey()]['credits']);
        $this->assertSame(600, $by[(int) $a->getKey()]['credits'], 'the agency\'s own spend is its own row');
        $this->assertEquals(30, $by[(int) $acme->getKey()]['share_percent']);
    }

    public function test_usage_leaves_grants_out_of_a_client_s_spend(): void
    {
        // A top-up is the agency buying credits; folding it in would net a
        // client's usage against money that had nothing to do with them.
        $a = $this->agency(credits: 5000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $acme = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $credits = app(CreditService::class);
        $credits->deduct((int) $acme->getKey(), 250, 'render');
        $credits->grant((int) $a->getKey(), 10000, 'top_up');

        $body = $this->ctrl()->usage($this->req($u, [], 'GET'))->getData(true)['data'];
        $this->assertSame(250, $body['total']);
    }

    public function test_usage_counts_nothing_before_anyone_spends(): void
    {
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));

        $body = $this->ctrl()->usage($this->req($u, [], 'GET'))->getData(true)['data'];
        $this->assertSame(0, $body['total']);
        // Every workspace still appears, at zero — a missing row reads as a bug.
        $this->assertCount(2, $body['usage']);
        $this->assertEquals(0, $body['usage'][0]['share_percent']);
    }

    // ── Seeding ─────────────────────────────────────────────────────────

    public function test_a_new_client_inherits_the_agency_brand(): void
    {
        $a = $this->agency();
        \App\Models\BrandKit::query()->create([
            'workspace_id' => $a->getKey(), 'name' => 'Northstar',
            'primary_color' => '#112233', 'font_primary' => 'Satoshi',
            'logo_asset_id' => 99, 'default_voice_profile_id' => 77,
        ]);

        $this->ctrl()->store($this->req($this->userFor($a), ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $kit = \App\Models\BrandKit::query()->where('workspace_id', $client->getKey())->firstOrFail();
        $this->assertSame('#112233', $kit->primary_color);
        $this->assertSame('Satoshi', $kit->font_primary);
        $this->assertSame('Acme', $kit->name);
        // Assets and voices are workspace-scoped; copying the ids would leave
        // the client pointing at rows it may not read.
        $this->assertNull($kit->logo_asset_id);
        $this->assertNull($kit->default_voice_profile_id);
    }

    // ── Client viewers ──────────────────────────────────────────────────

    public function test_an_agency_can_invite_a_client_to_watch_one_workspace(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $res = $this->ctrl()->inviteViewer(
            $this->req($u, ['email' => 'ops@acme.test']),
            (int) $client->getKey(),
        );

        $this->assertSame(201, $res->status());
        $viewer = User::query()->where('email', 'ops@acme.test')->firstOrFail();
        $this->assertSame(User::ROLE_CLIENT_VIEWER, $viewer->role);
        $this->assertSame((int) $client->getKey(), (int) $viewer->workspace_id);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\Workspace\ClientViewerInvite::class);
    }

    public function test_inviting_an_address_that_already_has_an_account_is_refused(): void
    {
        // Re-pointing it would take that person's own workspace away from them.
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $res = $this->ctrl()->inviteViewer(
            $this->req($u, ['email' => $u->email]),
            (int) $client->getKey(),
        );

        $this->assertSame(422, $res->status());
        $this->assertSame('owner_access', $res->getData(true)['error']['code']);
    }

    public function test_an_agency_cannot_invite_into_someone_else_s_client(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $mine = $this->agency();
        $theirs = $this->agency();
        $other = Workspace::query()->create(['name' => 'Not mine', 'status' => 'active']);
        $other->forceFill(['parent_workspace_id' => $theirs->getKey()])->save();

        $res = $this->ctrl()->inviteViewer(
            $this->req($this->userFor($mine), ['email' => 'x@y.test']),
            (int) $other->getKey(),
        );

        $this->assertSame(404, $res->status());
        $this->assertNull(User::query()->where('email', 'x@y.test')->first());
    }

    public function test_removing_a_viewer_revokes_only_this_membership(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();
        $this->ctrl()->inviteViewer($this->req($u, ['email' => 'ops@acme.test']), (int) $client->getKey());
        $viewer = User::query()->where('email', 'ops@acme.test')->firstOrFail();
        DB::table('auth_sessions')->insert(['user_id' => $viewer->getKey()]);

        $res = $this->ctrl()->removeViewer($this->req($u, [], 'DELETE'), (int) $client->getKey(), (int) $viewer->getKey());

        $this->assertSame(200, $res->status());
        $this->assertNotNull(User::query()->where('email', 'ops@acme.test')->first());
        $this->assertNotNull(DB::table('workspace_memberships')->where('workspace_id', $client->id)->where('user_id', $viewer->id)->value('revoked_at'));
        $this->assertSame(1, DB::table('auth_sessions')->where('user_id', $viewer->getKey())->count());
        $this->assertNotNull(Workspace::query()->find($client->getKey()), 'the workspace survives');
    }


    public function test_the_shipped_config_lets_enterprise_own_clients(): void
    {
        // Reads the file rather than the value injected in setUp: the tier list
        // that matters is the one that ships, and the last bug here was a
        // config key that every reader agreed on and nobody actually loaded.
        $shipped = require __DIR__.'/../../config/workspaces.php';

        $this->assertContains('enterprise', $shipped['client_tiers']);
        foreach (['agency', 'lifetime_agency', 'appsumo_agency'] as $tier) {
            $this->assertContains($tier, $shipped['client_tiers'], "{$tier} lost client access");
        }

        config(['workspaces.client_tiers' => $shipped['client_tiers']]);
        $this->assertTrue($this->agency('enterprise')->canOwnClients());
    }


    // ── Spend caps ──────────────────────────────────────────────────────

    public function test_a_client_is_stopped_at_the_cap_its_agency_set(): void
    {
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();
        $client->forceFill(['monthly_credit_cap' => 1000])->save();

        $credits = app(CreditService::class);
        $this->assertTrue($credits->deduct((int) $client->getKey(), 600, 'render'));
        $this->assertTrue($credits->deduct((int) $client->getKey(), 400, 'render'));
        // 1,000 spent against a 1,000 ceiling: the next one cannot land.
        $this->assertFalse($credits->deduct((int) $client->getKey(), 1, 'render'));

        // And the agency kept the credits it was not allowed to spend.
        $this->assertSame(19000, $credits->balance((int) $a->getKey()));
    }

    public function test_a_cap_does_not_stop_the_agency_itself(): void
    {
        // A ceiling an agency could set on itself and then be blocked by is a
        // support ticket, not a feature.
        $a = $this->agency(credits: 5000);
        $a->forceFill(['monthly_credit_cap' => 10])->save();

        $this->assertTrue(app(CreditService::class)->deduct((int) $a->getKey(), 900, 'render'));
    }

    public function test_a_client_with_no_cap_spends_to_the_pool(): void
    {
        $a = $this->agency(credits: 3000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $credits = app(CreditService::class);
        $this->assertTrue($credits->deduct((int) $client->getKey(), 2900, 'render'));
        $this->assertFalse($credits->deduct((int) $client->getKey(), 200, 'render'), 'stopped by the balance, not a cap');
    }

    public function test_a_grant_does_not_buy_a_client_more_room_under_its_cap(): void
    {
        // Netting a top-up against usage would quietly raise the ceiling by
        // whatever the agency happened to buy that month.
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();
        $client->forceFill(['monthly_credit_cap' => 500])->save();

        $credits = app(CreditService::class);
        $this->assertTrue($credits->deduct((int) $client->getKey(), 500, 'render'));
        $credits->grant((int) $a->getKey(), 10000, 'top_up');

        $this->assertFalse($credits->deduct((int) $client->getKey(), 1, 'render'));
    }

    public function test_the_cap_and_this_months_spend_reach_the_clients_screen(): void
    {
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();
        $client->forceFill(['monthly_credit_cap' => 2000])->save();
        app(CreditService::class)->deduct((int) $client->getKey(), 340, 'render');

        $body = $this->ctrl()->index($this->req($u, [], 'GET'))->getData(true)['data'];
        $row = collect($body['clients'])->firstWhere('id', (int) $client->getKey());

        $this->assertSame(2000, $row['monthly_credit_cap']);
        $this->assertSame(340, $row['spent_this_month']);
        $this->assertSame(0, $body['agency']['spent_this_month'], 'the agency row is not a client of itself');
    }

    public function test_an_agency_can_set_and_clear_a_cap(): void
    {
        $a = $this->agency();
        $u = $this->userFor($a);
        $this->ctrl()->store($this->req($u, ['name' => 'Acme']));
        $client = Workspace::query()->where('name', 'Acme')->firstOrFail();

        $this->ctrl()->update($this->req($u, ['monthly_credit_cap' => 750], 'PATCH'), (int) $client->getKey());
        $this->assertSame(750, (int) $client->fresh()->monthly_credit_cap);

        $this->ctrl()->update($this->req($u, ['monthly_credit_cap' => null], 'PATCH'), (int) $client->getKey());
        $this->assertNull($client->fresh()->monthly_credit_cap);
    }


    // ── Funding a client ────────────────────────────────────────────────

    private function client(Workspace $a, User $u, string $name = 'Acme'): Workspace
    {
        $this->ctrl()->store($this->req($u, ['name' => $name]));

        return Workspace::query()->where('name', $name)->firstOrFail();
    }

    public function test_funding_a_client_moves_credits_off_the_agency(): void
    {
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);

        $res = $this->ctrl()->fund($this->req($u, ['amount' => 2000]), (int) $client->getKey());

        $this->assertSame(200, $res->status());
        $this->assertSame(18000, (int) $a->fresh()->credits_topup, 'the agency paid for it');
        $this->assertSame(2000, (int) $client->fresh()->credits_topup);
        $this->assertSame('funded', $client->fresh()->funding_mode);
    }

    public function test_a_funded_client_spends_its_own_and_stops_there(): void
    {
        // The whole point of funding rather than pooling: the allocation is
        // the limit, not a suggestion.
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 1000]), (int) $client->getKey());

        $credits = app(CreditService::class);
        $this->assertSame(1000, $credits->balance((int) $client->getKey()), 'its own balance, not the pool');
        $this->assertTrue($credits->deduct((int) $client->getKey(), 900, 'render'));
        $this->assertFalse($credits->deduct((int) $client->getKey(), 200, 'render'), 'no falling back to the agency');

        $this->assertSame(19000, (int) $a->fresh()->credits_topup, 'the agency was never touched');
    }

    public function test_credits_can_be_taken_back(): void
    {
        $a = $this->agency(credits: 5000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 2000]), (int) $client->getKey());

        $this->ctrl()->fund($this->req($u, ['amount' => -500]), (int) $client->getKey());

        $this->assertSame(1500, (int) $client->fresh()->credits_topup);
        $this->assertSame(3500, (int) $a->fresh()->credits_topup);
    }

    public function test_an_agency_cannot_fund_beyond_its_balance(): void
    {
        // Crediting the client without debiting the agency would mint credits.
        $a = $this->agency(credits: 100);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);

        $res = $this->ctrl()->fund($this->req($u, ['amount' => 5000]), (int) $client->getKey());

        $this->assertSame(422, $res->status());
        $this->assertSame('agency_short', $res->getData(true)['error']['code']);
        $this->assertSame(100, (int) $a->fresh()->credits_topup);
        $this->assertSame(0, (int) $client->fresh()->credits_topup);
    }

    public function test_unfunding_returns_what_is_left_and_repools(): void
    {
        $a = $this->agency(credits: 5000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 2000]), (int) $client->getKey());
        app(CreditService::class)->deduct((int) $client->getKey(), 500, 'render');

        $this->ctrl()->unfund($this->req($u, [], 'DELETE'), (int) $client->getKey());

        $this->assertSame('pooled', $client->fresh()->funding_mode);
        $this->assertSame(0, (int) $client->fresh()->credits_topup);
        $this->assertSame(4500, (int) $a->fresh()->credits_topup, 'the unspent 1,500 came home');
        // And it is back on the pool.
        $this->assertSame(4500, app(CreditService::class)->balance((int) $client->getKey()));
    }

    public function test_an_agency_cannot_fund_someone_else_s_client(): void
    {
        $mine = $this->agency(credits: 9000);
        $theirs = $this->agency(credits: 9000);
        $other = Workspace::query()->create(['name' => 'Not mine', 'status' => 'active']);
        $other->forceFill(['parent_workspace_id' => $theirs->getKey()])->save();

        $res = $this->ctrl()->fund($this->req($this->userFor($mine), ['amount' => 100]), (int) $other->getKey());

        $this->assertSame(404, $res->status());
        $this->assertSame(0, (int) $other->fresh()->credits_topup);
    }

    // ── Editing access ──────────────────────────────────────────────────

    public function test_a_seat_can_be_changed_without_a_new_invite(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->agency();
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->inviteViewer($this->req($u, ['email' => 'ops@acme.test']), (int) $client->getKey());
        $seat = User::query()->where('email', 'ops@acme.test')->firstOrFail();
        $this->assertSame(User::ROLE_CLIENT_VIEWER, $seat->role);

        $res = $this->ctrl()->updateViewer(
            $this->req($u, ['role' => User::ROLE_CLIENT_ADMIN], 'PATCH'),
            (int) $client->getKey(),
            (int) $seat->getKey(),
        );

        $this->assertSame(200, $res->status());
        $this->assertSame(User::ROLE_CLIENT_ADMIN, DB::table('workspace_memberships')->where('workspace_id', $client->id)->where('user_id', $seat->id)->value('role'));
        $this->assertSame(User::ROLE_CLIENT_VIEWER, $seat->fresh()->role);
    }

    public function test_members_are_paged_and_searchable(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->agency();
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        foreach (range(1, 30) as $n) {
            $this->ctrl()->inviteViewer($this->req($u, ['email' => "person{$n}@acme.test"]), (int) $client->getKey());
        }

        $first = $this->ctrl()->viewers($this->req($u, [], 'GET'), (int) $client->getKey())->getData(true);
        $this->assertCount(25, $first['data']['viewers']);
        $this->assertSame(30, $first['meta']['pagination']['total']);

        $req = Request::create('/v', 'GET', ['q' => 'person7@']);
        $req->setUserResolver(fn () => $u);
        $found = $this->ctrl()->viewers($req, (int) $client->getKey())->getData(true);
        $this->assertCount(1, $found['data']['viewers']);
        $this->assertSame('person7@acme.test', $found['data']['viewers'][0]['email']);
    }

    public function test_the_client_list_carries_a_member_count_not_the_members(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $a = $this->agency();
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->inviteViewer($this->req($u, ['email' => 'ops@acme.test']), (int) $client->getKey());

        $body = $this->ctrl()->index($this->req($u, [], 'GET'))->getData(true)['data'];
        $row = collect($body['clients'])->firstWhere('id', (int) $client->getKey());

        $this->assertSame(1, $row['members']);
        $this->assertArrayNotHasKey('viewers', $row, 'a hundred members must not ride inside the list');
        $this->assertSame('pooled', $row['funding_mode']);
        $this->assertNull($row['credits'], 'a pooled client has no balance of its own');
    }


    public function test_funding_a_client_is_not_the_client_spending(): void
    {
        // The bug this pins: adding 250 credits to a client showed up as 250
        // spent this month, so an agency was told a client it had just topped
        // up had already burned the money.
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);

        $this->ctrl()->fund($this->req($u, ['amount' => 250]), (int) $client->getKey());

        $this->assertSame(0, app(CreditService::class)->spentThisMonth((int) $client->getKey()));

        $body = $this->ctrl()->index($this->req($u, [], 'GET'))->getData(true)['data'];
        $row = collect($body['clients'])->firstWhere('id', (int) $client->getKey());
        $this->assertSame(0, $row['spent_this_month'], 'funding is a transfer, not a charge');
        $this->assertSame(250, $row['credits'], 'and it did arrive');
    }

    public function test_taking_credits_back_is_not_spend_either(): void
    {
        $a = $this->agency(credits: 9000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 1000]), (int) $client->getKey());
        $this->ctrl()->fund($this->req($u, ['amount' => -400]), (int) $client->getKey());

        $this->assertSame(0, app(CreditService::class)->spentThisMonth((int) $client->getKey()));
    }

    public function test_real_work_still_counts_as_spend_alongside_funding(): void
    {
        // The fix must not suppress the number it was meant to correct.
        $a = $this->agency(credits: 9000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 1000]), (int) $client->getKey());
        app(CreditService::class)->deduct((int) $client->getKey(), 120, 'render');

        $this->assertSame(120, app(CreditService::class)->spentThisMonth((int) $client->getKey()));
    }

    public function test_a_pooled_client_is_not_shown_the_agencys_balance(): void
    {
        // setUp pins the tier list to the agency tiers; this one is about
        // an enterprise agency, so it has to be allowed to own clients.
        config(['workspaces.client_tiers' => ['agency', 'enterprise']]);
        // A client inherits its agency's plan_tier for feature gating, which
        // was quoting the agency's allowance back at it: an Enterprise agency's
        // client was told it had 50,000 credits a month.
        $a = $this->agency('enterprise', credits: 50000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);

        $summary = app(\App\Services\WorkspaceUsageService::class)->summaryForWorkspace($client->fresh());

        $this->assertNull($summary['credits_balance'], 'not ours to count');
        $this->assertSame(0, $summary['credits_monthly']);
        $this->assertSame('agency', $summary['credits_source']);
        $this->assertSame('Client workspace', $summary['plan']);
    }

    public function test_a_funded_client_is_shown_its_own_balance(): void
    {
        // setUp pins the tier list to the agency tiers; this one is about
        // an enterprise agency, so it has to be allowed to own clients.
        config(['workspaces.client_tiers' => ['agency', 'enterprise']]);
        $a = $this->agency('enterprise', credits: 50000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 800]), (int) $client->getKey());

        $summary = app(\App\Services\WorkspaceUsageService::class)->summaryForWorkspace($client->fresh());

        $this->assertSame(800, $summary['credits_balance']);
        $this->assertSame('allocated', $summary['credits_source']);
    }


    public function test_a_funded_clients_cap_still_fires(): void
    {
        // A funded client's charges land on its own row with no
        // spent_by_workspace_id, so a query matching only the pooled shape saw
        // nothing and the ceiling never applied.
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 5000]), (int) $client->getKey());
        $client->fresh()->forceFill(['monthly_credit_cap' => 300])->save();

        $credits = app(CreditService::class);
        $this->assertTrue($credits->deduct((int) $client->getKey(), 300, 'render'));
        $this->assertFalse($credits->deduct((int) $client->getKey(), 1, 'render'), 'capped, despite having 4,700 left');
        $this->assertSame(300, $credits->spentThisMonth((int) $client->getKey()));
    }

    public function test_a_funded_clients_spend_reaches_the_agency_report(): void
    {
        $a = $this->agency(credits: 20000);
        $u = $this->userFor($a);
        $client = $this->client($a, $u);
        $this->ctrl()->fund($this->req($u, ['amount' => 2000]), (int) $client->getKey());
        app(CreditService::class)->deduct((int) $client->getKey(), 450, 'render');

        $body = $this->ctrl()->usage($this->req($u, [], 'GET'))->getData(true)['data'];
        $row = collect($body['usage'])->firstWhere('workspace_id', (int) $client->getKey());

        $this->assertSame(450, $row['credits'], 'a funded client is still the agency\'s client');
        $this->assertSame(450, $body['total'], 'and funding itself is not in the total');
    }


    public function test_a_failed_ledger_write_takes_the_grant_with_it_and_is_never_silent(): void
    {
        // The contract changed when grants became atomic, and the reason the
        // old one existed still holds: a purchase must never be lost quietly.
        //
        // Credits used to land whatever happened, with the failure logged. That
        // left a workspace holding credits nothing could account for. Now the
        // money and its record move together or not at all, and the failure
        // raises — which is safe precisely because the webhook that drove it is
        // itself transactional: the rollback un-claims the event, and the
        // provider redelivers until both halves succeed.
        //
        // The one outcome still forbidden is the original incident: a grant
        // going astray with nobody told, unnoticed for fifteen days.
        $a = $this->agency(credits: 1000);
        Schema::drop('credit_ledger');   // make every ledger write fail

        try {
            app(CreditService::class)->grant((int) $a->getKey(), 500, 'topup_kelviq');
            $this->fail('A ledger write failure must never pass silently');
        } catch (\Illuminate\Database\QueryException) {
            // Loud, as intended — the caller (or the webhook) can retry.
        }

        $this->assertSame(1000, (int) $a->fresh()->credits_topup,
            'credits and their ledger row land together, or neither does');
    }

}
