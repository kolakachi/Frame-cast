<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayout;
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

        return response()->json(['data' => [
            'affiliate' => $this->profile($affiliate),
            'totals' => [
                'clicks' => $clicks,
                'sales' => $live->count(),
                // Rate is only meaningful once there is traffic to divide by.
                'conversion_rate' => $clicks > 0 ? round($live->count() / $clicks * 100, 2) : null,
                'earned' => round((float) $live->sum('commission_amount'), 2),
                'owed' => round((float) $rows->where('payout_status', 'unpaid')->sum('commission_amount'), 2),
                'paid' => round((float) $rows->where('payout_status', 'paid')->sum('commission_amount'), 2),
            ],
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
            ->get(['created_at', 'commission_amount'])
            ->groupBy(fn ($c) => CarbonImmutable::parse($c->created_at)->toDateString());

        $days = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $onDay = $sales->get($key);
            $days[] = [
                'date' => $key,
                'clicks' => (int) ($clicks[$key] ?? 0),
                'sales' => $onDay?->count() ?? 0,
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
                'status' => $p->status,
            ]);

        return response()->json(['data' => ['payouts' => $rows], 'meta' => []]);
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
