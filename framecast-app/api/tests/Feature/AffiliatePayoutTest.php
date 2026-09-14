<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\AffiliateController;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayout;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class AffiliatePayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pay_test', 'database.connections.pay_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('pay_test');

        Schema::create('affiliates', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->string('email')->nullable();
            $t->decimal('commission_percent', 5, 2)->default(20); $t->string('status')->default('active');
            $t->text('notes')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_clicks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->timestamp('clicked_at')->nullable();
            $t->string('landing_path')->nullable(); $t->string('referer')->nullable();
            $t->string('visitor_hash')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_conversions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('customer_email')->nullable(); $t->string('order_id')->nullable(); $t->string('plan')->nullable();
            $t->decimal('order_amount', 10, 2)->default(0); $t->string('currency')->default('USD');
            $t->decimal('gross_amount', 10, 2)->default(0); $t->decimal('basis_amount', 10, 2)->default(0);
            $t->string('basis_method')->default('gross');
            $t->decimal('commission_percent', 5, 2); $t->decimal('commission_amount', 10, 2);
            $t->string('attribution_source')->default('workspace'); $t->string('payout_status')->default('unpaid');
            $t->unsignedBigInteger('payout_id')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        Schema::create('affiliate_payouts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->string('reference');
            $t->date('period_start')->nullable(); $t->date('period_end')->nullable();
            $t->unsignedInteger('sales_count')->default(0); $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('currency')->default('USD'); $t->string('status')->default('paid');
            $t->string('method')->nullable(); $t->text('note')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable(); $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });
    }

    private function affiliate(): Affiliate
    {
        return Affiliate::query()->create(['code' => 'marcus', 'name' => 'Marcus', 'commission_percent' => 30, 'status' => 'active']);
    }

    private function sale(Affiliate $a, float $commission, array $o = []): AffiliateConversion
    {
        return AffiliateConversion::query()->create(array_merge([
            'affiliate_id' => $a->getKey(), 'order_id' => 'ord_'.bin2hex(random_bytes(4)),
            'order_amount' => $commission / 0.3, 'currency' => 'USD',
            'commission_percent' => 30, 'commission_amount' => $commission,
        ], $o));
    }

    private function request(array $body = []): Request
    {
        $request = Request::create('/payouts', 'POST', $body);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 1]));

        return $request;
    }

    public function test_paying_moves_only_what_existed_at_the_time_and_leaves_later_sales_outstanding(): void
    {
        // The failure this whole design exists to prevent: a sale arriving
        // after a statement is cut must not be settled by it.
        $a = $this->affiliate();
        $this->sale($a, 30.00);
        $this->sale($a, 15.00);

        $controller = new AffiliateController;
        $response = $controller->createPayout($this->request(['method' => 'wise']), $a->getKey());
        $this->assertSame(201, $response->status());

        $payout = AffiliatePayout::query()->firstOrFail();
        $this->assertSame(2, (int) $payout->sales_count);
        $this->assertEqualsWithDelta(45.00, (float) $payout->total_amount, 0.001);

        $late = $this->sale($a, 60.00);

        $row = collect($controller->index()->getData(true)['data']['affiliates'])->firstOrFail();
        $this->assertEqualsWithDelta(60.00, $row['owed'], 0.001);    // only the late sale
        $this->assertEqualsWithDelta(45.00, $row['paid'], 0.001);    // the run, unchanged
        $this->assertSame('MARCUS-'.now()->year.'-001', $row['last_payout']['reference']);
        $this->assertNull($late->fresh()->payout_id);
    }

    public function test_a_second_run_covers_only_what_accrued_since_the_first(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 30.00);
        $controller = new AffiliateController;
        $controller->createPayout($this->request(), $a->getKey());

        $this->sale($a, 12.50);
        $controller->createPayout($this->request(), $a->getKey());

        $runs = collect($controller->payouts($a->getKey())->getData(true)['data']['payouts']);
        $this->assertCount(2, $runs);
        $this->assertEqualsWithDelta(12.50, $runs->firstWhere('reference', 'MARCUS-'.now()->year.'-002')['total_amount'], 0.001);
        $this->assertEqualsWithDelta(30.00, $runs->firstWhere('reference', 'MARCUS-'.now()->year.'-001')['total_amount'], 0.001);
        $this->assertEqualsWithDelta(0.0, collect($controller->index()->getData(true)['data']['affiliates'])->firstOrFail()['owed'], 0.001);
    }

    public function test_each_sale_names_the_payment_that_settled_it(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 30.00);
        $controller = new AffiliateController;
        $controller->createPayout($this->request(), $a->getKey());
        $this->sale($a, 9.00);

        $rows = collect($controller->conversions(Request::create('/c'), $a->getKey())->getData(true)['data']['conversions']);
        $this->assertSame('MARCUS-'.now()->year.'-001', $rows->firstWhere('commission_amount', 30.0)['payout_reference']);
        $this->assertNull($rows->firstWhere('commission_amount', 9.0)['payout_reference']);
    }

    public function test_nothing_outstanding_is_refused_rather_than_recorded_as_an_empty_payment(): void
    {
        $a = $this->affiliate();
        $response = (new AffiliateController)->createPayout($this->request(), $a->getKey());
        $this->assertSame(422, $response->status());
        $this->assertSame(0, AffiliatePayout::query()->count());
    }

    public function test_mixed_currencies_are_refused_instead_of_summed(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 30.00);
        $this->sale($a, 25.00, ['currency' => 'EUR']);

        $response = (new AffiliateController)->createPayout($this->request(), $a->getKey());
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('EUR', $response->getData(true)['error']['message']);
        $this->assertSame(0, AffiliatePayout::query()->count());
    }

    public function test_a_cut_off_date_leaves_newer_sales_for_the_next_run(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 30.00)->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->sale($a, 80.00)->forceFill(['created_at' => now()->subDay()])->save();

        $controller = new AffiliateController;
        $controller->createPayout($this->request(['up_to' => now()->subDays(5)->toDateString()]), $a->getKey());

        $this->assertEqualsWithDelta(30.00, (float) AffiliatePayout::query()->firstOrFail()->total_amount, 0.001);
        $this->assertEqualsWithDelta(80.00, collect($controller->index()->getData(true)['data']['affiliates'])->firstOrFail()['owed'], 0.001);
    }

    public function test_voiding_returns_the_sales_to_outstanding_and_never_reissues_the_reference(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 30.00);
        $controller = new AffiliateController;
        $controller->createPayout($this->request(), $a->getKey());
        $payout = AffiliatePayout::query()->firstOrFail();

        $controller->voidPayout(Request::create('/void', 'POST', ['reason' => 'Transfer bounced']), $a->getKey(), $payout->getKey());

        $row = collect($controller->index()->getData(true)['data']['affiliates'])->firstOrFail();
        $this->assertEqualsWithDelta(30.00, $row['owed'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['paid'], 0.001);
        $this->assertNull($row['last_payout']);   // a void run is not the last payment

        // Re-paying issues a new reference; the voided one is spent.
        $controller->createPayout($this->request(), $a->getKey());
        $this->assertSame(
            ['MARCUS-'.now()->year.'-001', 'MARCUS-'.now()->year.'-002'],
            AffiliatePayout::query()->orderBy('id')->pluck('reference')->all(),
        );
    }

    public function test_a_statement_keeps_naming_its_own_sales_after_later_ones_arrive(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 30.00, ['order_id' => 'ord_first']);
        $controller = new AffiliateController;
        $controller->createPayout($this->request(), $a->getKey());
        $payout = AffiliatePayout::query()->firstOrFail();

        $this->sale($a, 99.00, ['order_id' => 'ord_later']);

        ob_start();
        $controller->statement(Request::create('/s'), $a->getKey(), $payout->getKey())->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('ord_first', $csv);
        $this->assertStringNotContainsString('ord_later', $csv);
        $this->assertStringContainsString('MARCUS-'.now()->year.'-001', $csv);
        $this->assertStringContainsString('30.00', $csv);
    }
}
