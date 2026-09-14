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
        $this->assertSame('59.70', (string) $row->commission_amount);
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
}
