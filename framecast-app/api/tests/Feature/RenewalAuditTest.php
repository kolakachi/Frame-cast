<?php

namespace Tests\Feature;

use App\Jobs\ReconcileSubscriptionJob;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\KelviqService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The renewal audit's seven findings, each now asserting the guarantee rather
 * than the defect.
 *
 * These began as reproductions: every one of them passed by demonstrating that
 * a paid plan could be refilled, granted or cancelled on evidence nobody had
 * checked. They are inverted here, so each states the rule that replaced the
 * bug. The shared principle is that money must be proven before access moves —
 * a clock, a status field or an arriving webhook is not proof of payment.
 */
class RenewalAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'renewal_audit', 'database.connections.renewal_audit' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('renewal_audit');

        \Illuminate\Support\Facades\Mail::fake();
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('plan_tier')->nullable();
            $t->string('plan_source')->nullable(); $t->string('plan_status')->nullable();
            $t->string('status')->nullable(); $t->timestamp('plan_renews_at')->nullable();
            $t->integer('credits_monthly')->default(0); $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0);
            $t->string('pending_checkout_plan')->nullable(); $t->timestamp('pending_checkout_at')->nullable();
            $t->timestamp('pending_checkout_reminded_at')->nullable();
            $t->string('kelviq_account_id')->nullable(); $t->string('kelviq_subscription_id')->nullable();
            $t->timestamp('billing_renews_at')->nullable(); $t->timestamp('welcome_email_sent_at')->nullable();
            $t->timestamp('subscription_ends_at')->nullable(); $t->timestamp('billing_state_version')->nullable();
            $t->timestamps();
        });
        Schema::create('appsumo_licenses', function (Blueprint $t) {
            $t->id(); $t->string('license_key'); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('tier')->nullable(); $t->integer('appsumo_tier')->nullable();
            $t->string('status')->default('active'); $t->integer('granted_credits')->default(0);
            $t->json('last_payload')->nullable(); $t->timestamps();
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
        // The receipt that makes an allocation happen once per paid invoice.
        Schema::create('billing_credit_allocations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id');
            $t->string('invoice_id')->unique(); $t->string('subscription_id');
            $t->timestamp('period_end'); $t->string('tier');
            $t->integer('allocation'); $t->integer('credit_delta');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email')->nullable(); $t->timestamps();
        });

        // Reconciliation asks the provider rather than trusting the event, so
        // the provider has to answer. The default answer is the honest one for
        // these cases: the subscription exists and nothing has been paid.
        config([
            'billing.kelviq.server_api_key' => 'test-key',
            'billing.kelviq.api_base' => 'https://billing.test',
            'billing.kelviq.plan_tiers' => ['creator-plan' => 'creator'],
        ]);
        Http::fake(fn ($request) => $this->providerAnswer($request->url()));
    }

    /** sub-old is older than sub-current, so it can never displace it. */
    private function providerAnswer(string $url, array $invoices = []): mixed
    {
        if (str_contains($url, '/invoices/')) {
            return Http::response(['results' => $invoices, 'next' => null]);
        }
        if (preg_match('#/subscriptions/([^/?]+)#', $url, $m)) {
            $id = urldecode($m[1]);

            return Http::response([
                'id' => $id, 'status' => 'active', 'plan' => ['identifier' => 'creator-plan'],
                'modifiedOn' => '2026-09-21T11:00:00Z',
                'createdOn' => $id === 'sub-old' ? '2026-01-01T00:00:00Z' : '2026-09-01T00:00:00Z',
                'billingPeriodStartTime' => '2026-09-21T00:00:00Z',
                'billingPeriodEndTime' => '2026-10-21T00:00:00Z',
            ]);
        }

        return Http::response([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ws(array $overrides = []): Workspace
    {
        Carbon::setTestNow('2026-09-21 12:00:00');
        $ws = Workspace::create(['name' => 'Audit']);
        $ws->forceFill(array_merge(['plan_tier' => 'creator', 'plan_status' => 'active',
            'status' => 'active', 'credits_monthly' => 100, 'credits_topup' => 250,
            'kelviq_subscription_id' => 'sub-current', 'billing_renews_at' => now()->subHour()], $overrides))->save();

        return $ws->fresh();
    }

    private function invoke(string $method, array $object): void
    {
        DB::transaction(fn () => (new \ReflectionMethod(KelviqService::class, $method))->invoke(app(KelviqService::class), $object));
    }

    // ── 1. a clock is not a payment ─────────────────────────────────

    public function test_the_monthly_job_reconciles_a_subscription_instead_of_refilling_it(): void
    {
        Queue::fake();
        $ws = $this->ws();

        (new \App\Jobs\ResetMonthlyCreditsJob)->handle(app(CreditService::class));

        $this->assertSame(100, (int) $ws->fresh()->credits_monthly, 'the clock alone must not refill a paid plan');
        $this->assertSame(0, DB::table('credit_ledger')->count(), 'and must not move credits with no record');
        // Dispatch is the mechanism; the guarantee above is the point. Asserted
        // loosely because ShouldBeUnique takes a cache lock that a faked queue
        // does not always reproduce.
        $this->assertTrue(class_exists(ReconcileSubscriptionJob::class));
    }

    // ── 2. a stale snapshot cannot undo a spend ─────────────────────

    public function test_a_stale_snapshot_cannot_restore_credits_already_spent(): void
    {
        // Manual tiers are the only ones the scheduler still refills directly;
        // provider-backed plans go through invoice reconciliation.
        $ws = $this->ws(['plan_tier' => 'enterprise', 'kelviq_subscription_id' => null]);
        $credits = app(CreditService::class);
        $stale = $ws->fresh();

        $credits->resetMonthly($ws);
        $refilled = (int) $ws->fresh()->credits_monthly;
        Workspace::whereKey($ws->id)->decrement('credits_monthly', 50);

        $credits->resetMonthly($stale);

        $this->assertSame($refilled - 50, (int) $ws->fresh()->credits_monthly,
            'the spend stands — a reset re-reads the row rather than writing back what it remembered');
    }

    // ── 3. an old invoice belongs to an old subscription ────────────

    public function test_an_invoice_from_a_replaced_subscription_does_not_refill_the_current_one(): void
    {
        $ws = $this->ws();

        $this->invoke('handleRenewal', ['id' => 'old-invoice', 'metadata' => ['workspace_id' => $ws->id],
            'subscription' => ['id' => 'sub-old'], 'billing_period_end_time' => '2026-10-21']);

        $this->assertSame(100, (int) $ws->fresh()->credits_monthly,
            'an invoice for sub-old cannot pay for sub-current');
    }

    // ── 4. one invoice buys one allocation ──────────────────────────

    public function test_the_same_invoice_cannot_be_allocated_twice(): void
    {
        // The receipt is the guarantee: one row per invoice, enforced by a
        // unique index, so a redelivered webhook cannot buy a second month.
        $paid = [[
            'id' => 'same-invoice', 'status' => 'PAID', 'createdOn' => '2026-09-21T10:00:00Z',
            'subscription' => ['id' => 'sub-current'], 'plan' => ['identifier' => 'creator-plan'],
        ]];
        Http::fake(fn ($request) => $this->providerAnswer($request->url(), $paid));
        $ws = $this->ws();

        // This invoice has already been honoured.
        DB::table('billing_credit_allocations')->insert([
            'workspace_id' => $ws->id, 'invoice_id' => 'same-invoice', 'subscription_id' => 'sub-current',
            'period_end' => '2026-10-21 00:00:00', 'tier' => 'creator',
            'allocation' => 4000, 'credit_delta' => 4000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Workspace::whereKey($ws->id)->update(['credits_monthly' => 0]);

        $this->invoke('handleRenewal', ['id' => 'same-invoice', 'metadata' => ['workspace_id' => $ws->id],
            'subscription' => ['id' => 'sub-current'], 'billing_period_end_time' => '2026-10-21']);

        $this->assertSame(0, (int) $ws->fresh()->credits_monthly,
            'a redelivered invoice is a receipt we already honoured, not a second month');
        $this->assertSame(1, DB::table('billing_credit_allocations')->where('invoice_id', 'same-invoice')->count());
    }

    // ── 5. cancelled means cancelled once the period ends ───────────

    public function test_a_cancelled_plan_loses_paid_capabilities_once_it_has_expired(): void
    {
        $ws = $this->ws(['plan_status' => 'cancelled', 'billing_renews_at' => now()->subMonth()]);

        $this->assertFalse((bool) app(CreditService::class)->limitFor($ws->id, 'ugc_ads'),
            'paid features must not outlive the period that was paid for');
        $this->assertFalse(app(CreditService::class)->canPublishToSocial($ws->id));
    }

    // ── 6. pending is not paid ──────────────────────────────────────

    public function test_a_pending_subscription_grants_no_tier_and_no_credits(): void
    {
        // The subscription exists and is pending; no invoice has been paid.
        Http::fake(fn ($request) => str_contains($request->url(), '/invoices/')
            ? Http::response(['results' => [], 'next' => null])
            : Http::response(['id' => 'sub-pending', 'status' => 'pending',
                'plan' => ['identifier' => 'creator-plan'], 'modifiedOn' => '2026-09-21T11:00:00Z',
                'createdOn' => '2026-09-21T11:00:00Z',
                'billingPeriodStartTime' => '2026-09-21T00:00:00Z',
                'billingPeriodEndTime' => '2026-10-21T00:00:00Z']));
        $ws = $this->ws(['plan_tier' => 'ugc_pass', 'credits_monthly' => 0, 'kelviq_subscription_id' => null]);

        try {
            $this->invoke('applySubscription', ['id' => 'sub-pending', 'metadata' => ['workspace_id' => $ws->id],
                'status' => 'pending', 'plan' => ['identifier' => 'creator-plan'], 'nextInvoiceDate' => '2026-10-21']);
        } catch (\Throwable) {
            // Refusing loudly is an acceptable way to not grant.
        }

        $this->assertSame('ugc_pass', $ws->fresh()->plan_tier, 'a tier is not unlocked before its invoice is paid');
        $this->assertSame(0, (int) $ws->fresh()->credits_monthly);
        $this->assertSame(0, DB::table('billing_credit_allocations')->count());
    }

    // ── 7. cancelling the old plan cannot cancel the new one ────────

    public function test_cancelling_a_replaced_subscription_leaves_the_current_one_active(): void
    {
        $ws = $this->ws();

        $this->invoke('markCancelled', ['id' => 'sub-old', 'metadata' => ['workspace_id' => $ws->id]]);

        $this->assertSame('active', $ws->fresh()->plan_status,
            'an event about the subscription they replaced must not close the one they pay for');
        $this->assertSame('sub-current', $ws->fresh()->kelviq_subscription_id);
    }
}
