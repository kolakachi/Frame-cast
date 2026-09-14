<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\AffiliateController;
use App\Http\Controllers\Api\V1\Affiliate\PortalController;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePaymentDetail;
use App\Models\AffiliatePayout;
use App\Models\User;
use App\Services\Affiliate\ExchangeRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAffiliateSchema;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database. */
class AffiliatePayoutPolicyTest extends TestCase
{
    use BuildsAffiliateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAffiliateSchema('pol_test');

        // Rates are stubbed and the cache is per-test, so a run never depends
        // on the live feed or on what an earlier test left behind.
        config(['cache.default' => 'array']);
        \Illuminate\Support\Facades\Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'open.er-api.com/*' => Http::response([
                'rates' => ['NGN' => 1500.0],
                'time_last_update_utc' => 'Mon, 14 Sep 2026 00:00:00 +0000',
            ]),
            // A separate host for the outage cases: Http::fake merges stubs and
            // the first match wins, so re-faking '*' later cannot override the
            // healthy one above.
            'fx-down.test/*' => Http::response([], 503),
        ]);
    }

    private string $key = '';

    private function affiliate(): Affiliate
    {
        $this->key = Affiliate::generateAccessKey();

        return Affiliate::query()->create(['code' => 'marcus', 'name' => 'Marcus',
            'commission_percent' => 30, 'status' => 'active', 'access_key' => $this->key]);
    }

    /** @param int $ageDays how long ago the sale happened */
    private function sale(Affiliate $a, float $commission, int $ageDays = 0): AffiliateConversion
    {
        $when = now()->subDays($ageDays);
        $hold = (int) config('affiliates.hold_days', 21);
        $c = AffiliateConversion::query()->create([
            'affiliate_id' => $a->getKey(), 'order_id' => 'o'.bin2hex(random_bytes(4)),
            'order_amount' => 199, 'gross_amount' => 199, 'basis_amount' => 157,
            'commission_percent' => 30, 'commission_amount' => $commission,
            'eligible_at' => $when->copy()->addDays($hold),
        ]);

        $c->forceFill(['created_at' => $when])->save();

        return $c;
    }

    private function verifiedDetails(Affiliate $a, string $status = 'verified'): AffiliatePaymentDetail
    {
        return AffiliatePaymentDetail::query()->create([
            'affiliate_id' => $a->getKey(), 'account_name' => 'Marcus Ilunga',
            'bank_name' => 'GTBank', 'account_number' => '0123456789',
            'account_number_last4' => '6789', 'country' => 'NG', 'payout_currency' => 'NGN',
            'status' => $status, 'verified_at' => $status === 'verified' ? now() : null,
        ]);
    }

    private function adminRequest(array $body = []): Request
    {
        $r = Request::create('/payouts', 'POST', $body);
        $r->setUserResolver(fn () => (new User)->forceFill(['id' => 1]));

        return $r;
    }

    // ── maturity ────────────────────────────────────────────────────

    public function test_a_commission_inside_the_refund_window_cannot_be_paid(): void
    {
        // The whole point: our own policy allows a refund up to ~21 days out.
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 3);
        $this->verifiedDetails($a);

        $response = (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());

        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('refund window', $response->getData(true)['error']['message']);
        $this->assertSame(0, AffiliatePayout::query()->count());
    }

    public function test_a_commission_past_the_hold_is_paid_and_a_newer_one_is_left_behind(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);   // matured
        $this->sale($a, 21.00, ageDays: 2);    // still held
        $this->verifiedDetails($a);

        $response = (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());
        $this->assertSame(201, $response->status());

        $payout = AffiliatePayout::query()->firstOrFail();
        $this->assertSame(1, (int) $payout->sales_count);
        $this->assertEqualsWithDelta(47.00, (float) $payout->total_amount, 0.001);
    }

    public function test_the_dashboard_separates_what_is_ready_from_what_is_still_held(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->sale($a, 21.00, ageDays: 2);

        $token = (new PortalController)->login(Request::create('/l', 'POST',
            ['code' => $a->code, 'key' => $this->key]))->getData(true)['data']['token'];
        $r = Request::create('/s', 'GET');
        $r->headers->set('Authorization', 'Bearer '.$token);
        $data = (new PortalController)->summary($r)->getData(true)['data'];

        $this->assertEqualsWithDelta(47.00, $data['balance']['available'], 0.001);
        $this->assertEqualsWithDelta(21.00, $data['balance']['maturing'], 0.001);
        $this->assertSame(21, $data['balance']['hold_days']);
        $this->assertNotNull($data['balance']['next_matures_at']);
    }

    // ── payment details gate ────────────────────────────────────────

    public function test_a_payout_is_refused_when_no_account_is_on_file(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);

        $response = (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('No payment details', $response->getData(true)['error']['message']);
    }

    public function test_a_payout_is_refused_while_details_are_unverified(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a, 'unverified');

        $response = (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('awaiting verification', $response->getData(true)['error']['message']);
    }

    public function test_editing_an_account_number_withdraws_its_verification(): void
    {
        // Verification checked specific digits. Carrying it across a change
        // would let a verified payout be redirected to an unchecked account.
        $a = $this->affiliate();
        $d = $this->verifiedDetails($a);
        $this->assertSame('verified', $d->status);

        $d->applySubmission(['account_number' => '9999999999', 'account_number_last4' => '9999']);

        $this->assertSame('unverified', $d->fresh()->status);
        $this->assertNull($d->fresh()->verified_at);
    }

    public function test_the_affiliate_is_never_shown_their_full_account_number_back(): void
    {
        $a = $this->affiliate();
        $this->verifiedDetails($a);

        $token = (new PortalController)->login(Request::create('/l', 'POST',
            ['code' => $a->code, 'key' => $this->key]))->getData(true)['data']['token'];
        $r = Request::create('/s', 'GET');
        $r->headers->set('Authorization', 'Bearer '.$token);
        $body = (new PortalController)->summary($r)->getContent();

        $this->assertStringNotContainsString('0123456789', $body);
        $this->assertStringContainsString('6789', $body);   // last four, to recognise it
    }

    // ── currency ────────────────────────────────────────────────────

    public function test_the_rate_used_is_frozen_onto_the_payout(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a);

        (new AffiliateController)->createPayout($this->adminRequest(['fx_rate' => 1450.5]), $a->getKey());

        $p = AffiliatePayout::query()->firstOrFail();
        $this->assertEqualsWithDelta(1450.5, (float) $p->fx_rate, 0.001);
        $this->assertEqualsWithDelta(47.00 * 1450.5, (float) $p->payout_amount, 0.01);
        $this->assertSame('NGN', $p->payout_currency);
        $this->assertSame('entered at payout', $p->fx_source);
    }

    public function test_without_an_entered_rate_the_live_one_is_recorded(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a);

        (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());

        $p = AffiliatePayout::query()->firstOrFail();
        // 1500 mid, less the 2% spread.
        $this->assertEqualsWithDelta(1470.0, (float) $p->fx_rate, 0.01);
        $this->assertSame('open.er-api.com', $p->fx_source);
    }

    public function test_the_shown_rate_is_below_mid_market(): void
    {
        // Quoting mid-market promises a figure a transfer into Nigeria does
        // not achieve, and the gap is discovered at the worst moment.
        $fx = (new ExchangeRateService)->current();

        $this->assertEqualsWithDelta(1500.0, $fx['mid'], 0.001);
        $this->assertLessThan($fx['mid'], $fx['rate']);
        $this->assertSame('USD/NGN', $fx['pair']);
    }

    public function test_an_fx_outage_with_nothing_cached_records_no_naira_figure_at_all(): void
    {
        // An invented rate here is one somebody gets paid on.
        \Illuminate\Support\Facades\Cache::flush();
        config(['affiliates.fx.endpoint' => 'https://fx-down.test/v6/latest']);
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a);

        (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());

        $p = AffiliatePayout::query()->firstOrFail();
        $this->assertNull($p->payout_amount);
        $this->assertNull($p->fx_rate);
        $this->assertEqualsWithDelta(47.00, (float) $p->total_amount, 0.001);
    }

    public function test_a_stale_rate_is_used_but_labelled_as_stale(): void
    {
        // Degrading to yesterday's number beats failing outright, but the
        // record has to admit which it was.
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a);
        // Seeded past its cache window, so the service is forced to re-fetch,
        // fail, and fall back — which is the path under test.
        \Illuminate\Support\Facades\Cache::put('affiliate:fx:usd-ngn', [
            'mid' => 1500.0, 'from' => 'USD', 'to' => 'NGN',
            'source' => 'open.er-api.com',
            'fetched_at' => now()->subDays(2)->toIso8601String(),
        ], now()->addDays(7));
        config(['affiliates.fx.endpoint' => 'https://fx-down.test/v6/latest']);

        (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());

        $p = AffiliatePayout::query()->firstOrFail();
        $this->assertEqualsWithDelta(1470.0, (float) $p->fx_rate, 0.01);
        $this->assertStringContainsString('stale', $p->fx_source);
    }

    // ── lifecycle ───────────────────────────────────────────────────

    public function test_a_failed_transfer_is_recorded_as_failed_with_its_reason(): void
    {
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a);
        (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());
        $p = AffiliatePayout::query()->firstOrFail();

        (new AffiliateController)->updatePayoutStatus(
            Request::create('/st', 'POST', ['status' => 'failed', 'failure_reason' => 'Account name mismatch']),
            $a->getKey(), $p->getKey(),
        );

        $this->assertSame('failed', $p->fresh()->status);
        $this->assertSame('Account name mismatch', $p->fresh()->failure_reason);
    }

    public function test_a_corrected_rate_restates_the_naira_amount_with_it(): void
    {
        // Otherwise the statement shows a rate and a total that disagree.
        $a = $this->affiliate();
        $this->sale($a, 47.00, ageDays: 30);
        $this->verifiedDetails($a);
        (new AffiliateController)->createPayout($this->adminRequest(), $a->getKey());
        $p = AffiliatePayout::query()->firstOrFail();

        (new AffiliateController)->updatePayoutStatus(
            Request::create('/st', 'POST', ['status' => 'paid', 'fx_rate' => 1380.25, 'payment_reference' => 'GTB-99182']),
            $a->getKey(), $p->getKey(),
        );

        $fresh = $p->fresh();
        $this->assertEqualsWithDelta(1380.25, (float) $fresh->fx_rate, 0.001);
        $this->assertEqualsWithDelta(47.00 * 1380.25, (float) $fresh->payout_amount, 0.01);
        $this->assertSame('GTB-99182', $fresh->payment_reference);
    }

    public function test_the_next_payout_date_is_always_in_the_future(): void
    {
        // A schedule that has slipped must not show a past date, which reads
        // as a payment that was missed.
        $a = $this->affiliate();
        $a->forceFill(['created_at' => now()->subYear()])->save();

        $date = \App\Services\Affiliate\PayoutEligibility::nextPayoutDate($a->fresh());

        $this->assertTrue(\Carbon\CarbonImmutable::parse($date)->isFuture());
    }
}
