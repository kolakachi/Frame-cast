<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\Workspace;
use App\Services\Affiliate\AffiliateAttribution;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateAttributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'aff_test', 'database.connections.aff_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('aff_test');

        Schema::create('affiliates', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->string('email')->nullable();
            $t->decimal('commission_percent', 5, 2)->default(20); $t->string('status')->default('active');
            $t->text('notes')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_conversions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('customer_email')->nullable(); $t->string('order_id')->nullable(); $t->string('plan')->nullable();
            $t->decimal('order_amount', 10, 2)->default(0); $t->string('currency')->default('USD');
            $t->decimal('gross_amount', 10, 2)->default(0); $t->decimal('basis_amount', 10, 2)->default(0);
            $t->string('basis_method')->default('gross');
            $t->decimal('commission_percent', 5, 2); $t->decimal('commission_amount', 10, 2);
            $t->string('attribution_source')->default('workspace'); $t->string('payout_status')->default('unpaid');
            $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('affiliate_code')->nullable();
            $t->timestamp('affiliate_attributed_at')->nullable(); $t->timestamps();
        });
    }

    private function affiliate(array $o = []): Affiliate
    {
        return Affiliate::query()->create(array_merge(
            ['code' => 'marcus', 'name' => 'Marcus', 'commission_percent' => 30, 'status' => 'active'], $o,
        ));
    }

    public function test_the_basis_settings_are_readable_at_the_path_the_code_asks_for(): void
    {
        // Deliberately reads the shipped config rather than injecting a value.
        // The key sat one level too deep for a while: every other test passed,
        // because config([...]) creates the key it writes, while production
        // resolved null, fell back to the defaults, and paid commission on the
        // full gross with each row stamped "net".
        $cfg = config('billing.affiliate_basis');

        $this->assertIsArray($cfg, 'billing.affiliate_basis must resolve — the code reads it at this exact path.');
        foreach (['method', 'tax_rate_estimate', 'platform_fee_percent'] as $key) {
            $this->assertArrayHasKey($key, $cfg);
        }
        $this->assertContains($cfg['method'], ['gross', 'ex_tax', 'net']);

        // And the deduction actually happens on a net basis.
        if ($cfg['method'] === 'net' && $cfg['platform_fee_percent'] > 0) {
            [$basis] = AffiliateAttribution::commissionBasis(100.0);
            $this->assertLessThan(100.0, $basis, 'A net basis that equals gross means nothing is being deducted.');
        }
    }

    public function test_a_sale_with_no_account_is_still_attributed_from_checkout_metadata(): void
    {
        // The direct-purchase path: no registration ever happened, so the only
        // surviving link is what travelled with the order.
        $a = $this->affiliate();
        [$found, $source] = app(AffiliateAttribution::class)
            ->resolveForSale(['affiliate_code' => 'marcus'], null, null);

        $this->assertSame($a->id, $found?->id);
        $this->assertSame('checkout_metadata', $source);
    }

    public function test_a_sale_is_attributed_from_the_workspace_when_metadata_is_absent(): void
    {
        $a = $this->affiliate();
        $ws = Workspace::query()->create(['name' => 'W', 'affiliate_code' => 'marcus']);

        [$found, $source] = app(AffiliateAttribution::class)->resolveForSale([], $ws, null);

        $this->assertSame($a->id, $found?->id);
        $this->assertSame('workspace', $source);
    }

    public function test_the_rate_is_frozen_at_the_time_of_sale(): void
    {
        $a = $this->affiliate(['commission_percent' => 30]);
        $attribution = app(AffiliateAttribution::class);

        $attribution->recordConversion($a, 'workspace', null, 'buyer@example.com', 'ORD-1', 'lifetime_creator', 199.00);

        // Renegotiating must not restate what is already owed.
        $a->forceFill(['commission_percent' => 10])->save();

        $row = AffiliateConversion::query()->where('order_id', 'ORD-1')->firstOrFail();
        $this->assertSame('30.00', (string) $row->commission_percent);
        // On the basis, not the gross — the gross includes tax.
        $this->assertSame('199.00', (string) $row->gross_amount);
        $this->assertEqualsWithDelta(
            (float) $row->basis_amount * 0.30,
            (float) $row->commission_amount,
            0.01,
        );
    }

    public function test_a_repeated_webhook_cannot_pay_twice(): void
    {
        $a = $this->affiliate();
        $attribution = app(AffiliateAttribution::class);

        $first = $attribution->recordConversion($a, 'workspace', null, 'b@e.com', 'ORD-2', 'x', 99.0);
        $again = $attribution->recordConversion($a, 'workspace', null, 'b@e.com', 'ORD-2', 'x', 99.0);

        $this->assertNotNull($first);
        $this->assertNull($again);
        $this->assertSame(1, AffiliateConversion::query()->where('order_id', 'ORD-2')->count());
    }

    public function test_a_customer_already_earned_is_not_moved_by_a_later_link(): void
    {
        $this->affiliate(['code' => 'first', 'name' => 'First']);
        $this->affiliate(['code' => 'second', 'name' => 'Second']);
        $attribution = app(AffiliateAttribution::class);
        $ws = Workspace::query()->create(['name' => 'W']);

        $attribution->attributeWorkspace($ws, 'first');
        $attribution->attributeWorkspace($ws->fresh(), 'second');

        $this->assertSame('first', $ws->fresh()->affiliate_code);
    }

    public function test_an_unknown_or_paused_affiliate_earns_nothing(): void
    {
        $this->affiliate(['code' => 'dormant', 'status' => 'paused']);
        $attribution = app(AffiliateAttribution::class);

        [$none] = $attribution->resolveForSale(['affiliate_code' => 'dormant'], null, null);
        $this->assertNull($none, 'a paused affiliate must not earn');

        [$nobody] = $attribution->resolveForSale(['affiliate_code' => 'nobody'], null, null);
        $this->assertNull($nobody);
    }

    public function test_commission_is_not_taken_on_tax_or_the_platform_fee(): void
    {
        // Kelviq reports one gross figure with tax included. A real order:
        // $199.00 less a $29.85 discount plus $33.83 of tax = $202.98.
        config(['billing.affiliate_basis' => [
            'method' => 'net', 'tax_rate_estimate' => 0.20, 'platform_fee_percent' => 5.0,
        ]]);

        [$basis, $method] = AffiliateAttribution::commissionBasis(202.98);

        $this->assertSame('net', $method);
        $this->assertLessThan(202.98, $basis, 'tax and fees must come out first');
        $this->assertEqualsWithDelta(160.69, $basis, 0.05, '202.98 ex 20% tax, less a 5% fee');

        // What the mistake was worth on one order.
        $this->assertEqualsWithDelta(12.69, (202.98 - $basis) * 0.30, 0.05);
    }

    public function test_the_basis_can_be_set_to_gross_where_that_is_the_deal(): void
    {
        config(['billing.affiliate_basis' => ['method' => 'gross']]);

        [$basis, $method] = AffiliateAttribution::commissionBasis(202.98);

        $this->assertSame(202.98, $basis);
        $this->assertSame('gross', $method);
    }

    public function test_the_providers_own_figures_remove_the_tax_guess(): void
    {
        config(['billing.affiliate_basis' => ['method' => 'net', 'tax_rate_estimate' => 0.20, 'platform_fee_percent' => 5.52]]);
        $a = $this->affiliate(['commission_percent' => 30]);

        // Michael's real order, as the orders API reports it.
        app(AffiliateAttribution::class)->recordConversion(
            $a, 'checkout_metadata', null, 'buyer@example.com', 'ORD-REAL', 'lifetime_creator',
            202.98, 'USD',
            ['subtotal' => 199.00, 'discount' => 29.85, 'tax' => 33.83, 'total' => 202.98, 'refunded' => 0.0],
        );

        $row = AffiliateConversion::query()->where('order_id', 'ORD-REAL')->firstOrFail();
        $this->assertSame('provider_net', $row->basis_method);
        $this->assertSame('202.98', (string) $row->gross_amount);
        // 199.00 - 29.85 = 169.15, less Kelviq's 5.52% = 159.81 — the figure
        // the payout screen actually shows.
        $this->assertEqualsWithDelta(159.81, (float) $row->basis_amount, 0.02);
        $this->assertEqualsWithDelta(47.94, (float) $row->commission_amount, 0.02);
    }

    public function test_a_refunded_sale_owes_nothing(): void
    {
        $a = $this->affiliate();

        app(AffiliateAttribution::class)->recordConversion(
            $a, 'workspace', null, 'buyer@example.com', 'ORD-REFUND', 'lifetime_creator',
            202.98, 'USD',
            ['subtotal' => 199.00, 'discount' => 0.0, 'tax' => 33.83, 'total' => 202.98, 'refunded' => 202.98],
        );

        $row = AffiliateConversion::query()->where('order_id', 'ORD-REFUND')->firstOrFail();
        $this->assertSame('void', $row->payout_status, 'a reversed sale must not be owed');
    }
}
