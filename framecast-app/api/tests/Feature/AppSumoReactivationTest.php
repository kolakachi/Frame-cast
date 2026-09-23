<?php

namespace Tests\Feature;

use App\Exceptions\AppSumoLicenseAlreadyClaimed;
use App\Models\AppSumoLicense;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppSumoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Schema};
use Tests\TestCase;

/**
 * A buyer clicking their AppSumo activation link a second time.
 *
 * This is what happened to a real customer: they activated on one email, and
 * days later opened the same link and typed a different address. The licence
 * was re-pointed at a brand-new empty workspace, and because credits are
 * granted once per licence, the new workspace got the tier badge and an empty
 * wallet while the original kept 4,000 credits and lost its licence. To them
 * it looked like their lifetime deal had turned into a free plan, and AppSumo
 * still showed the deal as activated so they could not re-claim it.
 *
 * A second activation from a different address must be refused, leaving the
 * original workspace holding both the licence and the credits.
 */
class AppSumoReactivationTest extends TestCase
{
    private const KEY = 'f1b7820c-4190-4751-9789-e26ec253c972';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // linkAndProvision dispatches sample-project + defaults jobs
        config(['database.default' => 'reactivation_test', 'database.connections.reactivation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('reactivation_test');
        config(['appsumo.tiers' => [1 => ['plan_tier' => 'appsumo_starter', 'credits' => 4000]]]);

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('plan_tier')->nullable();
            $t->string('plan_source')->nullable(); $t->string('plan_status')->nullable();
            $t->string('status')->nullable(); $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->integer('credits_monthly')->default(0); $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0);
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
            foreach (['spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
            }
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->integer('credits'); $t->integer('balance_after')->nullable();
            $t->json('metadata')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->string('password_hash')->nullable(); $t->string('timezone')->nullable();
            $t->string('role')->nullable(); $t->string('status')->nullable();
            $t->json('preferences_json')->nullable(); $t->timestamps();
        });

        AppSumoLicense::query()->create([
            'license_key' => self::KEY, 'tier' => 'appsumo_starter',
            'appsumo_tier' => 1, 'status' => 'active', 'granted_credits' => 0,
        ]);
    }

    private function activate(string $email): ?User
    {
        return app(AppSumoService::class)->linkAndProvision(self::KEY, $email, 'a-password-123', 'Bob');
    }

    public function test_a_different_email_is_told_where_the_deal_already_lives(): void
    {
        $first = $this->activate('first@example.com');
        $this->assertNotNull($first);
        $this->assertSame(4000, (int) Workspace::find($first->workspace_id)->credits_topup);

        // Same link, a different address — the mistake a real buyer made.
        try {
            $this->activate('second@example.com');
            $this->fail('claiming a licence from a second address must be refused');
        } catch (AppSumoLicenseAlreadyClaimed $e) {
            $this->assertSame('f***t@example.com', $e->maskedEmail,
                'the buyer is told which account to sign in with, without leaking the full address');
        }
    }

    public function test_a_refused_second_activation_leaves_the_licence_where_it_was(): void
    {
        $first = $this->activate('first@example.com');
        $original = (int) $first->workspace_id;

        try { $this->activate('second@example.com'); } catch (AppSumoLicenseAlreadyClaimed) { /* expected */ }

        // The failure this guards against: the licence moves, the credits do
        // not, and the buyer is left looking at a paid tier with an empty wallet.
        $licensed = (int) AppSumoLicense::where('license_key', self::KEY)->value('workspace_id');
        $this->assertSame($original, $licensed, 'the licence must not follow the new address');
        $this->assertSame(4000, (int) Workspace::find($licensed)->credits_topup,
            'whichever workspace holds the licence must hold the credits');
    }

    public function test_a_refused_second_activation_creates_no_workspace_and_no_user(): void
    {
        $this->activate('first@example.com');
        $workspaces = Workspace::query()->count();
        $users = User::query()->count();

        try { $this->activate('second@example.com'); } catch (AppSumoLicenseAlreadyClaimed) { /* expected */ }

        $this->assertSame($workspaces, Workspace::query()->count(), 'one licence must never produce two workspaces');
        $this->assertSame($users, User::query()->count(), 'nor an account the buyer cannot use');
    }

    public function test_the_same_buyer_clicking_twice_is_idempotent(): void
    {
        $first = $this->activate('first@example.com');

        $again = $this->activate('first@example.com');

        $this->assertNotNull($again, 'their own link must keep working');
        $this->assertSame((int) $first->workspace_id, (int) $again->workspace_id);
    }

    public function test_the_credits_are_granted_once_and_only_once(): void
    {
        $this->activate('first@example.com');
        $this->activate('first@example.com');
        try { $this->activate('second@example.com'); } catch (AppSumoLicenseAlreadyClaimed) { /* refused */ }

        $granted = -(int) DB::table('credit_ledger')->where('operation', 'grant:appsumo_ltd')->sum('credits');
        $this->assertSame(4000, $granted, 'reactivation must not mint a second bucket either');
    }

    public function test_a_deactivated_licence_still_refuses(): void
    {
        AppSumoLicense::where('license_key', self::KEY)->update(['status' => 'deactivated']);

        $this->assertNull($this->activate('first@example.com'),
            'a refunded licence must not provision anything');
    }
}
