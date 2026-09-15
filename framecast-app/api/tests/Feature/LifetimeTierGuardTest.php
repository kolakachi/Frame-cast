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

    public function test_the_same_pack_twice_does_not_grant_twice(): void
    {
        $w = $this->ws(['plan_tier' => 'lifetime_creator', 'plan_source' => 'lifetime', 'credits_topup' => 12000]);

        $this->buyLifetime($w, 'lifetime_creator', 12000);

        $this->assertSame(12000, (int) $w->fresh()->credits_topup, 'a redelivered webhook must not pay twice');
    }
}
