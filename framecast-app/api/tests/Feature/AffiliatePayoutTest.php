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
use Tests\Support\BuildsAffiliateSchema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class AffiliatePayoutTest extends TestCase
{
    use BuildsAffiliateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAffiliateSchema('pay_test');
    }

    private function affiliate(): Affiliate
    {
        $a = Affiliate::query()->create(['code' => 'marcus', 'name' => 'Marcus', 'commission_percent' => 30, 'status' => 'active']);
        $this->seedPaymentDetails((int) $a->getKey());

        return $a;
    }

    /** Defaults to a matured sale — this suite is about the ledger, not the hold. */
    private function sale(Affiliate $a, float $commission, array $o = []): AffiliateConversion
    {
        return AffiliateConversion::query()->create(array_merge([
            'eligible_at' => now()->subDay(),
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

    public function test_a_generated_code_carries_nothing_about_the_affiliate(): void
    {
        $request = Request::create('/affiliates', 'POST', [
            'name' => 'Marcus Ilunga', 'email' => 'marcus@growthloop.io', 'commission_percent' => 30,
        ]);
        $code = (new AffiliateController)->store($request)->getData(true)['data']['affiliate']['code'];

        $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{8}$/', $code);
        foreach (['marcus', 'ilunga', 'growthloop'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $code);
        }
        // Look-alikes are excluded because these get read aloud and retyped.
        $this->assertSame(0, preg_match('/[01ilo]/', $code));
    }

    public function test_two_affiliates_sharing_a_name_get_distinct_references(): void
    {
        $a = Affiliate::query()->create(['code' => Affiliate::generateCode(), 'name' => 'Marcus', 'commission_percent' => 30, 'status' => 'active']);
        $b = Affiliate::query()->create(['code' => Affiliate::generateCode(), 'name' => 'Marcus', 'commission_percent' => 30, 'status' => 'active']);
        $this->seedPaymentDetails((int) $a->getKey());
        $this->seedPaymentDetails((int) $b->getKey());
        $this->sale($a, 10.00);
        $this->sale($b, 10.00);

        $controller = new AffiliateController;
        $controller->createPayout($this->request(), $a->getKey());
        $controller->createPayout($this->request(), $b->getKey());

        $references = AffiliatePayout::query()->orderBy('id')->pluck('reference')->all();
        $this->assertCount(2, array_unique($references));
        $this->assertStringStartsWith('MARCUS-', $references[0]);
    }

    public function test_generated_codes_do_not_repeat(): void
    {
        $codes = [];
        for ($i = 0; $i < 60; $i++) {
            $codes[] = Affiliate::generateCode();
            Affiliate::query()->create(['code' => end($codes), 'name' => 'A'.$i, 'commission_percent' => 20, 'status' => 'active']);
        }
        $this->assertCount(60, array_unique($codes));
    }

    public function test_a_requested_code_is_still_honoured_but_normalised(): void
    {
        // Kept for a negotiated vanity link; the case is flattened so it cannot
        // collide with an existing code that only differs by case.
        $request = Request::create('/affiliates', 'POST', [
            'name' => 'Dana', 'code' => 'TheBrief', 'commission_percent' => 25,
        ]);
        $this->assertSame('thebrief', (new AffiliateController)->store($request)->getData(true)['data']['affiliate']['code']);

        $clash = Request::create('/affiliates', 'POST', ['name' => 'Other', 'code' => 'THEBRIEF', 'commission_percent' => 25]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new AffiliateController)->store($clash);
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

    public function test_the_statement_says_whose_fee_was_deducted(): void
    {
        // An unnamed "platform fee" reads, to the person being paid, as the
        // platform paying them keeping a slice.
        $a = $this->affiliate();
        $this->sale($a, 21.14);

        ob_start();
        (new AffiliateController)->statement(Request::create('/s', 'GET', ['unpaid_only' => 1]), $a->getKey())->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Kelviq', $csv);
        $this->assertStringContainsString('merchant of record', $csv);
        $this->assertStringContainsString('WyvStudio deducts nothing of its own', $csv);
        $this->assertStringNotContainsString('a platform fee of', $csv);
    }

    public function test_a_deduction_of_zero_is_not_listed_as_a_deduction(): void
    {
        // Naming a 0% subtraction describes something that did not happen, and
        // invites exactly the question the footer exists to answer.
        config(['billing.affiliate_basis' => ['method' => 'net', 'tax_rate_estimate' => 0.0, 'platform_fee_percent' => 0.0]]);
        $a = $this->affiliate();
        $this->sale($a, 21.14, ['basis_method' => 'net']);

        $csv = $this->statementFor($a);

        $this->assertStringContainsString('Nothing is deducted', $csv);
        $this->assertStringNotContainsString('0%', $csv);
    }

    public function test_on_a_gross_basis_the_statement_promises_no_deduction(): void
    {
        config(['billing.affiliate_basis' => ['method' => 'gross', 'tax_rate_estimate' => 0.2, 'platform_fee_percent' => 5.5]]);
        $a = $this->affiliate();
        $this->sale($a, 26.70, ['basis_method' => 'gross']);

        $csv = $this->statementFor($a);

        $this->assertStringContainsString('Nothing is deducted', $csv);
        $this->assertStringNotContainsString('processing fee', $csv);
    }

    public function test_only_the_deductions_actually_taken_are_named(): void
    {
        config(['billing.affiliate_basis' => ['method' => 'net', 'tax_rate_estimate' => 0.0, 'platform_fee_percent' => 5.52]]);
        $a = $this->affiliate();
        $this->sale($a, 21.14, ['basis_method' => 'net']);

        $csv = $this->statementFor($a);

        $this->assertStringContainsString('5.5%', $csv);
        $this->assertStringNotContainsString('sales tax', $csv);
    }

    private function statementFor(Affiliate $a): string
    {
        ob_start();
        (new AffiliateController)->statement(Request::create('/s', 'GET', ['unpaid_only' => 1]), $a->getKey())->sendContent();

        return (string) ob_get_clean();
    }

    public function test_an_ex_tax_statement_is_the_one_with_no_estimate_in_it(): void
    {
        // Kelviq reports tax per order, so this basis is entirely theirs.
        $a = $this->affiliate();
        $this->sale($a, 21.14, ['basis_method' => 'provider_ex_tax']);

        $csv = $this->statementFor($a);

        $this->assertStringContainsString('not estimated', $csv);
        $this->assertStringNotContainsString('estimated at', $csv);
        $this->assertStringNotContainsString('processing fee', $csv);
    }

    public function test_a_net_statement_admits_the_fee_is_our_estimate(): void
    {
        // Kelviq's order API does not report what Kelviq keeps. Presenting that
        // number as though it came from them would be the dishonest part —
        // deducting a real cost is not.
        config(['billing.affiliate_basis' => ['method' => 'net', 'tax_rate_estimate' => 0.2, 'platform_fee_percent' => 5.52]]);
        $a = $this->affiliate();
        $this->sale($a, 21.14, ['basis_method' => 'provider_net']);

        $csv = $this->statementFor($a);

        $this->assertStringContainsString('estimated at 5.5%', $csv);
        $this->assertStringContainsString('does not report per order', $csv);
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
