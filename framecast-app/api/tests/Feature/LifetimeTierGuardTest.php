<?php

namespace Tests\Feature;

use App\Models\AppSumoLicense;
use App\Models\Workspace;
use App\Services\AppSumoService;
use App\Services\CreditService;
use App\Services\KelviqService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Deliberately uses a fresh in-memory DB, never the configured application database.
 *
 * These cover the two ways a customer could lose access they had paid for:
 * an AppSumo licence event landing after a lifetime purchase, and a lifetime
 * purchase landing under an AppSumo tier that was already higher.
 */
class LifetimeTierGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'ltd_test', 'database.connections.ltd_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('ltd_test');

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('plan_tier')->nullable();
            $t->string('plan_source')->nullable(); $t->string('plan_status')->nullable();
            $t->string('status')->nullable(); $t->timestamp('plan_renews_at')->nullable();
            $t->integer('credits_monthly')->default(0); $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0);
            $t->string('pending_checkout_plan')->nullable(); $t->timestamp('pending_checkout_at')->nullable();
            $t->timestamp('pending_checkout_reminded_at')->nullable();
            $t->string('kelviq_account_id')->nullable(); $t->string('kelviq_subscription_id')->nullable();
            $t->timestamp('welcome_email_sent_at')->nullable(); $t->timestamps();
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
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('email')->nullable(); $t->timestamps();
        });
    }

    private function ws(array $o = []): Workspace
    {
        $w = Workspace::query()->create(['name' => 'Acme']);
        $w->forceFill(array_merge(['plan_tier' => 'appsumo_creator', 'plan_source' => 'appsumo',
            'plan_status' => 'active', 'status' => 'active', 'credits_topup' => 9549], $o))->save();

        return $w->fresh();
    }

    /** Runs the private applyTierToWorkspace the way a licence webhook would. */
    private function reapplyLicence(Workspace $w, string $tier = 'appsumo_creator', int $num = 2): void
    {
        $lic = AppSumoLicense::query()->create(['license_key' => 'k-'.bin2hex(random_bytes(3)),
            'workspace_id' => $w->getKey(), 'tier' => $tier, 'appsumo_tier' => $num,
            'status' => 'active', 'granted_credits' => 12000]);

        $m = new \ReflectionMethod(AppSumoService::class, 'applyTierToWorkspace');
        $m->setAccessible(true);
        $m->invoke(app(AppSumoService::class), $lic->fresh('workspace'));
    }

    public function test_a_licence_event_cannot_undo_a_lifetime_purchase(): void
    {
        // AppSumo sends purchase/activate on re-activation and re-keys on any
        // tier change. Each lands in applyTierToWorkspace.
        $w = $this->ws(['plan_tier' => 'lifetime_agency', 'plan_source' => 'lifetime', 'credits_topup' => 29549]);

        $this->reapplyLicence($w);

        $this->assertSame('lifetime_agency', $w->fresh()->plan_tier, 'the purchased tier must survive');
        $this->assertSame('lifetime', $w->fresh()->plan_source);
    }

    public function test_a_licence_event_still_grants_credits_the_licence_owes(): void
    {
        // The tier is held, but the bucket is owed on the licence regardless.
        $w = $this->ws(['plan_tier' => 'lifetime_agency', 'plan_source' => 'lifetime', 'credits_topup' => 0]);

        $lic = AppSumoLicense::query()->create(['license_key' => 'k1', 'workspace_id' => $w->getKey(),
            'tier' => 'appsumo_creator', 'appsumo_tier' => 2, 'status' => 'active', 'granted_credits' => 0]);
        config(['appsumo.tiers' => [2 => ['plan_tier' => 'appsumo_creator', 'credits' => 12000]]]);

        $m = new \ReflectionMethod(AppSumoService::class, 'applyTierToWorkspace');
        $m->setAccessible(true);
        $m->invoke(app(AppSumoService::class), $lic->fresh('workspace'));

        $this->assertSame(12000, (int) $w->fresh()->credits_topup);
        $this->assertSame('lifetime_agency', $w->fresh()->plan_tier);
    }

    public function test_an_ordinary_licence_event_still_applies_its_tier(): void
    {
        // The guard must not break the normal path.
        $w = $this->ws(['plan_tier' => 'free', 'plan_source' => null]);

        $this->reapplyLicence($w, 'appsumo_agency', 3);

        $this->assertSame('appsumo_agency', $w->fresh()->plan_tier);
        $this->assertSame('appsumo', $w->fresh()->plan_source);
    }

    // ── the mirror image: a purchase must not lower a tier ──────────

    private function buyLifetime(Workspace $w, string $tier, int $credits): void
    {
        $m = new \ReflectionMethod(KelviqService::class, 'applyLifetimePurchase');
        $m->setAccessible(true);
        $m->invoke(app(KelviqService::class),
            ['id' => 'ord_'.bin2hex(random_bytes(3)), 'metadata' => ['workspace_id' => (string) $w->getKey()]],
            'plan-id', ['tier' => $tier, 'credits' => $credits]);
    }

    public function test_buying_a_smaller_pack_does_not_write_the_customer_down(): void
    {
        // The plans page filters this out, but that filter is in the browser
        // and the endpoint accepts any pack.
        $w = $this->ws(['plan_tier' => 'appsumo_agency', 'plan_source' => 'appsumo', 'credits_topup' => 20000]);

        $this->buyLifetime($w, 'lifetime_starter', 4000);

        $this->assertSame('appsumo_agency', $w->fresh()->plan_tier, 'paying must not cost them access');
        $this->assertSame(24000, (int) $w->fresh()->credits_topup, 'the credits they bought still land');
    }

    public function test_buying_a_bigger_pack_upgrades_the_tier_and_adds_credits(): void
    {
        $w = $this->ws(['plan_tier' => 'appsumo_creator', 'plan_source' => 'appsumo', 'credits_topup' => 9549]);

        $this->buyLifetime($w, 'lifetime_agency', 20000);

        $this->assertSame('lifetime_agency', $w->fresh()->plan_tier);
        $this->assertSame('lifetime', $w->fresh()->plan_source);
        $this->assertSame(29549, (int) $w->fresh()->credits_topup, 'unspent credits carry over');
    }

    // ── upgrading swaps the bucket, it does not stack ───────────────

    public function test_upgrading_lands_on_the_new_bucket_not_the_sum(): void
    {
        // Michael's case: barely-used Creator moving to Agency. Stacking would
        // give him 31,402 for the same money an Agency buyer pays for 20,000.
        config(['billing.kelviq.lifetime_plans' => [
            'p-creator' => ['tier' => 'lifetime_creator', 'credits' => 12000],
            'p-agency'  => ['tier' => 'lifetime_agency',  'credits' => 20000],
        ]]);
        $w = $this->ws(['plan_tier' => 'lifetime_creator', 'plan_source' => 'lifetime', 'credits_topup' => 11402]);

        $this->buyLifetime($w, 'lifetime_agency', 20000);

        $this->assertSame(20000, (int) $w->fresh()->credits_topup);
        $this->assertSame('lifetime_agency', $w->fresh()->plan_tier);
    }

    public function test_credits_bought_separately_survive_the_swap(): void
    {
        // Only the old pack's own allocation is replaced. A top-up was paid for
        // on its own terms and is not ours to take back.
        config(['billing.kelviq.lifetime_plans' => [
            'p-creator' => ['tier' => 'lifetime_creator', 'credits' => 12000],
            'p-agency'  => ['tier' => 'lifetime_agency',  'credits' => 20000],
        ]]);
        // 12,000 pack + 5,000 top-up, 2,000 spent.
        $w = $this->ws(['plan_tier' => 'lifetime_creator', 'plan_source' => 'lifetime', 'credits_topup' => 15000]);

        $this->buyLifetime($w, 'lifetime_agency', 20000);

        $this->assertSame(23000, (int) $w->fresh()->credits_topup, 'the 3,000 top-up remnant stays');
    }

    public function test_someone_who_overspent_the_old_pack_is_not_pushed_negative(): void
    {
        config(['billing.kelviq.lifetime_plans' => [
            'p-creator' => ['tier' => 'lifetime_creator', 'credits' => 12000],
            'p-agency'  => ['tier' => 'lifetime_agency',  'credits' => 20000],
        ]]);
        $w = $this->ws(['plan_tier' => 'lifetime_creator', 'plan_source' => 'lifetime', 'credits_topup' => 900]);

        $this->buyLifetime($w, 'lifetime_agency', 20000);

        $this->assertSame(20000, (int) $w->fresh()->credits_topup);
    }

    public function test_an_appsumo_bucket_is_never_taken_back(): void
    {
        // Those credits were bought from AppSumo, not from us.
        config(['billing.kelviq.lifetime_plans' => [
            'p-agency' => ['tier' => 'lifetime_agency', 'credits' => 20000],
        ]]);
        $w = $this->ws(['plan_tier' => 'appsumo_creator', 'plan_source' => 'appsumo', 'credits_topup' => 11448]);

        $this->buyLifetime($w, 'lifetime_agency', 20000);

        $this->assertSame(31448, (int) $w->fresh()->credits_topup);
    }

    public function test_a_first_time_buyer_is_unaffected(): void
    {
        config(['billing.kelviq.lifetime_plans' => [
            'p-agency' => ['tier' => 'lifetime_agency', 'credits' => 20000],
        ]]);
        $w = $this->ws(['plan_tier' => 'free', 'plan_source' => null, 'credits_topup' => 0]);

        $this->buyLifetime($w, 'lifetime_agency', 20000);

        $this->assertSame(20000, (int) $w->fresh()->credits_topup);
    }

    public function test_the_same_pack_twice_does_not_grant_twice(): void
    {
        $w = $this->ws(['plan_tier' => 'lifetime_creator', 'plan_source' => 'lifetime', 'credits_topup' => 12000]);

        $this->buyLifetime($w, 'lifetime_creator', 12000);

        $this->assertSame(12000, (int) $w->fresh()->credits_topup, 'a redelivered webhook must not pay twice');
    }

    // ── UGC Test Pass ($9, one per customer) ────────────────────────

    private function buyTestPass(Workspace $w): void
    {
        $m = new \ReflectionMethod(KelviqService::class, 'applyUgcTestPass');
        $m->setAccessible(true);
        $m->invoke(app(KelviqService::class),
            ['id' => 'ord_'.bin2hex(random_bytes(3)), 'metadata' => ['workspace_id' => (string) $w->getKey()]], 600);
    }

    public function test_the_test_pass_grants_ugc_access_and_600_credits(): void
    {
        $w = $this->ws(['plan_tier' => 'free', 'plan_source' => null, 'credits_topup' => 200]);

        $this->buyTestPass($w);

        $this->assertSame('ugc_pass', $w->fresh()->plan_tier);
        $this->assertSame(800, (int) $w->fresh()->credits_topup, 'the 600 lands on top of what they had');
        $this->assertTrue(app(CreditService::class)->limitFor((int) $w->getKey(), 'ugc_ads'), 'the pass buys the UGC gate');
        $this->assertFalse((bool) app(CreditService::class)->limitFor((int) $w->getKey(), 'custom_characters'), 'but not an own-face cast');
        $this->assertSame(0, (int) app(CreditService::class)->limitFor((int) $w->getKey(), 'max_characters'), 'and no characters');
    }

    public function test_a_duplicate_test_pass_still_hands_over_the_credits_paid_for(): void
    {
        // Checkout refuses a second pass, so reaching here means a race, a
        // stale tab or a direct link. The money is real: keeping it and
        // granting nothing is the one outcome that is never acceptable.
        $w = $this->ws(['plan_tier' => 'free', 'plan_source' => null, 'credits_topup' => 0]);

        $this->buyTestPass($w);
        $this->buyTestPass($w);

        $this->assertSame(1200, (int) $w->fresh()->credits_topup, 'both payments deliver their credits');
        $this->assertSame(1, \App\Models\CreditLedgerEntry::query()
            ->where('workspace_id', $w->getKey())->where('operation', 'grant:ugc_pass')->count(),
            'but the pass itself is only ever granted once');
    }

    public function test_the_test_pass_never_writes_a_paying_customer_down(): void
    {
        // Ranked with Free, so a Starter who buys the pass keeps Starter —
        // they still get the credits they paid for.
        $w = $this->ws(['plan_tier' => 'lifetime_starter', 'plan_source' => 'lifetime', 'credits_topup' => 1000]);

        $this->buyTestPass($w);

        $this->assertSame('lifetime_starter', $w->fresh()->plan_tier, 'a pass never writes a paying customer down');
        $this->assertSame(1600, (int) $w->fresh()->credits_topup, 'and never swallows the bucket they already paid for');
    }

    // ── Test Pass take allowance ────────────────────────────────────

    public function test_a_pass_take_is_reserved_capped_and_returned_on_failure(): void
    {
        $w = $this->ws(['plan_tier' => 'ugc_pass', 'plan_source' => 'ugc_pass', 'credits_topup' => 600]);
        $ws = (int) $w->getKey();
        $controller = app(\App\Http\Controllers\Api\V1\Ugc\UgcController::class);
        $reserve = new \ReflectionMethod(\App\Http\Controllers\Api\V1\Ugc\UgcController::class, 'reservePassTake');
        $reserve->setAccessible(true);

        $reserve->invoke($controller, $ws, 2, 'req-1');
        $reserve->invoke($controller, $ws, 2, 'req-2');
        $this->assertSame(2, \App\Http\Controllers\Api\V1\Ugc\UgcController::passTakesUsed($ws));

        try {
            $reserve->invoke($controller, $ws, 2, 'req-3');
            $this->fail('A third take must be refused once the pass is spent');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Test Pass covers', $e->getMessage());
        }

        // A generation that failed hands its take back — credits are charged
        // on success only, so losing the allowance too would cost the customer
        // both attempts for no video.
        \App\Models\CreditLedgerEntry::query()->create([
            'workspace_id' => $ws, 'operation' => 'refund:ugc_pass_take',
            'credits' => 0, 'balance_after' => 600,
        ]);
        $this->assertSame(1, \App\Http\Controllers\Api\V1\Ugc\UgcController::passTakesUsed($ws));

        $reserve->invoke($controller, $ws, 2, 'req-3');
        $this->assertSame(2, \App\Http\Controllers\Api\V1\Ugc\UgcController::passTakesUsed($ws));
    }

    public function test_one_run_reserves_a_take_for_every_take_it_will_make(): void
    {
        // A multi-scene run fans out to plans x cast; reserving one claim let a
        // single submission produce several takes on a two-take pass.
        $w = $this->ws(['plan_tier' => 'ugc_pass', 'plan_source' => 'ugc_pass', 'credits_topup' => 600]);
        $ws = (int) $w->getKey();
        $controller = app(\App\Http\Controllers\Api\V1\Ugc\UgcController::class);
        $reserve = new \ReflectionMethod(\App\Http\Controllers\Api\V1\Ugc\UgcController::class, 'reservePassTake');
        $reserve->setAccessible(true);

        try {
            $reserve->invoke($controller, $ws, 2, 'run-1', 3);
            $this->fail('A run making three takes must not fit a two-take pass');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(0, \App\Http\Controllers\Api\V1\Ugc\UgcController::passTakesUsed($ws), 'a refused run claims nothing');
        }

        $reserve->invoke($controller, $ws, 2, 'run-2', 2);
        $this->assertSame(2, \App\Http\Controllers\Api\V1\Ugc\UgcController::passTakesUsed($ws));
    }
    public function test_failure_release_is_per_project_and_repeat_safe(): void
    {
        Schema::create('scenes', function (Blueprint $t) { $t->id(); });
        $w = $this->ws(['plan_tier' => 'ugc_pass', 'credits_topup' => 600]);
        $controller = app(\App\Http\Controllers\Api\V1\Ugc\UgcController::class);
        $reserve = new \ReflectionMethod($controller, 'reservePassTake');
        $service = app(\App\Services\UgcPassTakeService::class);
        $reserve->invoke($controller, (int) $w->id, 2, 'batch', 2);
        $service->attach((int) $w->id, 'batch', 101);
        $service->attach((int) $w->id, 'batch', 102);
        $job = new \App\Jobs\GenerateOneShotUgcJob(101, 999, [], 100);
        $job->failed(new \RuntimeException('provider failed'));
        $job->failed(new \RuntimeException('same failure delivered again'));
        $this->assertSame(1, $controller::passTakesUsed((int) $w->id));
        $this->assertSame(1, DB::table('credit_ledger')->where('operation', 'refund:ugc_pass_take')->count());
        // A plan change or deleted project cannot prevent release of an existing reservation.
        $w->forceFill(['plan_tier' => 'starter'])->save();
        $service->releaseProject(102);
        $this->assertSame(0, $controller::passTakesUsed((int) $w->id));
    }

    public function test_request_cleanup_releases_only_its_claims_and_can_retry(): void
    {
        $w = $this->ws(['plan_tier' => 'ugc_pass', 'credits_topup' => 600]);
        $controller = app(\App\Http\Controllers\Api\V1\Ugc\UgcController::class);
        $reserve = new \ReflectionMethod($controller, 'reservePassTake');
        $service = app(\App\Services\UgcPassTakeService::class);
        $reserve->invoke($controller, (int) $w->id, 2, 'retry');
        $reserve->invoke($controller, (int) $w->id, 2, 'other');
        $service->releaseRequest((int) $w->id, 'retry');
        $service->releaseRequest((int) $w->id, 'retry');
        $this->assertSame(1, $controller::passTakesUsed((int) $w->id));
        $reserve->invoke($controller, (int) $w->id, 2, 'retry');
        $service->attach((int) $w->id, 'retry', 103);
        $service->releaseProject(103);
        $this->assertSame(1, $controller::passTakesUsed((int) $w->id));
    }

}
