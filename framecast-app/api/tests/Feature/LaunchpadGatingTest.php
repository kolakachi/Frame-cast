<?php

namespace Tests\Feature;

use App\Mail\LaunchpadPurchaseMail;
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
        (require database_path('migrations/2026_10_23_000000_create_launch_access_emails.php'))->up();
        // The done-for-you vault is the template library (Templates, 2026-10-16).
        (require database_path('migrations/2026_10_16_120000_create_create_templates_table.php'))->up();
        (require database_path('migrations/2026_10_17_220000_add_free_audit_to_create_templates.php'))->up();
        (require database_path('migrations/2026_08_28_000001_create_billing_webhook_logs_table.php'))->up();
        config(['services.launchpad.ipn_token' => 'test-token']);
    }

    /** @param  array<string, mixed>  $over */
    private function ipn(array $over = [], string $token = 'test-token'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/webhooks/launchpad/{$token}", array_replace_recursive([
            'user_id' => 24, 'product_id' => 5945, 'vendor_id' => 2, 'quantity' => 1, 'transaction_id' => 'pi_1',
            'total_amount' => 7, 'refund_status' => 'approved', 'admin_amount' => 1, 'id' => 205,
            'product' => ['name' => 'WyvStudio Fe'], 'action' => 'SALE',
            'user' => ['id' => 24, 'name' => 'Ipn Buyer', 'email' => 'ipn@example.com'],
        ], $over));
    }

    private function hook(): \App\Models\BillingWebhookLog
    {
        return \App\Models\BillingWebhookLog::query()->latest('id')->firstOrFail();
    }

    public function test_an_ipn_sale_grants_the_product_and_a_redelivery_grants_nothing_twice(): void
    {
        $this->ipn()->assertOk()->assertJson(['success' => true]);
        $w = User::query()->where('email', 'ipn@example.com')->firstOrFail()->workspace;
        $this->assertSame(['launch_fe', 200], [$w->plan_tier, (int) $w->credits_topup], 'a SALE with refund_status set still grants');
        $this->assertSame(['launchpad', 'processed', 'pi_1', $w->id], [$this->hook()->provider, $this->hook()->outcome, $this->hook()->event_id, $this->hook()->workspace_id]);

        $this->ipn()->assertOk();
        $this->assertSame(200, (int) $w->fresh()->credits_topup);
        $this->assertSame('duplicate', $this->hook()->outcome);
        $this->assertSame(1, LaunchPurchase::query()->count());
        Mail::assertQueued(LaunchpadPurchaseMail::class, fn ($mail) => $mail->hasTo('ipn@example.com'));
        Mail::assertQueued(LaunchpadPurchaseMail::class, 1);
    }

    public function test_an_ipn_redelivery_retries_a_failed_access_email_without_duplicating_credits(): void
    {
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $this->ipn()->assertStatus(500);
        $w = User::query()->where('email', 'ipn@example.com')->firstOrFail()->workspace;
        $this->assertSame(0, DB::table('launch_access_emails')->count());
        $this->assertSame(200, (int) $w->credits_topup);

        Mail::swap($fake);
        $this->ipn()->assertOk();
        $this->ipn()->assertOk();
        Mail::assertQueued(LaunchpadPurchaseMail::class, 1);
        $this->assertSame(1, DB::table('launch_access_emails')->count());
        $this->assertSame(200, (int) $w->fresh()->credits_topup);
        $this->assertSame(1, LaunchPurchase::query()->count());
    }

    public function test_a_refunded_purchase_does_not_retry_access_email_on_sale_redelivery(): void
    {
        $this->ipn()->assertOk();
        $this->ipn(['action' => 'REFUND'])->assertOk();
        DB::table('launch_access_emails')->delete();
        Mail::fake();

        $this->ipn()->assertOk();
        Mail::assertNothingQueued();
        $this->assertSame(0, DB::table('launch_access_emails')->count());
    }

    public function test_payment_alone_sends_access_to_an_existing_buyer_even_if_the_workspace_was_welcomed(): void
    {
        $w = $this->account('ipn@example.com', 'appsumo_creator', 'appsumo');
        $owner = User::query()->create(['workspace_id' => $w->id, 'name' => 'Owner', 'email' => 'owner@example.com']);
        $w->forceFill(['owner_user_id' => $owner->id, 'welcome_email_sent_at' => now()->subMonth()])->save();

        // Only a server-to-server payment notification: no login or thank-you page visit.
        $this->ipn()->assertOk();
        Mail::assertQueued(LaunchpadPurchaseMail::class, fn ($mail) =>
            $mail->hasTo('ipn@example.com') && ! $mail->hasTo('owner@example.com')
            && $mail->purchaseEmail === 'ipn@example.com' && $mail->receipt === 'pi_1');
        Mail::assertQueuedCount(1);
        $this->assertSame('appsumo_creator', $w->fresh()->plan_tier);
    }

    public function test_a_receipt_with_a_bump_sends_one_access_email_and_an_upgrade_sends_its_own(): void
    {
        $this->ipn(['total_amount' => 24])->assertOk();
        $this->ipn(['total_amount' => 24])->assertOk();
        Mail::assertQueuedCount(1);
        $this->assertSame(2, LaunchPurchase::query()->count());
        $this->assertSame(1, DB::table('launch_access_emails')->count());

        $this->ipn(['product_id' => 5947, 'transaction_id' => 'pi_upgrade', 'total_amount' => 67])->assertOk();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(LaunchpadPurchaseMail::class, fn ($mail) =>
            $mail->receipt === 'pi_upgrade' && $mail->productName === 'WyvStudio Pro');
    }

    public function test_access_email_contains_a_lasting_sign_in_entry_point_and_purchase_email(): void
    {
        config(['app.frontend_url' => 'https://studio.example.test/']);
        $mail = new LaunchpadPurchaseMail('buyer@example.com', 'WyvStudio Pro', 'LP-123');
        $body = $mail->render();

        $this->assertStringContainsString('https://studio.example.test/login?source=launchpad', $body);
        $this->assertStringContainsString('buyer@example.com', $body);
        $this->assertStringContainsString('Send Magic Link', $body);
        $this->assertStringContainsString('even if you closed checkout', $body);
        $this->assertStringContainsString('https://special.wyvstudio.com/thank-you', $body);
        $this->assertStringContainsString('WyvStudio Pro', $body);
        $this->assertStringContainsString('LP-123', $body);
        $this->assertStringNotContainsString('/auth/magic?token=', $body);
        $this->assertSame(5, $mail->tries);
    }

    public function test_an_ipn_total_that_covers_the_bump_grants_it_too(): void
    {
        $this->ipn(['product_id' => 6942, 'total_amount' => 24]);
        $this->assertEqualsCanonicalizing(['fe', 'fe_credits'], LaunchPurchase::query()->pluck('product')->all());
        $this->assertSame(800, (int) User::query()->where('email', 'ipn@example.com')->firstOrFail()->workspace->credits_topup);
        $this->assertSame('7.00', (string) LaunchPurchase::query()->where('product', 'fe')->value('amount'));

        $this->ipn(['product_id' => 5946, 'transaction_id' => 'pi_2', 'total_amount' => 247]);
        $this->assertSame(0, LaunchPurchase::query()->where('product', 'bundle_dfy')->count(), 'no bump without its price');
        $this->ipn(['product_id' => 9999, 'transaction_id' => 'pi_2', 'total_amount' => 21, 'product' => ['name' => '20 More DFY Templates']]);
        $this->assertSame(1, LaunchPurchase::query()->where('product', 'bundle_dfy')->count(), 'a bump named as its own product');
    }

    public function test_an_ipn_refund_revokes_the_receipt(): void
    {
        $this->ipn(['product_id' => 5947, 'transaction_id' => 'pi_3', 'total_amount' => 86]);
        $this->assertSame(2, LaunchPurchase::query()->where('status', 'active')->count());
        $this->ipn(['product_id' => 5947, 'transaction_id' => 'pi_3', 'action' => 'REFUND'])->assertOk();
        $this->assertSame(['refunded', 'refunded'], LaunchPurchase::query()->pluck('status')->all());
        $this->assertSame('free', User::query()->where('email', 'ipn@example.com')->firstOrFail()->workspace->plan_tier);
        $this->ipn(['product_id' => 5947, 'transaction_id' => 'pi_3', 'action' => 'CHARGEBACK'])->assertOk();
        $this->assertSame('duplicate', $this->hook()->outcome);
    }

    public function test_an_ipn_with_a_wrong_or_unset_token_is_refused(): void
    {
        $this->ipn([], 'nope')->assertNotFound();
        config(['services.launchpad.ipn_token' => '']);
        $this->ipn([], '')->assertNotFound();
        $this->ipn()->assertNotFound();
        $this->assertSame(0, LaunchPurchase::query()->count());
        $this->assertSame('invalid_signature', $this->hook()->outcome);
    }

    public function test_offeriq_and_unknown_ipns_are_acknowledged_and_grant_nothing(): void
    {
        $this->ipn(['product_id' => 5948, 'total_amount' => 47])->assertOk();
        $this->assertSame('ignored', $this->hook()->outcome);
        $this->ipn(['product_id' => 1234, 'product' => ['name' => 'Something else']])->assertOk();
        $this->ipn(['action' => 'SUBSCRIPTION_RENEWED'])->assertOk();
        $this->assertSame(0, LaunchPurchase::query()->count());
        $this->assertSame(['ignored', 'ignored', 'ignored'], \App\Models\BillingWebhookLog::query()->pluck('outcome')->all());
    }

    public function test_a_failure_answers_500_so_launchpad_retries(): void
    {
        $this->ipn(['user' => ['email' => 'not-an-email']])->assertStatus(500);
        $this->assertSame('error', $this->hook()->outcome);
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
        foreach ([['fe', 'LP-2'], ['bundle', 'LP-3'], ['bundle_dfy', 'LP-3'], ['bundle', 'LP-3']] as [$p, $r]) {
            $this->launch()->grant('stack@example.com', null, $p, $r);
        }
        $w = User::query()->where('email', 'stack@example.com')->firstOrFail()->workspace;
        $this->assertSame('launch_bundle', $w->plan_tier);
        $this->assertSame(8200, (int) $w->credits_topup);
        $this->assertSame(3, LaunchPurchase::query()->count());
        $caps = CreditService::limitsFor($w);
        $this->assertSame([30, 90, 5, null, 3, 50, true], [$caps['sample_vault'], $caps['max_duration_seconds'], $caps['max_characters'], $caps['max_channels'], $caps['batch_variations'], $caps['max_client_workspaces'], $caps['series']]);
        $this->assertSame(5, WorkspaceUsageService::planFor($w)['voice_cloning_limit']);
    }

    public function test_bumps_add_to_the_products_own_allowance(): void
    {
        $this->launch()->grant('pro@example.com', null, 'pro', 'LP-4');
        $this->launch()->grant('pro@example.com', null, 'pro_seats', 'LP-4');
        $w = User::query()->where('email', 'pro@example.com')->firstOrFail()->workspace;
        $this->assertSame([4, 10], [CreditService::limitsFor($w)['max_characters'], CreditService::limitsFor($w)['sample_vault']]);
        $this->assertSame(4, WorkspaceUsageService::planFor($w)['voice_cloning_limit']);
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
        $this->assertSame([5, 5, null, true], [$caps['max_characters'], $caps['max_brand_kits'], $caps['max_channels'], $caps['series']]);

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
        $this->launch()->grant('agency@example.com', null, 'agency_credits', 'LP-10');
        $agency = User::query()->where('email', 'agency@example.com')->firstOrFail()->workspace;
        $client = Workspace::query()->create(['name' => 'Client']);
        $client->forceFill(['parent_workspace_id' => $agency->id, 'plan_tier' => 'free'])->save();
        $caps = CreditService::limitsFor($client->fresh());
        $this->assertSame([30, true, true, true, null], [$caps['max_client_workspaces'], $caps['api_access'], $caps['ugc_ads'], $caps['reference_style'], $caps['sample_vault']]);
        $this->assertSame(5000, (int) $agency->fresh()->credits_topup);
    }

    public function test_the_new_gates_refuse_only_what_a_launch_plan_leaves_out(): void
    {
        $fe = $this->launch()->grant('gate-fe@example.com', null, 'fe', 'LP-11')['purchase']->workspace_id;
        $ltd = $this->account('gate-ltd@example.com', 'appsumo_starter', 'appsumo')->id;
        foreach (['create', 'reference_style', 'series'] as $gate) {
            $this->assertFalse(\App\Support\PlanGate::allows($fe, $gate), $gate);
            $this->assertTrue(\App\Support\PlanGate::allows($ltd, $gate), $gate);
        }
        $user = fn (int $ws) => (new User)->forceFill(['workspace_id' => $ws]);
        $this->assertSame([], \App\Services\Create\SampleLibrary::catalogueFor($user($fe)));
        $this->assertCount(count(\App\Services\Create\SampleLibrary::SAMPLES), \App\Services\Create\SampleLibrary::catalogueFor($user($ltd)));
        $this->launch()->grant('gate-fe@example.com', null, 'bundle', 'LP-12');
        $this->assertCount(10, \App\Services\Create\SampleLibrary::catalogueFor($user($fe)));

        try {
            \App\Support\PlanGate::require($ltd, 'series');
            \App\Support\PlanGate::require($this->launch()->grant('gate-pro@example.com', null, 'pro', 'LP-13')['purchase']->workspace_id, 'series');
            $this->fail('Pro is sold without Series');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(402, $e->getResponse()->getStatusCode());
            $this->assertSame('series', $e->getResponse()->getData(true)['error']['feature']);
        }
    }

    public function test_client_workspaces_and_priority_follow_the_launch_plan_and_nothing_else_changes(): void
    {
        $agency = Workspace::query()->findOrFail($this->launch()->grant('own@example.com', null, 'agency', 'LP-14')['purchase']->workspace_id);
        $this->assertSame([true, 30], [$agency->canOwnClients(), $agency->maxClients()]);
        $this->assertSame('exports-priority', CreditService::exportQueueFor($agency->plan_tier));
        $pro = Workspace::query()->findOrFail($this->launch()->grant('own-pro@example.com', null, 'pro', 'LP-15')['purchase']->workspace_id);
        $this->assertSame([false, 'exports'], [$pro->canOwnClients(), CreditService::exportQueueFor($pro->plan_tier)]);

        foreach (['appsumo_agency' => true, 'agency' => true, 'appsumo_starter' => false, 'creator' => false] as $tier => $can) {
            $w = $this->account("own-{$tier}@example.com", $tier, 'kelviq');
            $this->assertSame([$can, (int) config('workspaces.max_clients', 50)], [$w->canOwnClients(), $w->maxClients()], $tier);
        }
        $ltd = $this->account('own-ltd@example.com', 'appsumo_creator', 'appsumo');
        $this->launch()->grant('own-ltd@example.com', null, 'bundle', 'LP-16');
        $ltd->refresh();
        $this->assertTrue($ltd->canOwnClients());
        $this->assertSame('exports-priority', CreditService::exportQueueFor($ltd->plan_tier, $ltd->plan_addons));
        $this->assertSame('exports', CreditService::exportQueueFor('appsumo_creator'));
    }
}
