<?php

namespace Tests\Feature;

use App\Models\LaunchPurchase;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Launch\LaunchCatalogue;
use App\Services\Launch\LaunchpadService;
use App\Services\WorkspaceUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Launchpad launch tiers (owner, 2026-10-12): buyers get exactly what they bought, purchases stack, a refund takes the
 * product back, and every tier sold anywhere else resolves exactly as it did before.
 */
class LaunchpadGatingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'launch_test', 'database.connections.launch_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('launch_test');
        Mail::fake();
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->unsignedBigInteger('parent_workspace_id')->nullable();
            $t->string('plan_tier')->nullable(); $t->string('plan_source')->nullable(); $t->json('plan_addons')->nullable();
            $t->string('plan_status')->nullable(); $t->string('status')->nullable(); $t->timestamp('plan_renews_at')->nullable();
            $t->integer('credits_monthly')->default(0); $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0); $t->string('kelviq_subscription_id')->nullable();
            $t->string('acquisition_channel')->nullable(); $t->string('referral_code')->nullable();
            $t->timestamp('billing_renews_at')->nullable(); $t->timestamp('subscription_ends_at')->nullable();
            $t->timestamp('welcome_email_sent_at')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable(); $t->string('name')->nullable();
            $t->string('email')->nullable(); $t->string('password_hash')->nullable(); $t->string('timezone')->nullable();
            $t->string('role')->nullable(); $t->string('status')->nullable(); $t->integer('onboarding_step')->nullable();
            $t->timestamp('onboarding_last_sent_at')->nullable(); $t->timestamps();
        });
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('operation');
            foreach (['spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->integer('credits'); $t->integer('balance_after')->nullable();
            $t->json('metadata')->nullable(); $t->timestamps();
        });
        Schema::create('launch_purchases', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('product'); $t->string('receipt');
            $t->string('source')->default('launchpad'); $t->string('email'); $t->string('status')->default('active');
            $t->unsignedInteger('credits_granted')->default(0); $t->unsignedInteger('credits_revoked')->default(0);
            $t->decimal('amount', 12, 2)->nullable(); $t->string('currency', 3)->nullable(); $t->json('payload')->nullable();
            $t->timestamp('revoked_at')->nullable(); $t->timestamps(); $t->unique(['receipt', 'product']);
        });
    }

    private function launch(): LaunchpadService
    {
        return app(LaunchpadService::class);
    }

    private function account(string $email, string $tier, string $source, int $topup = 0): Workspace
    {
        $w = Workspace::query()->create(['name' => 'Acme']);
        $w->forceFill(['plan_tier' => $tier, 'plan_source' => $source, 'plan_status' => 'active', 'status' => 'active', 'credits_topup' => $topup])->save();
        $u = User::query()->create(['email' => $email, 'name' => 'Owner']);
        $u->forceFill(['workspace_id' => $w->getKey(), 'role' => 'owner', 'status' => 'active'])->save();

        return $w->fresh();
    }

    public function test_every_tier_sold_elsewhere_resolves_exactly_as_before(): void
    {
        foreach (CreditService::PLAN_LIMITS as $tier => $row) {
            if (LaunchCatalogue::isLaunchTier($tier)) continue;
            $w = (new Workspace)->forceFill(['plan_tier' => $tier]);
            $limits = CreditService::limitsFor($w);
            foreach ($row as $key => $value) {
                $this->assertSame($value, $limits[$key], "{$tier}.{$key}");
            }
            foreach (CreditService::GATE_DEFAULTS as $key => $value) {
                $this->assertSame($value, $limits[$key], "{$tier}.{$key} keeps today's behaviour");
            }
            $this->assertSame(WorkspaceUsageService::plans()[$tier], WorkspaceUsageService::planFor($w), $tier);
        }
    }

    public function test_launch_tiers_spell_out_every_gate_and_are_never_watermarked(): void
    {
        $keys = array_keys(CreditService::PLAN_LIMITS['starter'] + CreditService::GATE_DEFAULTS);
        foreach (array_keys(LaunchCatalogue::TIER_RANK) as $tier) {
            $this->assertEqualsCanonicalizing($keys, array_keys(CreditService::PLAN_LIMITS[$tier]), $tier);
            $this->assertFalse(WorkspaceUsageService::plans()[$tier]['watermark'], $tier);
            $this->assertSame(0, CreditService::PLAN_CREDITS[$tier]);
        }
    }

    public function test_a_first_time_front_end_buyer_gets_an_account_the_composer_and_200_credits_only(): void
    {
        $out = $this->launch()->grant('New.Buyer@Example.com', 'New Buyer', 'fe', 'LP-1');
        $this->assertTrue($out['created']);
        $w = Workspace::query()->findOrFail($out['purchase']->workspace_id);
        $this->assertSame(['launch_fe', 'launchpad', 200, 'launchpad'], [$w->plan_tier, $w->plan_source, (int) $w->credits_topup, $w->acquisition_channel]);
        $this->assertSame('new.buyer@example.com', User::query()->where('workspace_id', $w->id)->value('email'));
        $caps = CreditService::limitsFor($w);
        foreach (['create', 'ugc_ads', 'api_access', 'social_publishing', 'reference_style', 'series', 'custom_characters'] as $gate) {
            $this->assertFalse($caps[$gate], $gate);
        }
        $this->assertSame([60, 0, 0], [$caps['max_duration_seconds'], $caps['max_characters'], $caps['sample_vault']]);
        $this->assertSame(0, WorkspaceUsageService::planFor($w)['voice_cloning_limit']);
    }

    public function test_purchases_stack_and_a_redelivered_sale_grants_nothing_twice(): void
    {
        foreach ([['fe', 'LP-2'], ['bundle', 'LP-3'], ['bundle_dfy', 'LP-3'], ['bundle_length', 'LP-3'], ['bundle', 'LP-3']] as [$p, $r]) {
            $this->launch()->grant('stack@example.com', null, $p, $r);
        }
        $w = User::query()->where('email', 'stack@example.com')->firstOrFail()->workspace;
        $this->assertSame('launch_bundle', $w->plan_tier);
        $this->assertSame(8200, (int) $w->credits_topup);
        $this->assertSame(4, LaunchPurchase::query()->count());
        $caps = CreditService::limitsFor($w);
        $this->assertSame([30, 180, null, 3, 50, true], [$caps['sample_vault'], $caps['max_duration_seconds'], $caps['max_channels'], $caps['batch_variations'], $caps['max_client_workspaces'], $caps['series']]);
        $this->assertSame(5, WorkspaceUsageService::planFor($w)['voice_cloning_limit']);
    }

    public function test_bumps_add_to_the_products_own_allowance(): void
    {
        $this->launch()->grant('pro@example.com', null, 'pro', 'LP-4');
        $this->launch()->grant('pro@example.com', null, 'pro_seats', 'LP-4');
        $w = User::query()->where('email', 'pro@example.com')->firstOrFail()->workspace;
        $this->assertSame(3, CreditService::limitsFor($w)['max_characters']);
        $this->assertSame(3, WorkspaceUsageService::planFor($w)['voice_cloning_limit']);
        $this->assertSame(1, WorkspaceUsageService::planFor($w)['channel_limit']);
        $this->assertSame(1500, (int) $w->credits_topup);
    }

    public function test_an_appsumo_or_lifetime_holder_keeps_their_plan_and_only_gains(): void
    {
        $w = $this->account('ltd@example.com', 'appsumo_creator', 'appsumo', 9549);
        $before = CreditService::limitsFor($w);
        $this->launch()->grant('ltd@example.com', null, 'fe', 'LP-5');
        $w->refresh();
        $this->assertSame(['appsumo_creator', 'appsumo'], [$w->plan_tier, $w->plan_source]);
        $this->assertSame($before, CreditService::limitsFor($w), 'the front end takes nothing away');
        $this->launch()->grant('ltd@example.com', null, 'bundle', 'LP-6');
        $w->refresh();
        $this->assertSame('appsumo_creator', $w->plan_tier);
        $this->assertSame(9549 + 200 + 8000, (int) $w->credits_topup);
        $caps = CreditService::limitsFor($w);
        $this->assertSame([10, 5, null, true], [$caps['max_characters'], $caps['max_brand_kits'], $caps['max_channels'], $caps['series']]);

        $sub = $this->account('sub@example.com', 'lifetime_agency', 'lifetime');
        $this->launch()->grant('sub@example.com', null, 'pro', 'LP-7');
        $this->assertSame('lifetime_agency', $sub->fresh()->plan_tier);
        $this->assertSame(600, CreditService::limitsFor($sub->fresh())['max_duration_seconds']);
    }

    public function test_a_refund_takes_back_the_product_and_its_unspent_credits(): void
    {
        $this->launch()->grant('refund@example.com', null, 'fe', 'LP-8');
        $this->launch()->grant('refund@example.com', null, 'bundle', 'LP-9');
        $w = User::query()->where('email', 'refund@example.com')->firstOrFail()->workspace;
        $w->forceFill(['credits_topup' => 3000])->save(); // spent 5,200 of 8,200
        $this->launch()->revoke('LP-9');
        $w->refresh();
        $this->assertSame(['launch_fe', 0, 'active'], [$w->plan_tier, (int) $w->credits_topup, $w->status]);
        $this->assertFalse(CreditService::limitsFor($w)['create']);
        $this->assertSame(3000, (int) LaunchPurchase::query()->where('receipt', 'LP-9')->value('credits_revoked'));
        $this->assertSame(3000, (int) DB::table('credit_ledger')->where('operation', 'forfeit:launch_refunded')->value('credits'));

        $this->launch()->revoke('LP-8', 'fe', 'chargeback');
        $w->refresh();
        $this->assertSame(['free', 'suspended', null], [$w->plan_tier, $w->status, $w->plan_addons]);
    }

    public function test_an_agency_client_has_its_agencys_launch_allowance(): void
    {
        $this->launch()->grant('agency@example.com', null, 'agency', 'LP-10');
        $this->launch()->grant('agency@example.com', null, 'agency_clients', 'LP-10');
        $agency = User::query()->where('email', 'agency@example.com')->firstOrFail()->workspace;
        $client = Workspace::query()->create(['name' => 'Client']);
        $client->forceFill(['parent_workspace_id' => $agency->id, 'plan_tier' => 'free'])->save();
        $caps = CreditService::limitsFor($client->fresh());
        $this->assertSame([40, false, true], [$caps['max_client_workspaces'], $caps['api_access'], $caps['ugc_ads']]);
    }
}
