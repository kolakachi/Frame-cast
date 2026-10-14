<?php

namespace Tests\Feature;

use App\Models\AppSumoLicense;
use App\Models\Workspace;
use App\Services\AppSumoService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Schema};
use Tests\TestCase;

/**
 * The AppSumo listing approved 2026-10-14 changed the tiers' credits (2,500 / 9,000 / 20,000, was 4,000 / 9,000 /
 * 30,000). Each licence keeps the terms it was bought on: licences already on file keep the original bucket, new
 * purchases and any upgrade get the new one, and nothing a holder was granted is ever taken back.
 */
class AppSumoCreditScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['database.default' => 'schedule_test', 'database.connections.schedule_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('schedule_test');
        config(['appsumo.tiers' => [
            1 => ['plan_tier' => 'appsumo_starter', 'credits' => 2500,  'legacy_credits' => 4000],
            2 => ['plan_tier' => 'appsumo_creator', 'credits' => 9000,  'legacy_credits' => 9000],
            3 => ['plan_tier' => 'appsumo_agency',  'credits' => 20000, 'legacy_credits' => 30000],
        ]]);
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('plan_tier')->nullable();
            $t->string('plan_source')->nullable(); $t->string('plan_status')->nullable();
            $t->string('status')->nullable(); $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->integer('credits_monthly')->default(0); $t->integer('credits_topup')->default(0);
            $t->integer('credits_free_granted')->default(0);
            $t->timestamp('welcome_email_sent_at')->nullable(); $t->string('acquisition_channel')->nullable(); $t->timestamps();
        });
        Schema::create('appsumo_licenses', function (Blueprint $t) {
            $t->id(); $t->string('license_key'); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('tier')->nullable(); $t->integer('appsumo_tier')->nullable();
            $t->string('status')->default('active'); $t->integer('granted_credits')->default(0); $t->string('credit_schedule')->nullable();
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

    }

    private function activate(string $key, string $email): Workspace
    {
        $user = app(AppSumoService::class)->linkAndProvision($key, $email, 'a-password-123', 'Ada');
        return Workspace::findOrFail($user->workspace_id);
    }

    public function test_a_licence_bought_on_the_new_listing_gets_the_new_credits(): void
    {
        app(AppSumoService::class)->handleEvent(['event' => 'purchase', 'license_key' => 'new-3', 'tier' => 3]);
        $this->assertSame('v2', AppSumoLicense::where('license_key', 'new-3')->value('credit_schedule'));
        $this->assertSame(20000, (int) $this->activate('new-3', 'agency@example.com')->credits_topup);

        app(AppSumoService::class)->handleEvent(['event' => 'purchase', 'license_key' => 'new-1', 'tier' => 1]);
        $this->assertSame(2500, (int) $this->activate('new-1', 'starter@example.com')->credits_topup);
    }

    public function test_a_licence_bought_before_keeps_the_original_credits_when_activated_now(): void
    {
        AppSumoLicense::query()->create(['license_key' => 'old-3', 'tier' => 'appsumo_agency', 'appsumo_tier' => 3, 'status' => 'active']);
        $this->assertSame(30000, (int) $this->activate('old-3', 'old@example.com')->credits_topup);

        // A re-sent purchase or a re-activation does not move it onto the new terms.
        app(AppSumoService::class)->handleEvent(['event' => 'activate', 'license_key' => 'old-3', 'tier' => 3]);
        $this->assertNull(AppSumoLicense::where('license_key', 'old-3')->value('credit_schedule'));
        $this->assertSame(30000, (int) Workspace::query()->value('credits_topup'));
    }

    public function test_an_upgrade_tops_up_to_the_new_tier_and_never_takes_credits_back(): void
    {
        AppSumoLicense::query()->create(['license_key' => 'old-1', 'tier' => 'appsumo_starter', 'appsumo_tier' => 1, 'status' => 'active']);
        $ws = $this->activate('old-1', 'up@example.com');
        $this->assertSame(4000, (int) $ws->credits_topup, 'bought on the original listing');

        app(AppSumoService::class)->handleEvent(['event' => 'upgrade', 'license_key' => 'up-2', 'prev_license_key' => 'old-1', 'tier' => 2]);
        $this->assertSame(9000, (int) $ws->fresh()->credits_topup, 'topped up by the difference to the new Creator bucket');
        $this->assertSame('appsumo_creator', $ws->fresh()->plan_tier);

        // A downgrade back to Starter (2,500 on the new listing) grants nothing and takes nothing.
        app(AppSumoService::class)->handleEvent(['event' => 'downgrade', 'license_key' => 'down-1', 'prev_license_key' => 'up-2', 'tier' => 1]);
        $this->assertSame(9000, (int) $ws->fresh()->credits_topup);
    }
}
