<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateConversion;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tie a sale to the affiliate that caused it, and keep hold of that link long
 * enough to pay them.
 *
 * The hard part is not the split, it is the distance between a click and the
 * money. Someone arrives from a link, reads for a while, maybe leaves and
 * comes back, registers days later or not at all, and then pays on a checkout
 * hosted by Kelviq. Any one place to keep that fact will eventually lose it,
 * so it is written in four:
 *
 *   1. a first-party cookie, which survives browsing and a later return;
 *   2. the workspace, the moment an account exists, which survives the cookie
 *      being cleared and a different device afterwards;
 *   3. the Kelviq checkout's metadata, which comes back with the webhook and
 *      so survives having no account at all — the direct-purchase path;
 *   4. the conversion row itself, written at the moment of sale with the rate
 *      copied in, which survives everything, including the affiliate's terms
 *      being renegotiated later.
 *
 * Only the last one is authoritative at payout. The first three exist to get
 * the fact as far as the sale; after that nothing is recomputed.
 */
class AffiliateAttribution
{
    public const COOKIE = 'wyv_aff';

    /** How long a click keeps earning. */
    public const WINDOW_DAYS = 90;

    /**
     * Record an arrival and return the cookie value to set, or null when the
     * code matches no active affiliate.
     *
     * Last click wins: a visitor who arrives again through a different
     * affiliate is credited to the more recent one, which is the convention
     * marketers expect and the one they can check for themselves.
     */
    public function recordClick(Request $request, string $code): ?string
    {
        $affiliate = Affiliate::findByCode($code);
        if (! $affiliate) {
            return null;
        }

        // Best-effort: a failed click log must never cost the visit.
        rescue(fn () => AffiliateClick::query()->create([
            'affiliate_id' => $affiliate->getKey(),
            'landing_path' => mb_substr((string) $request->path(), 0, 255),
            'referer'      => mb_substr((string) $request->headers->get('referer', ''), 0, 255) ?: null,
            'visitor_hash' => $this->visitorHash($request),
            'clicked_at'   => now(),
        ]), null, false);

        return $affiliate->code;
    }

    /** The code carried by this request's cookie, if it still names an active affiliate. */
    public function fromCookie(Request $request): ?string
    {
        $code = (string) $request->cookie(self::COOKIE, '');

        return $code !== '' && Affiliate::findByCode($code) ? $code : null;
    }

    /**
     * Stamp a workspace with the affiliate that sent them, once. Re-stamping
     * would let a later visit through a different link move a customer who was
     * already earned.
     */
    public function attributeWorkspace(Workspace $workspace, ?string $code): void
    {
        if (! $code || $workspace->affiliate_code) {
            return;
        }
        if (! Affiliate::findByCode($code)) {
            return;
        }

        $workspace->forceFill([
            'affiliate_code' => $code,
            'affiliate_attributed_at' => now(),
        ])->save();
    }

    /**
     * Resolve the affiliate for a completed sale, most durable source first.
     *
     * @return array{0: ?Affiliate, 1: string}  the affiliate and how it was found
     */
    public function resolveForSale(?array $checkoutMetadata, ?Workspace $workspace, ?string $cookieCode): array
    {
        // Came back with the order itself: survives having no account.
        $fromMeta = $checkoutMetadata['affiliate_code'] ?? null;
        if ($fromMeta && ($a = Affiliate::findByCode((string) $fromMeta))) {
            return [$a, 'checkout_metadata'];
        }

        // Stamped when the account was made.
        if ($workspace?->affiliate_code && ($a = Affiliate::findByCode((string) $workspace->affiliate_code))) {
            return [$a, 'workspace'];
        }

        // Still in the browser that bought.
        if ($cookieCode && ($a = Affiliate::findByCode($cookieCode))) {
            return [$a, 'cookie'];
        }

        return [null, 'none'];
    }

    /**
     * Write the conversion. Idempotent on order id, because a webhook that is
     * delivered twice must not owe an affiliate twice.
     */
    public function recordConversion(
        Affiliate $affiliate,
        string $attributionSource,
        ?Workspace $workspace,
        ?string $customerEmail,
        ?string $orderId,
        ?string $plan,
        float $orderAmount,
        string $currency = 'USD',
    ): ?AffiliateConversion {
        if ($orderId && AffiliateConversion::query()->where('order_id', $orderId)->exists()) {
            return null;
        }

        $percent = (float) $affiliate->commission_percent;

        try {
            return AffiliateConversion::query()->create([
                'affiliate_id'       => $affiliate->getKey(),
                'workspace_id'       => $workspace?->getKey(),
                'customer_email'     => $customerEmail,
                'order_id'           => $orderId,
                'plan'               => $plan,
                'order_amount'       => round($orderAmount, 2),
                'currency'           => $currency,
                // Copied, not referenced: renegotiating a rate must not
                // restate what is already owed.
                'commission_percent' => $percent,
                'commission_amount'  => round($orderAmount * $percent / 100, 2),
                'attribution_source' => $attributionSource,
                'payout_status'      => 'unpaid',
            ]);
        } catch (\Throwable $e) {
            // A lost commission row is worse than a noisy log.
            Log::error('AffiliateAttribution: could not record a conversion', [
                'affiliate_id' => $affiliate->getKey(),
                'order_id'     => $orderId,
                'error'        => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Coarse visitor fingerprint — enough to spot self-clicking, not to identify anyone. */
    private function visitorHash(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->ip(),
            mb_substr((string) $request->userAgent(), 0, 120),
            config('app.key'),
        ]));
    }
}
