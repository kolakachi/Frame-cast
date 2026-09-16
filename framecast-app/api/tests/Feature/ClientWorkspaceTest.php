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
            $t->string('email')->nullable(); $t->string('role')->nullable();
            $t->string('name')->nullable(); $t->string('timezone')->nullable();
            $t->string('status')->nullable(); $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
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
        Schema::create('brand_kits', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id');
            $t->string('name')->nullable();
            $t->string('primary_color')->nullable(); $t->string('secondary_color')->nullable();
            $t->string('accent_color')->nullable();
            $t->string('font_primary')->nullable(); $t->string('font_secondary')->nullable();
            $t->unsignedBigInteger('logo_asset_id')->nullable();
            $t->string('default_caption_style')->nullable();
            $t->unsignedBigInteger('default_voice_profile_id')->nullable();
            $t->timestamps();
        });
        Schema::create('magic_link_tokens', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('email')->nullable();
            $t->string('token_hash'); $t->timestamp('expires_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('auth_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->nullable(); $t->timestamps();
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
        $this->assertSame('email_in_use', $res->getData(true)['error']['code']);
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

    public function test_removing_a_viewer_kills_their_sessions_too(): void
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
        $this->assertNull(User::query()->where('email', 'ops@acme.test')->first());
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', $viewer->getKey())->count());
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

}
