<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\EconomicsController;
use App\Models\Workspace;
use App\Services\Billing\RevenueService;
use App\Services\KelviqService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Mockery;
use Tests\TestCase;

/** Money in and customer economics (owner, 2026-10-11, cost tracking steps 2 and 3). */
class CustomerEconomicsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'econ_test', 'database.connections.econ_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('econ_test');
        (require database_path('migrations/2026_10_11_200000_create_revenue_events.php'))->up();
        $tables = [
            'workspaces' => ['name', 'plan_tier', 'parent_workspace_id'], 'users' => ['workspace_id', 'email'],
            'credit_ledger' => ['workspace_id', 'operation', 'credits', 'upstream_cost_usd'], 'api_usage_events' => ['workspace_id', 'estimated_cost_usd', 'occurred_at'],
            'appsumo_licenses' => ['license_key', 'workspace_id', 'tier', 'appsumo_tier', 'status'], 'affiliate_conversions' => ['order_id'],
            'billing_checkout_attempts' => ['plan'], 'billing_webhook_logs' => ['provider', 'event', 'signature_valid', 'payload'],
        ];
        foreach ($tables as $t => $cols) Schema::create($t, function (Blueprint $b) use ($t, $cols) {
            $t === 'billing_checkout_attempts' ? $b->string('id')->primary() : $b->id();
            foreach ($cols as $c) $b->text($c)->nullable();
            $b->timestamps();
        });
        DB::table('workspaces')->insert([['id' => 28, 'name' => 'Davids Studio', 'plan_tier' => 'lifetime_creator'], ['id' => 27, 'name' => 'Sumo Shop', 'plan_tier' => 'appsumo_creator'], ['id' => 1, 'name' => 'Test', 'plan_tier' => 'agency']]);
        DB::table('users')->insert([['workspace_id' => 28, 'email' => 'a@x.com'], ['workspace_id' => 27, 'email' => 'b@x.com']]);
        DB::table('billing_checkout_attempts')->insert(['id' => 'att-1', 'plan' => 'lifetime_creator']);
    }

    private function kelviq(?array $breakdown): void
    {
        $k = Mockery::mock(KelviqService::class);
        $k->shouldReceive('workspaceFor')->andReturn((new Workspace)->forceFill(['id' => 28])->setAttribute('id', 28));
        $k->shouldReceive('fetchOrderBreakdown')->andReturn($breakdown);
        $this->app->instance(KelviqService::class, $k);
    }

    public function test_a_kelviq_sale_is_recorded_once_from_its_real_breakdown(): void
    {
        $this->kelviq(['subtotal' => 199.0, 'discount' => 20.0, 'tax' => 35.8, 'total' => 214.8, 'refunded' => 0.0]);
        $event = ['id' => 'evt_1', 'type' => 'checkout.completed', 'data' => ['object' => ['id' => 'chk_1', 'order_id' => 'ord_1', 'amount' => 214.8, 'currency' => 'USD',
            'plan' => ['identifier' => 'creator-lt'], 'metadata' => ['checkout_attempt_id' => 'att-1']]]];
        app(RevenueService::class)->fromKelviqEvent($event);
        app(RevenueService::class)->fromKelviqEvent($event);
        $rows = DB::table('revenue_events')->get();
        $this->assertCount(1, $rows, 'a replay lands on the same row');
        $r = $rows[0];
        $this->assertSame([28, 'kelviq', 'ord_1', 'lifetime_creator', 'direct', 179.0, 35.8], [(int) $r->workspace_id, $r->provider, $r->external_id, $r->plan, $r->channel, (float) $r->net_usd, (float) $r->tax]);
    }

    public function test_the_invoice_that_follows_a_checkout_is_not_a_renewal_but_a_second_one_is(): void
    {
        $this->kelviq(null);
        $invoice = fn (string $id) => ['id' => 'evt_'.$id, 'type' => 'invoice.paid', 'created_at' => now()->toIso8601String(),
            'data' => ['object' => ['id' => $id, 'subscription_id' => 'sub-1', 'amount' => 10.8, 'currency' => 'USD']]];
        // The first invoice of a one-time purchase: its webhook is stored as it arrives, and it is not money in twice.
        DB::table('billing_webhook_logs')->insert(['provider' => 'kelviq', 'event' => 'invoice.paid', 'signature_valid' => true, 'payload' => json_encode($invoice('INV-1')), 'created_at' => now()]);
        app(RevenueService::class)->fromKelviqEvent($invoice('INV-1'));
        $this->assertSame(0, DB::table('revenue_events')->count());
        // A month later the same subscription is invoiced again: that is a renewal.
        $this->travel(31)->days();
        app(RevenueService::class)->fromKelviqEvent($invoice('INV-2'));
        $this->assertSame(['renewal', 'invoice:INV-2'], [DB::table('revenue_events')->value('kind'), DB::table('revenue_events')->value('external_id')]);
    }

    public function test_an_appsumo_licence_keeps_a_quarter_and_a_deactivation_is_a_refund(): void
    {
        $this->kelviq(null);
        DB::table('appsumo_licenses')->insert(['id' => 5, 'license_key' => 'k1', 'workspace_id' => 27, 'tier' => 'appsumo_creator', 'appsumo_tier' => 2, 'status' => 'active', 'created_at' => '2026-09-01 10:00:00']);
        app(RevenueService::class)->sync();
        $r = DB::table('revenue_events')->first();
        $this->assertSame([147.0, 110.25, 0.0, 'appsumo'], [(float) $r->net_usd, (float) $r->fee_usd, (float) $r->refunded_usd, $r->channel]);
        DB::table('appsumo_licenses')->where('id', 5)->update(['status' => 'deactivated']);
        app(RevenueService::class)->sync();
        $this->assertSame(36.75, (float) DB::table('revenue_events')->value('refunded_usd'), 'what reached us goes back');
        // Bought during the 15% campaign: $124.95.
        DB::table('appsumo_licenses')->insert(['id' => 6, 'license_key' => 'k2', 'workspace_id' => null, 'tier' => 'appsumo_creator', 'appsumo_tier' => 2, 'status' => 'active', 'created_at' => '2026-10-20 12:00:00']);
        app(RevenueService::class)->sync();
        $this->assertSame([124.95, 22.05], [(float) DB::table('revenue_events')->where('external_id', 'license:6')->value('net_usd'), (float) DB::table('revenue_events')->where('external_id', 'license:6')->value('discount')]);
        $this->assertSame(2, DB::table('revenue_events')->count());
    }

    public function test_economics_sets_money_in_against_cost_per_customer(): void
    {
        $this->kelviq(null);
        DB::table('revenue_events')->insert([
            ['workspace_id' => 28, 'provider' => 'kelviq', 'external_id' => 'o1', 'kind' => 'pack', 'channel' => 'direct', 'currency' => 'USD', 'net' => 199, 'net_usd' => 199, 'fee_usd' => 0, 'refunded_usd' => 0, 'occurred_at' => now()],
            ['workspace_id' => 27, 'provider' => 'appsumo', 'external_id' => 'license:5', 'kind' => 'appsumo', 'channel' => 'appsumo', 'currency' => 'USD', 'net' => 139, 'net_usd' => 139, 'fee_usd' => 104.25, 'refunded_usd' => 0, 'occurred_at' => now()],
        ]);
        DB::table('revenue_events')->insert(['workspace_id' => null, 'provider' => 'appsumo', 'external_id' => 'license:9', 'kind' => 'appsumo', 'channel' => 'appsumo', 'currency' => 'USD', 'net' => 49, 'net_usd' => 49, 'fee_usd' => 36.75, 'refunded_usd' => 0, 'occurred_at' => now()]);
        DB::table('api_usage_events')->insert([['workspace_id' => 28, 'estimated_cost_usd' => 12.5, 'occurred_at' => now()], ['workspace_id' => 27, 'estimated_cost_usd' => 40, 'occurred_at' => now()], ['workspace_id' => null, 'estimated_cost_usd' => 3, 'occurred_at' => now()]]);
        DB::table('credit_ledger')->insert([['workspace_id' => 28, 'operation' => 'grant:lifetime_kelviq', 'credits' => -12000, 'upstream_cost_usd' => null, 'created_at' => now()],
            ['workspace_id' => 28, 'operation' => 'ugc_oneshot', 'credits' => 330, 'upstream_cost_usd' => 2.0, 'created_at' => '2026-10-01 10:00:00']]);
        $d = app(EconomicsController::class)->index(Request::create('/admin/economics'))->getData(true)['data'];
        $byId = collect($d['rows'])->keyBy('workspace_id');
        $this->assertSame([199.0, 12.5, 2.0, 184.5, 12000, 330], [(float) $byId[28]['money_in_usd'], (float) $byId[28]['cost_recorded_usd'], (float) $byId[28]['cost_earlier_estimate_usd'], (float) $byId[28]['margin_usd'], $byId[28]['credits_granted'], $byId[28]['credits_used']]);
        $this->assertSame(-5.25, (float) $byId[27]['margin_usd'], 'an AppSumo buyer who used $40 of video on a $34.75 share loses money');
        $this->assertSame(3.0, (float) $d['unattributed_cost_usd']);
        $this->assertFalse($byId->has(1), 'paying customers only by default');
        $this->assertSame(12.25, (float) $byId[0]['money_in_usd'], 'an unredeemed licence still reached us');
        $this->assertSame((float) collect($d['by_channel'])->sum('money_in_usd'), (float) $d['totals']['money_in_usd'], 'the two totals agree');
    }
}
