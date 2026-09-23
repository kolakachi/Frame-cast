<?php

namespace Tests\Feature;

use App\Jobs\ResetMonthlyCreditsJob;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionRenewal;
use App\Services\CreditService;
use App\Services\KelviqService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionRenewalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'renewal_audit', 'database.connections.renewal_audit' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('renewal_audit');

        Mail::fake();
        Http::preventStrayRequests();
        config(['billing.kelviq.server_api_key' => 'test', 'billing.kelviq.api_base' => 'https://billing.test', 'billing.kelviq.plan_tiers' => ['creator-plan' => 'creator', 'agency-plan' => 'agency']]);
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('plan_tier')->nullable();
            $t->string('plan_source')->nullable();
            $t->string('plan_status')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('plan_renews_at')->nullable();
            $t->integer('credits_monthly')->default(0);
            $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0);
            $t->string('pending_checkout_plan')->nullable();
            $t->timestamp('pending_checkout_at')->nullable();
            $t->timestamp('pending_checkout_reminded_at')->nullable();
            $t->string('kelviq_account_id')->nullable();
            $t->string('kelviq_subscription_id')->nullable();
            $t->timestamp('billing_renews_at')->nullable();
            $t->timestamp('welcome_email_sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('appsumo_licenses', function (Blueprint $t) {
            $t->id();
            $t->string('license_key');
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('tier')->nullable();
            $t->integer('appsumo_tier')->nullable();
            $t->string('status')->default('active');
            $t->integer('granted_credits')->default(0);
            $t->json('last_payload')->nullable();
            $t->timestamps();
        });
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('operation');
            foreach (['spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->integer('credits');
            $t->integer('balance_after')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_22_000000_add_verified_subscription_allocations.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ws(array $overrides = []): Workspace
    {
        Carbon::setTestNow('2026-09-21 12:00:00');
        $ws = Workspace::create(['name' => 'Renewal test']);
        $ws->forceFill(array_merge(['plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active',
            'credits_monthly' => 100, 'credits_topup' => 250, 'kelviq_subscription_id' => 'sub-current',
            'billing_renews_at' => '2026-09-21 10:00:00'], $overrides))->save();

        return $ws->fresh();
    }

    private function sub(array $overrides = []): array
    {
        return array_replace_recursive(['id' => 'sub-current', 'status' => 'active', 'plan' => ['identifier' => 'creator-plan'],
            'billingPeriodStartTime' => '2026-09-21T10:00:00Z', 'billingPeriodEndTime' => '2026-10-21T10:00:00Z',
            'modifiedOn' => '2026-09-21T10:01:00Z', 'createdOn' => '2026-08-21T10:00:00Z', 'endDate' => null], $overrides);
    }

    private function invoice(array $overrides = []): array
    {
        return array_replace_recursive(['id' => 'invoice-1', 'status' => 'PAID', 'plan' => ['identifier' => 'creator-plan'],
            'subscription' => ['id' => 'sub-current'], 'createdOn' => '2026-09-21T10:00:00Z', 'paidAt' => '2026-09-21T10:01:00Z'], $overrides);
    }

    private function provider(?array $sub = null, ?array $invoices = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/subscriptions/sub-current/' => Http::response($sub ?? $this->sub()),
            '*/invoices/*' => Http::response(['results' => $invoices ?? [$this->invoice()], 'next' => null])]);
    }

    private function reconcile(Workspace $ws): void
    {
        app(SubscriptionRenewal::class)->reconcile($ws->fresh(), 'sub-current');
    }

    public function test_paid_invoice_refills_once_and_writes_exact_ledger_delta(): void
    {
        $ws = $this->ws();
        $this->provider();
        $this->reconcile($ws);
        $this->assertSame(4000, $ws->fresh()->credits_monthly);
        $this->assertSame(250, $ws->fresh()->credits_topup);
        $this->assertSame(-3900, (int) DB::table('credit_ledger')->sum('credits'));
        Workspace::whereKey($ws->id)->decrement('credits_monthly', 50);
        $this->reconcile($ws);
        $this->assertSame(3950, $ws->fresh()->credits_monthly);
        $this->assertSame(1, DB::table('billing_credit_allocations')->count());
    }

    public function test_unpaid_renewal_never_mints_credits_and_recovery_restores_access(): void
    {
        $ws = $this->ws();
        $this->provider($this->sub(['status' => 'past_due']), []);
        $this->reconcile($ws);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->assertSame('free', $ws->fresh()->plan_tier);
        $this->assertSame(250, $ws->fresh()->creditsBalance());
        $this->assertSame(0, DB::table('credit_ledger')->count());
        $this->provider();
        $this->reconcile($ws);
        $this->assertSame('creator', $ws->fresh()->plan_tier);
        $this->assertSame(4250, $ws->fresh()->creditsBalance());
    }

    public function test_pending_subscription_does_not_grant_a_paid_tier(): void
    {
        $ws = $this->ws(['plan_tier' => 'free', 'credits_monthly' => 0, 'kelviq_subscription_id' => null]);
        $this->provider($this->sub(['status' => 'incomplete']), []);
        $this->reconcile($ws);
        $this->assertSame('free', $ws->fresh()->plan_tier);
        $this->assertSame(0, $ws->fresh()->credits_monthly);
        $this->assertSame('sub-current', $ws->fresh()->kelviq_subscription_id);
    }

    public function test_scheduled_cancellation_preserves_access_only_until_paid_end(): void
    {
        $ws = $this->ws();
        $this->provider($this->sub(['endDate' => '2026-10-21T10:00:00Z']));
        $this->reconcile($ws);
        $this->assertTrue((bool) app(CreditService::class)->limitFor($ws->id, 'ugc_ads'));
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->assertFalse((bool) app(CreditService::class)->limitFor($ws->id, 'ugc_ads'));
        // Not canPublishToSocial — free can publish too, so it proves nothing here.
        $this->assertFalse((bool) app(CreditService::class)->limitFor($ws->id, 'custom_characters'));
        $this->assertSame(250, $ws->fresh()->creditsBalance());
    }

    public function test_old_subscription_cannot_refill_or_cancel_replacement(): void
    {
        $ws = $this->ws();
        Http::fake(['*/subscriptions/sub-old/' => Http::response($this->sub(['id' => 'sub-old', 'status' => 'cancelled', 'createdOn' => '2026-07-21T10:00:00Z'])),
            '*/subscriptions/sub-current/' => Http::response($this->sub())]);
        app(SubscriptionRenewal::class)->reconcile($ws, 'sub-old');
        $this->assertSame('active', $ws->fresh()->plan_status);
        $this->assertSame('sub-current', $ws->fresh()->kelviq_subscription_id);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
    }

    public function test_missing_period_is_retryable_and_never_guessed(): void
    {
        $ws = $this->ws();
        $this->provider($this->sub(['billingPeriodEndTime' => null]));
        try {
            $this->reconcile($ws);
            $this->fail('Missing period must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('period', $e->getMessage());
        }
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->assertSame(0, DB::table('billing_credit_allocations')->count());
    }

    public function test_old_paid_invoice_is_not_proof_of_payment_for_current_period(): void
    {
        $ws = $this->ws();
        $this->provider(null, [$this->invoice(['createdOn' => '2026-08-21T10:00:00Z'])]);
        $this->reconcile($ws);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->assertSame('free', $ws->fresh()->plan_tier);
    }

    public function test_different_invoice_for_same_period_cannot_refill_spent_credits(): void
    {
        $ws = $this->ws();
        $this->provider();
        $this->reconcile($ws);
        Workspace::whereKey($ws->id)->update(['credits_monthly' => 50]);
        $this->provider(null, [$this->invoice(['id' => 'invoice-2'])]);
        $this->reconcile($ws);
        $this->assertSame(50, $ws->fresh()->credits_monthly);
    }

    public function test_agency_rollover_adds_only_one_paid_allocation(): void
    {
        $ws = $this->ws(['plan_tier' => 'agency']);
        $this->provider($this->sub(['plan' => ['identifier' => 'agency-plan']]), [$this->invoice(['plan' => ['identifier' => 'agency-plan']])]);
        $this->reconcile($ws);
        $this->reconcile($ws);
        $this->assertSame(13600, $ws->fresh()->credits_monthly);
    }

    public function test_manual_reset_reloads_under_lock_and_preserves_intervening_spend(): void
    {
        $ws = $this->ws(['plan_tier' => 'enterprise', 'kelviq_subscription_id' => null]);
        $stale = $ws->fresh();
        $credits = app(CreditService::class);
        $credits->resetMonthly($ws);
        $amount = $ws->fresh()->credits_monthly;
        Workspace::whereKey($ws->id)->decrement('credits_monthly', 50);
        $credits->resetMonthly($stale);
        $this->assertSame($amount - 50, $ws->fresh()->credits_monthly);
        $this->assertSame(1, DB::table('credit_ledger')->count());
    }

    public function test_clock_reset_cannot_refill_kelviq_or_unconfigured_manual_tiers(): void
    {
        $ws = $this->ws();
        app(CreditService::class)->resetMonthly($ws);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $ws->forceFill(['kelviq_subscription_id' => null])->save();
        app(CreditService::class)->resetMonthly($ws);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
    }

    public function test_ledger_failure_rolls_back_invoice_receipt_and_allocation(): void
    {
        $ws = $this->ws();
        $this->provider();
        DB::statement("CREATE TRIGGER fail_ledger BEFORE INSERT ON credit_ledger BEGIN SELECT RAISE(ABORT, 'ledger unavailable'); END");
        try {
            $this->reconcile($ws);
            $this->fail('Must fail atomically');
        } catch (QueryException $e) {
        }
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->assertSame(0, DB::table('billing_credit_allocations')->count());
        DB::statement('DROP TRIGGER fail_ledger');
        $this->reconcile($ws);
        $this->assertSame(4000, $ws->fresh()->credits_monthly);
    }

    public function test_scheduler_reconciles_paid_invoices_instead_of_resetting_the_clock(): void
    {
        $ws = $this->ws();
        $this->provider(null, []);
        (new ResetMonthlyCreditsJob)->handle(app(CreditService::class));
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->provider();
        (new ResetMonthlyCreditsJob)->handle(app(CreditService::class));
        $this->assertSame(4000, $ws->fresh()->credits_monthly);
    }

    public function test_paid_replacement_is_adopted_but_delayed_old_state_cannot_restore_it(): void
    {
        $ws = $this->ws(['kelviq_subscription_id' => 'sub-old']);
        Http::fake(['*/subscriptions/sub-old/' => Http::response($this->sub(['id' => 'sub-old', 'status' => 'superseded', 'createdOn' => '2026-07-21T10:00:00Z'])),
            '*/subscriptions/sub-current/' => Http::response($this->sub()),
            '*/invoices/*' => Http::response(['results' => [$this->invoice()], 'next' => null])]);
        $this->reconcile($ws);
        $this->assertSame('sub-current', $ws->fresh()->kelviq_subscription_id);
        app(SubscriptionRenewal::class)->reconcile($ws->fresh(), 'sub-old');
        $this->assertSame('sub-current', $ws->fresh()->kelviq_subscription_id);
        $this->assertSame(4000, $ws->fresh()->credits_monthly);
    }

    public function test_upgrade_downgrade_upgrade_in_one_period_cannot_farm_credits(): void
    {
        $ws = $this->ws();
        $this->provider();
        $this->reconcile($ws);
        Workspace::whereKey($ws->id)->update(['credits_monthly' => 100]);
        $this->provider($this->sub(['plan' => ['identifier' => 'agency-plan']]), [$this->invoice(['id' => 'upgrade', 'plan' => ['identifier' => 'agency-plan']])]);
        $this->reconcile($ws);
        $this->assertSame(9600, $ws->fresh()->credits_monthly);
        $this->provider(null, [$this->invoice(['id' => 'downgrade'])]);
        $this->reconcile($ws);
        Workspace::whereKey($ws->id)->update(['credits_monthly' => 10]);
        $this->provider($this->sub(['plan' => ['identifier' => 'agency-plan']]), [$this->invoice(['id' => 'upgrade-again', 'plan' => ['identifier' => 'agency-plan']])]);
        $this->reconcile($ws);
        $this->assertSame(10, $ws->fresh()->credits_monthly);
    }

    public function test_older_provider_snapshot_cannot_overwrite_newer_state(): void
    {
        $ws = $this->ws(['billing_state_version' => '2026-09-21 11:00:00', 'plan_status' => 'cancelled']);
        $this->provider();
        $this->reconcile($ws);
        $this->assertSame('cancelled', $ws->fresh()->plan_status);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
    }

    public function test_paid_invoice_arriving_before_subscription_event_still_activates(): void
    {
        $ws = $this->ws(['plan_tier' => 'ugc_pass', 'credits_monthly' => 0, 'kelviq_subscription_id' => null, 'billing_renews_at' => null]);
        $this->provider();
        $event = ['id' => 'invoice-1', 'subscription_id' => 'sub-current', 'metadata' => ['workspace_id' => $ws->id]];
        (new \ReflectionMethod(KelviqService::class, 'handleRenewal'))->invoke(app(KelviqService::class), $event);
        $this->assertSame('creator', $ws->fresh()->plan_tier);
        $this->assertSame(4000, $ws->fresh()->credits_monthly);
        $this->reconcile($ws);
        $this->assertSame(1, DB::table('credit_ledger')->count());
    }

    public function test_client_capabilities_expire_with_agency_even_with_own_funding(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->unsignedBigInteger('parent_workspace_id')->nullable());
        $agency = $this->ws(['plan_tier' => 'agency']);
        $client = Workspace::create(['name' => 'Client']);
        $client->forceFill(['parent_workspace_id' => $agency->id, 'plan_tier' => 'agency', 'status' => 'active'])->save();
        $this->assertSame('free', $client->fresh()->plan_tier);
        $this->assertFalse((bool) app(CreditService::class)->limitFor($client->id, 'ugc_ads'));
    }
    public function test_failed_and_paid_invoice_events_use_provider_state_and_deduplicate(): void
    {
        Schema::create('processed_webhook_events', function (Blueprint $t) {
            $t->id(); $t->string('provider'); $t->string('event_id')->unique(); $t->string('type')->nullable();
            $t->timestamp('processed_at'); $t->timestamps();
        });
        $ws = $this->ws();
        $event = ['id'=>'failed-delivery','type'=>'invoice.payment_failed','data'=>['object'=>[
            'id'=>'invoice-1','subscription_id'=>'sub-current','metadata'=>['workspace_id'=>$ws->id],
        ]]];
        $this->provider($this->sub(['status'=>'past_due']), []);
        app(\App\Services\KelviqService::class)->handleEvent($event);
        $this->assertSame('past_due', $ws->fresh()->plan_status);
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->provider(); $event['id']='paid-delivery'; $event['type']='invoice.paid';
        app(\App\Services\KelviqService::class)->handleEvent($event);
        Workspace::whereKey($ws->id)->decrement('credits_monthly',50);
        $event['id']='different-delivery-same-invoice';
        app(\App\Services\KelviqService::class)->handleEvent($event);
        $this->assertSame(3950, $ws->fresh()->credits_monthly);
        $this->assertSame(1, DB::table('billing_credit_allocations')->count());
    }
    public function test_provider_outage_is_retryable_and_changes_no_balances(): void
    {
        $ws = $this->ws(); Http::fake(['*'=>Http::response([],503)]);
        try { $this->reconcile($ws); $this->fail('Provider outage must propagate'); }
        catch (\Illuminate\Http\Client\RequestException $e) {}
        $this->assertSame(100, $ws->fresh()->credits_monthly);
        $this->assertSame(250, $ws->fresh()->credits_topup);
        $this->assertSame(0, DB::table('billing_credit_allocations')->count());
    }
    public function test_immediate_cancellation_uses_authoritative_end_even_before_renewal(): void
    {
        $ws = $this->ws();
        $this->provider($this->sub(['status'=>'cancelled','endDate'=>'2026-09-21T11:00:00Z']));
        $this->reconcile($ws);
        $this->assertSame('free', $ws->fresh()->plan_tier);
        $this->assertSame(250, $ws->fresh()->creditsBalance());
    }
    public function test_paid_lifetime_access_is_not_replaced_by_its_old_subscription(): void
    {
        $ws = $this->ws(['plan_tier'=>'lifetime_agency','plan_source'=>'lifetime']);
        $this->reconcile($ws);
        Http::assertNothingSent();
        $this->assertSame('lifetime_agency', $ws->fresh()->plan_tier);
    }

    public function test_rollover_uses_balance_after_a_spend_during_provider_lookup(): void
    {
        $ws = $this->ws(['plan_tier'=>'agency']);
        Http::fake(['*/subscriptions/sub-current/' => Http::response($this->sub(['plan'=>['identifier'=>'agency-plan']])),
            '*/invoices/*' => function () use ($ws) {
                Workspace::whereKey($ws->id)->decrement('credits_monthly',50);
                return Http::response(['results'=>[$this->invoice(['plan'=>['identifier'=>'agency-plan']])], 'next'=>null]);
            }]);
        $this->reconcile($ws);
        $this->assertSame(13550, $ws->fresh()->credits_monthly);
    }
    public function test_first_paid_invoice_activates_free_account_and_rewards_once(): void
    {
        $reward = $this->createMock(\App\Services\RewardService::class);
        $reward->expects($this->once())->method('referralConversion');
        $this->app->instance(\App\Services\RewardService::class, $reward);
        $ws = $this->ws(['plan_tier'=>'free','credits_monthly'=>0,'kelviq_subscription_id'=>null,'billing_renews_at'=>null]);
        $this->provider(); $this->reconcile($ws); $this->reconcile($ws);
        $this->assertSame('creator', $ws->fresh()->plan_tier);
        $this->assertSame(4000, $ws->fresh()->credits_monthly);
    }

}
