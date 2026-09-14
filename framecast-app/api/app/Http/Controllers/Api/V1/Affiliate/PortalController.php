<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayout;
use App\Models\AffiliatePaymentDetail;
use App\Services\Affiliate\ExchangeRateService;
use App\Services\Affiliate\PayoutEligibility;
use App\Models\AffiliateSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * What an affiliate can see about their own referrals.
 *
 * Read-only, and aggregate: counts and totals by day, never the buyers. An
 * affiliate needs to know a sale happened and what it earned, which is a
 * different question from who bought — and our customers did not agree to be
 * listed in a marketer's dashboard.
 *
 * Every response is scoped to the affiliate the session belongs to; no route
 * here takes an affiliate id.
 */
class PortalController extends Controller
{
    private const SESSION_DAYS = 30;

    public function login(Request $request): JsonResponse
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'key'  => ['required', 'string', 'max:64'],
        ]);

        // Throttled on the code, since that is the half an attacker already
        // has — the key is the only thing actually being guessed.
        $throttle = 'aff-portal:'.Str::lower($v['code']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 8)) {
            return response()->json(['error' => ['message' => 'Too many attempts. Try again in a few minutes.']], 429);
        }
        RateLimiter::hit($throttle, 600);

        $affiliate = Affiliate::findByCode($v['code']);
        $expected = $affiliate?->access_key;

        // One message for every failure, compared in constant time: a distinct
        // "no such code" would confirm which codes exist.
        if (! $affiliate || ! $expected || ! hash_equals($expected, trim($v['key']))) {
            return response()->json(['error' => ['message' => 'That code and key do not match.']], 401);
        }

        RateLimiter::clear($throttle);
        [$token, $session] = AffiliateSession::issue($affiliate, self::SESSION_DAYS);
        $affiliate->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => [
            'token' => $token,
            'expires_at' => $session->expires_at->toIso8601String(),
            'affiliate' => $this->profile($affiliate),
        ], 'meta' => []]);
    }

    public function logout(Request $request): JsonResponse
    {
        AffiliateSession::revoke($this->bearer($request));

        return response()->json(['data' => ['ok' => true], 'meta' => []]);
    }

    /** Headline figures, plus what is owed and what has already been sent. */
    public function summary(Request $request): JsonResponse
    {
        $affiliate = $this->affiliate($request);
        if (! $affiliate) {
            return $this->unauthenticated();
        }

        $rows = AffiliateConversion::query()->where('affiliate_id', $affiliate->getKey())->get();
        $live = $rows->where('payout_status', '!=', 'void');
        $clicks = AffiliateClick::query()->where('affiliate_id', $affiliate->getKey())->count();

        $lastPayout = AffiliatePayout::query()->where('affiliate_id', $affiliate->getKey())
            ->where('status', 'paid')->latest('paid_at')->first();

        $eligibility = PayoutEligibility::summarise($affiliate);
        $fx = app(ExchangeRateService::class)->current();

        return response()->json(['data' => [
            'affiliate' => $this->profile($affiliate),
            'totals' => [
                'clicks' => $clicks,
                'sales' => $live->count(),
                // Rate is only meaningful once there is traffic to divide by.
                'conversion_rate' => $clicks > 0 ? round($live->count() / $clicks * 100, 2) : null,

                // What the customers actually paid, shown next to what it
                // earned. The commission is a share of the net, so without the
                // gross beside it the percentage on their agreement does not
                // reconcile with the number in front of them — and an
                // unexplained gap is what makes a partner suspect one.
                'revenue' => round((float) $live->sum('gross_amount'), 2)
                    ?: round((float) $live->sum('order_amount'), 2),
                'basis' => round((float) $live->sum('basis_amount'), 2)
                    ?: round((float) $live->sum('order_amount'), 2),

                'earned' => round((float) $live->sum('commission_amount'), 2),
                'owed' => round((float) $rows->where('payout_status', 'unpaid')->sum('commission_amount'), 2),
                'paid' => round((float) $rows->where('payout_status', 'paid')->sum('commission_amount'), 2),
            ],

            // Owed splits in two once a hold exists. A single figure either
            // overstates what is coming or understates what was earned, and a
            // number that drops without explanation reads as a mistake.
            'balance' => [
                'available' => $eligibility['available'],
                'available_count' => $eligibility['available_count'],
                'maturing' => $eligibility['maturing'],
                'maturing_count' => $eligibility['maturing_count'],
                'next_matures_at' => $eligibility['next_matures_at'],
                'hold_days' => (int) config('affiliates.hold_days', 21),
                'blocked_reason' => $eligibility['blocked_reason'],
            ],

            'schedule' => [
                'cycle_days' => (int) config('affiliates.cycle_days', 14),
                'next_payout_date' => PayoutEligibility::nextPayoutDate($affiliate),
                'minimum' => $eligibility['minimum'],
                'payout_currency' => (string) config('affiliates.payout_currency', 'NGN'),
                'commission_currency' => (string) config('affiliates.commission_currency', 'USD'),
            ],

            'fx' => $fx,

            // Spelled out rather than left for them to multiply, so the figure
            // on the dashboard and the figure in the bank have a visible link.
            'estimate' => [
                'commission' => $eligibility['available'],
                'rate' => $fx['rate'],
                'payout' => $fx['rate'] ? round($eligibility['available'] * $fx['rate'], 2) : null,
                // Never a promise: the rate moves, and the one that counts is
                // the one captured when the transfer is made.
                'is_estimate' => true,
            ],

            'payment_details' => $this->detailPayload($affiliate),
            'last_payout' => $lastPayout ? [
                'reference' => $lastPayout->reference,
                'amount' => round((float) $lastPayout->total_amount, 2),
                'paid_at' => $lastPayout->paid_at?->toDateString(),
            ] : null,
        ], 'meta' => []]);
    }

    /**
     * Visits, sales and commission by day.
     *
     * Every day in the window is returned, including empty ones — a chart with
     * the quiet days missing reads as a series of good days.
     */
    public function daily(Request $request): JsonResponse
    {
        $affiliate = $this->affiliate($request);
        if (! $affiliate) {
            return $this->unauthenticated();
        }

        $v = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $to = CarbonImmutable::parse($v['to'] ?? 'today')->endOfDay();
        $from = CarbonImmutable::parse($v['from'] ?? $to->subDays(29)->toDateString())->startOfDay();
        // Bounded so a hand-edited range cannot ask for an unbounded scan.
        if ($from->diffInDays($to) > 365) {
            $from = $to->subDays(365)->startOfDay();
        }

        $clicks = AffiliateClick::query()
            ->where('affiliate_id', $affiliate->getKey())
            ->whereBetween('clicked_at', [$from, $to])
            ->get(['clicked_at'])
            ->countBy(fn ($c) => CarbonImmutable::parse($c->clicked_at)->toDateString());

        $sales = AffiliateConversion::query()
            ->where('affiliate_id', $affiliate->getKey())
            ->where('payout_status', '!=', 'void')
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'commission_amount', 'gross_amount', 'order_amount'])
            ->groupBy(fn ($c) => CarbonImmutable::parse($c->created_at)->toDateString());

        $days = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $onDay = $sales->get($key);
            $days[] = [
                'date' => $key,
                'clicks' => (int) ($clicks[$key] ?? 0),
                'sales' => $onDay?->count() ?? 0,
                'revenue' => round((float) ($onDay?->sum(fn ($c) => (float) ($c->gross_amount ?: $c->order_amount)) ?? 0), 2),
                'commission' => round((float) ($onDay?->sum('commission_amount') ?? 0), 2),
            ];
        }

        return response()->json(['data' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days,
        ], 'meta' => []]);
    }

    /** What we have actually sent them, so the dashboard can be reconciled. */
    public function payouts(Request $request): JsonResponse
    {
        $affiliate = $this->affiliate($request);
        if (! $affiliate) {
            return $this->unauthenticated();
        }

        $rows = AffiliatePayout::query()->where('affiliate_id', $affiliate->getKey())
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (AffiliatePayout $p) => [
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toDateString(),
                'period_start' => $p->period_start?->toDateString(),
                'period_end' => $p->period_end?->toDateString(),
                'sales_count' => (int) $p->sales_count,
                'total_amount' => (float) $p->total_amount,
                'currency' => $p->currency,
                'payout_amount' => $p->payout_amount !== null ? (float) $p->payout_amount : null,
                'payout_currency' => $p->payout_currency,
                'fx_rate' => $p->fx_rate !== null ? (float) $p->fx_rate : null,
                'fx_source' => $p->fx_source,
                'payment_reference' => $p->payment_reference,
                'status' => $p->status,
                'failure_reason' => $p->failure_reason,
            ]);

        return response()->json(['data' => ['payouts' => $rows], 'meta' => []]);
    }

    /**
     * Save or replace the account their money goes to.
     *
     * Editing returns the row to unverified — see the model. Verification
     * checked specific digits, and carrying approval across a change would let
     * a verified payout be redirected to an account nobody looked at.
     */
    public function savePaymentDetails(Request $request): JsonResponse
    {
        $affiliate = $this->affiliate($request);
        if (! $affiliate) {
            return $this->unauthenticated();
        }

        $v = $request->validate([
            'account_name' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:120'],
            // Digits and spaces only: an account number with letters in it is
            // a typo, and this is the one field nobody gets to re-check later.
            'account_number' => ['required', 'string', 'max:34', 'regex:/^[0-9 -]+$/'],
            'bank_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'string', 'size:2'],
            'payout_currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $number = preg_replace('/[^0-9]/', '', $v['account_number']);
        if (strlen($number) < 6) {
            return response()->json(['error' => ['message' => 'That account number looks too short.']], 422);
        }

        $detail = AffiliatePaymentDetail::query()->firstOrNew(['affiliate_id' => $affiliate->getKey()]);
        $detail->applySubmission([
            'affiliate_id' => $affiliate->getKey(),
            'account_name' => $v['account_name'],
            'bank_name' => $v['bank_name'],
            'account_number' => $number,
            'account_number_last4' => substr($number, -4),
            'bank_code' => $v['bank_code'] ?? null,
            'country' => strtoupper($v['country'] ?? 'NG'),
            'payout_currency' => strtoupper($v['payout_currency'] ?? (string) config('affiliates.payout_currency', 'NGN')),
        ]);

        return response()->json(['data' => ['payment_details' => $this->detailPayload($affiliate->fresh())], 'meta' => []]);
    }

    /**
     * What the owner is shown back. Never the full number — they typed it, and
     * echoing it puts it in a browser cache and a screenshot for no gain.
     */
    private function detailPayload(Affiliate $affiliate): ?array
    {
        $d = AffiliatePaymentDetail::query()->where('affiliate_id', $affiliate->getKey())->first();
        if (! $d) {
            return null;
        }

        return [
            'account_name' => $d->account_name,
            'bank_name' => $d->bank_name,
            'account_number_masked' => $d->masked(),
            'bank_code' => $d->bank_code,
            'country' => $d->country,
            'payout_currency' => $d->payout_currency,
            'status' => $d->status,
            'verified_at' => $d->verified_at?->toDateString(),
            'rejected_reason' => $d->rejected_reason,
            'submitted_at' => $d->submitted_at?->toDateString(),
        ];
    }

    private function profile(Affiliate $a): array
    {
        return [
            'name' => $a->name,
            'code' => $a->code,
            'commission_percent' => (float) $a->commission_percent,
            'status' => $a->status,
            'link' => rtrim((string) config('app.marketing_url', 'https://wyvstudio.com'), '/').'/?ref='.$a->code,
        ];
    }

    private function bearer(Request $request): string
    {
        return (string) ($request->bearerToken() ?: $request->input('token', ''));
    }

    private function affiliate(Request $request): ?Affiliate
    {
        return AffiliateSession::resolve($this->bearer($request));
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['error' => ['message' => 'Sign in again to continue.']], 401);
    }
}
