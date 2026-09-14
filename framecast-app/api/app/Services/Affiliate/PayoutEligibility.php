<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePaymentDetail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether an affiliate can be paid right now, and how much of what they are
 * owed is actually ready.
 *
 * "Owed" splits in two the moment a hold exists. Reporting one number would
 * either overstate what is coming (counting commissions that could still be
 * refunded) or understate what has been earned — and an affiliate who sees a
 * figure drop without explanation assumes the worst.
 */
class PayoutEligibility
{
    /** Commissions past their hold, unpaid, and not reversed. */
    public static function matured(int $affiliateId): Builder
    {
        return AffiliateConversion::query()
            ->where('affiliate_id', $affiliateId)
            ->where('payout_status', 'unpaid')
            ->where(function (Builder $q) {
                // A row written before the hold existed has no stamp; treating
                // it as mature is right — it predates the policy and has long
                // since cleared any refund window.
                $q->whereNull('eligible_at')->orWhere('eligible_at', '<=', now());
            });
    }

    /** Unpaid but still inside its refund window. */
    public static function maturing(int $affiliateId): Builder
    {
        return AffiliateConversion::query()
            ->where('affiliate_id', $affiliateId)
            ->where('payout_status', 'unpaid')
            ->whereNotNull('eligible_at')
            ->where('eligible_at', '>', now());
    }

    /**
     * Everything a dashboard or a payout screen needs to say, in one shape.
     *
     * @return array{available: float, available_count: int, maturing: float,
     *               maturing_count: int, next_matures_at: ?string,
     *               minimum: float, meets_minimum: bool, details_ok: bool,
     *               details_status: string, blocked_reason: ?string}
     */
    public static function summarise(Affiliate $affiliate): array
    {
        $id = (int) $affiliate->getKey();

        $matured = self::matured($id)->get();
        $maturing = self::maturing($id)->get();

        $available = round((float) $matured->sum('commission_amount'), 2);
        $minimum = (float) config('affiliates.minimum_payout', 0);

        $details = AffiliatePaymentDetail::query()->where('affiliate_id', $id)->first();
        $needsVerified = (bool) config('affiliates.require_verified_details', true);
        $detailsOk = $details && (! $needsVerified || $details->status === 'verified');

        return [
            'available' => $available,
            'available_count' => $matured->count(),
            'maturing' => round((float) $maturing->sum('commission_amount'), 2),
            'maturing_count' => $maturing->count(),
            'next_matures_at' => $maturing->min('eligible_at')?->toDateString(),
            'minimum' => $minimum,
            'meets_minimum' => $available >= $minimum,
            'details_ok' => $detailsOk,
            'details_status' => $details?->status ?? 'missing',
            // One reason, in the order a person would fix them.
            'blocked_reason' => match (true) {
                $available <= 0 => 'Nothing has matured yet.',
                ! $details => 'No payment details on file.',
                $needsVerified && $details->status === 'rejected' => 'Payment details were rejected.',
                $needsVerified && $details->status !== 'verified' => 'Payment details are awaiting verification.',
                $available < $minimum => 'Below the minimum payout.',
                default => null,
            },
        ];
    }

    /**
     * When the next run is due.
     *
     * Counted from the last completed payout so the cadence is a promise about
     * the gap between payments, not about which day of the month it is.
     */
    public static function nextPayoutDate(Affiliate $affiliate): string
    {
        $cycle = max(1, (int) config('affiliates.cycle_days', 14));

        $last = $affiliate->payouts()
            ->whereIn('status', ['paid', 'processing'])
            ->max('paid_at');

        $from = $last ? CarbonImmutable::parse($last) : CarbonImmutable::parse($affiliate->created_at);
        $next = $from->addDays($cycle);

        // A schedule that has slipped should say "next run", not a date in the
        // past — which reads as a missed payment.
        while ($next->isPast()) {
            $next = $next->addDays($cycle);
        }

        return $next->toDateString();
    }
}
