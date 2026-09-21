<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kelviq (Merchant of Record) billing integration.
 *
 * Webhooks follow the Svix scheme: headers webhook-id / webhook-timestamp /
 * webhook-signature, signature = hex(HMAC-SHA256(secret, "id.timestamp.body"))
 * where `secret` is the literal `kq_whsec_...` string (verified against a live
 * delivery 2026-08-24); header carries space-separated `v1,<sig>` entries.
 * Checkout is created via
 * POST {api_base}/checkout/. See spec/KELVIQ_INTEGRATION.md + docs.kelviq.com.
 */
class KelviqService
{
    public function __construct(private CreditService $credits)
    {
    }

    // ── Webhooks ──────────────────────────────────────────────────────────

    /** Verify a Svix-style signature. Returns false on any mismatch/missing. */
    public function verifyWebhook(string $rawBody, string $webhookId, string $timestamp, string $signatureHeader): bool
    {
        $secret = (string) config('billing.kelviq.webhook_secret', '');
        if ($secret === '' || $webhookId === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }
        // Replay protection — reject clock skew greater than 5 minutes.
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedContent = "{$webhookId}.{$timestamp}.{$rawBody}";

        // Kelviq signs with the LITERAL secret string (the `kq_whsec_` prefix
        // included) and sends the digest hex-encoded. The decoded/stripped keys
        // and the base64 digest are kept as fallbacks in case that changes.
        $stripped = preg_replace('/^kq_whsec_|^whsec_/', '', $secret);
        $candidateKeys = array_filter([
            $secret,
            $stripped,
            base64_decode($stripped, true) ?: null,
        ]);

        // Header may be "v1,<sig> v1,<sig2>"; compare against each.
        $provided = [];
        foreach (preg_split('/\s+/', trim($signatureHeader)) as $part) {
            $provided[] = str_contains($part, ',') ? substr($part, strpos($part, ',') + 1) : $part;
        }

        foreach ($candidateKeys as $key) {
            $raw = hash_hmac('sha256', $signedContent, $key, true);
            foreach ([bin2hex($raw), base64_encode($raw)] as $expected) {
                foreach ($provided as $sig) {
                    if (hash_equals($expected, $sig)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Dispatch a verified webhook event. Idempotent per event id (Svix retries
     * and dashboard reruns resend the same id) via a permanent claim in
     * `processed_webhook_events`; monthly credit refills additionally SET (not
     * add) the bucket, so reprocessing is safe on both counts.
     *
     * @param  array<string,mixed>  $event
     */
    public function handleEvent(array $event): void
    {
        // The receipt and entitlement changes commit together, including on crashes.
        DB::transaction(fn () => $this->processEvent($event));
    }

    private function processEvent(array $event): void
    {
        $eventId = (string) ($event['id'] ?? '');
        $type    = (string) ($event['type'] ?? '');

        // Commission recovery is independent of entitlement processing. A replay
        // can repair a missing commission without granting a paid top-up twice.
        $sale = $event['data']['object'] ?? null;
        if ($type === 'checkout.completed' && is_array($sale)) {
            $this->recordAffiliateConversion($sale, $sale['plan']['identifier'] ?? null);
        }

        if ($eventId !== '' && ! $this->claimEvent($eventId, $type)) {
            return; // already processed
        }

        $object = $event['data']['object'] ?? [];
        // A checkout can be re-emitted with a different delivery/event ID.
        if ($type === 'checkout.completed' && ! empty($object['id'])
            && ! $this->claimEvent('checkout:'.(string) $object['id'], $type)) {
            return;
        }
        if (! is_array($object)) {
            // Silently returning here was one of the candidates we couldn't
            // rule out when a cancellation went unrecorded — make it visible.
            Log::warning('KelviqService: event has no usable data.object', [
                'event_id'   => $eventId,
                'type'       => $type,
                'data_keys'  => is_array($event['data'] ?? null) ? array_keys($event['data']) : null,
                'event_keys' => array_keys($event),
            ]);

            return;
        }

        try {
            match ($type) {
                'subscription.created',
                'subscription.updated',
                'subscription.plan_changed' => $this->applySubscription($object),
                'subscription.cancelled'    => $this->markCancelled($object),
                'invoice.paid'              => $this->handleRenewal($object),
                'checkout.completed'        => $this->handleCheckoutCompleted($object),
                default                     => null,
            };
            if ($type === 'checkout.completed' && ! empty($object['metadata']['checkout_attempt_id'])) {
                $workspace = $this->resolveWorkspace($object);
                if (! $workspace) throw new \RuntimeException('Paid checkout has no matching workspace.');
                DB::table('billing_checkout_attempts')
                    ->where('id', $object['metadata']['checkout_attempt_id'])
                    ->where('workspace_id', $workspace->getKey())
                    ->where('provider_plan', $object['plan']['identifier'] ?? '')
                    ->update(['paid_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Don't let a failed attempt permanently burn the event id — a
            // retry/rerun must be able to process it.
            $this->releaseEvent($eventId);
            throw $e;
        }
    }

    /**
     * Claim an event id exactly once, permanently. Returns false if some
     * earlier delivery already processed it.
     *
     * The unique index does the work: a concurrent duplicate delivery loses the
     * insert race and gets false rather than double-processing. This must not
     * expire — `checkout.completed` grants top-up credits additively, so a
     * rerun days later would otherwise grant the pack twice.
     */
    private function claimEvent(string $eventId, string $type): bool
    {
        try {
            DB::transaction(fn () => DB::table('processed_webhook_events')->insert([
                'provider'     => 'kelviq',
                'event_id'     => $eventId,
                'type'         => $type !== '' ? $type : null,
                'processed_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]));

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                Log::info('KelviqService: duplicate event ignored', ['event_id' => $eventId, 'type' => $type]);

                return false;
            }
            throw $e;
        }
    }

    private function releaseEvent(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }
        rescue(fn () => DB::table('processed_webhook_events')
            ->where('provider', 'kelviq')
            ->where('event_id', $eventId)
            ->delete(), null, false);
    }

    /** Postgres unique-violation SQLSTATE (23505), with a MySQL fallback. */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23505', '23000'], true);
    }

    private function applySubscription(array $object): void
    {
        // Same as lifetime: a monthly plan bought straight from a checkout link
        // arrives before any account exists.
        $workspace = $this->resolveWorkspace($object) ?? $this->provisionFromEvent($object);
        if (! $workspace) {
            // Diagnostics are keys and flags only — never the raw body, which
            // carries customer name, email and billing address.
            Log::warning('KelviqService: subscription event — no workspace', [
                'object_id'      => $object['id'] ?? null,
                'object_keys'    => array_keys($object),
                'has_metadata_ws' => isset($object['metadata']['workspace_id']),
                'customer_keys'  => is_array($object['customer'] ?? null) ? array_keys($object['customer']) : null,
            ]);
            return;
        }

        $planId = $object['plan']['identifier'] ?? null;
        $tier   = config('billing.kelviq.plan_tiers')[$planId] ?? null;
        if (! $tier) {
            Log::warning('KelviqService: unknown plan identifier', [
                'plan'         => $planId,
                'workspace_id' => $workspace->getKey(),
                'plan_keys'    => is_array($object['plan'] ?? null) ? array_keys($object['plan']) : null,
                'object_keys'  => array_keys($object),
            ]);
            return;
        }

        // Kelviq keeps `status` at "active" for a cancel-at-period-end and
        // signals the cancellation out-of-band via canceled_at /
        // cancellation_reason. Reading `status` alone therefore records a
        // cancelled subscriber as active and overstates MRR until the period
        // actually lapses — and a `subscription.cancelled` event may never
        // arrive at all (a merchant-initiated cancel arrives as .updated).
        $cancelled = $this->isCancelled($object);
        $status    = $cancelled ? 'cancelled' : (string) ($object['status'] ?? 'active');

        Log::info('KelviqService: applying subscription', [
            'workspace_id' => $workspace->getKey(),
            'plan'         => $planId,
            'raw_status'   => $object['status'] ?? null,
            'cancelled'    => $cancelled,
            'object_keys'  => array_keys($object),
        ]);

        $workspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
        $previousTier = $workspace->plan_tier;
        $update = [
            'kelviq_account_id'      => $object['customer']['id'] ?? $workspace->kelviq_account_id,
            'kelviq_subscription_id' => $object['id'] ?? $workspace->kelviq_subscription_id,
            'plan_tier'              => $tier,
            'plan_status'            => $status,
        ];
        // Refill on tier change (SET, so it's idempotent). Deliberately NOT a
        // rollover-aware add: rollover is a renewal benefit, and adding here
        // would let anyone farm credits by toggling plans.
        if ($previousTier !== $tier) {
            $update['credits_monthly']   = CreditService::PLAN_CREDITS[$tier] ?? 0;
            $update['billing_renews_at'] = $this->periodEnd($object);
        }
        $workspace->forceFill($update)->save();
        $this->clearPendingCheckout($workspace);

        // First paid activation. Claimed once inside, because this handler also
        // runs for every subsequent plan change and webhook redelivery.
        if (! $cancelled && $status === 'active') {
            \App\Services\Onboarding\WelcomeMail::sendOnce($workspace);
        }

        // First free -> paid conversion rewards the referrer (idempotent).
        if ($previousTier === 'free' && $tier !== 'free') {
            rescue(fn () => app(RewardService::class)->referralConversion($workspace->fresh()));
        }
    }

    /**
     * Is this subscription cancelled — including cancelled-but-still-running
     * until the period ends?
     *
     * `status` alone is not the signal: Kelviq reports "active" right up to the
     * end date and marks the cancellation with canceled_at / cancellation_reason
     * (and nextInvoiceDate: null). Accepts both the webhook's snake_case and the
     * REST API's camelCase spellings.
     */
    private function isCancelled(array $object): bool
    {
        $status = strtolower(trim((string) ($object['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return true;
        }

        foreach (['canceled_at', 'canceledAt', 'cancelled_at', 'cancellation_reason', 'cancellationReason'] as $key) {
            if (! empty($object[$key])) {
                return true;
            }
        }

        return false;
    }

    /** Recurring charge — refill the current plan's monthly allocation. */
    private function handleRenewal(array $object): void
    {
        $workspace = $this->resolveWorkspace($object);
        if (! $workspace || $workspace->plan_tier === 'free') {
            return;
        }

        $workspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
        $periodEnd = $this->periodEnd($object);

        // Two paths refill: this webhook and the hourly ResetMonthlyCreditsJob
        // (which fires when a webhook is late or lost). A renewal date already
        // at or past this period's end means that period was refilled — and on
        // rollover tiers the refill ADDS, so doing it twice would double-grant.
        if ($workspace->billing_renews_at && $workspace->billing_renews_at->greaterThanOrEqualTo($periodEnd)) {
            Log::info('KelviqService: renewal already applied for this period', [
                'workspace_id' => $workspace->getKey(),
                'period_end'   => $periodEnd->toIso8601String(),
            ]);

            return;
        }

        $workspace->forceFill([
            'credits_monthly'   => CreditService::refilledMonthlyCredits(
                (string) $workspace->plan_tier,
                (int) $workspace->credits_monthly,
            ),
            'billing_renews_at' => $periodEnd,
        ])->save();
    }

    /**
     * The real end of the paid period, taken from the event rather than clock
     * arithmetic. `now()->addMonth()` drifted the renewal date forward by
     * however long the webhook took to arrive (or be replayed) — a rerun hours
     * later silently bought the customer extra paid time.
     *
     * Kelviq sends snake_case in webhooks and camelCase in REST responses, so
     * accept both. Falls back to a month out only if the event carries nothing.
     */
    private function periodEnd(array $object): CarbonInterface
    {
        $candidates = [
            $object['billing_period_end_time']       ?? null,
            $object['subscription']['next_invoice_date'] ?? null,
            $object['subscription']['nextInvoiceDate']   ?? null,
            $object['subscription']['billing_period_end_time'] ?? null,
            $object['next_invoice_date']             ?? null,
            $object['nextInvoiceDate']               ?? null,
            $object['billingPeriodEndTime']          ?? null,
        ];

        foreach ($candidates as $value) {
            if (! $value) {
                continue;
            }
            $parsed = rescue(fn () => Carbon::parse($value), null, false);
            if ($parsed) {
                return $parsed;
            }
        }

        Log::warning('KelviqService: no period end on event, falling back to +1 month', [
            'object_id' => $object['id'] ?? null,
        ]);

        return now()->addMonth();
    }

    private function markCancelled(array $object): void
    {
        $workspace = $this->resolveWorkspace($object);
        if ($workspace) {
            // Keep access/credits until the period ends; just record the status.
            $workspace->forceFill(['plan_status' => 'cancelled'])->save();
        }
    }

    /**
     * Tie a completed sale to the affiliate that produced it.
     *
     * Deliberately separate from granting the plan: an affiliate's commission
     * and a customer's credits are different obligations, and a failure in one
     * must not take the other with it.
     */
    private function recordAffiliateConversion(array $object, ?string $planId): void
    {
        // Commission rewards a PLAN sale, never a credit top-up. Top-ups are
        // priced a hair above the credit margin floor, so a percentage of one
        // can exceed what the sale earns: at the 50% rate we actually pay, a
        // $8/500 pack nets ~$0.30 against ~$3.50 of provider cost — underwater
        // by construction. Affiliates are paid when their referral buys a plan,
        // which is both the real sale and the high-margin one.
        if ($planId !== null && (
            array_key_exists($planId, (array) config('billing.kelviq.topup_plans', []))
            || $planId === (string) config('billing.kelviq.ugc_pass_plan')
        )) {
            return;
        }

        $attribution = app(\App\Services\Affiliate\AffiliateAttribution::class);

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $email = $object['customer']['email'] ?? null;
        $workspace = $this->resolveWorkspace($object);

        // No cookie here — a webhook is a server calling us, not the buyer's
        // browser. Metadata and the workspace are what survive that trip,
        // which is exactly why the code is put into the checkout.
        [$affiliate, $source] = $attribution->resolveForSale($metadata, $workspace, null);
        if (! $affiliate) {
            return;
        }

        $orderId = (string) ($object['order_id'] ?? $object['id'] ?? '');

        $amount = (float) ($object['amount'] ?? $object['amount_total'] ?? 0);
        if ($amount <= 0 && isset($object['amount_total_units'])) {
            $amount = ((int) $object['amount_total_units']) / 100;
        }

        // Ask Kelviq what the sale actually was. The webhook gives one
        // gross figure, so tax otherwise has to be guessed from a
        // configured rate — correct for a British buyer at 20% and wrong
        // for everyone else. Null falls back to that estimate.
        $breakdown = $this->fetchOrderBreakdown($orderId);

        $conversion = $attribution->recordConversion(
            $affiliate,
            $source,
            $workspace,
            is_string($email) ? $email : null,
            $orderId,
            $planId,
            $amount,
            (string) ($object['currency'] ?? 'USD'),
            $breakdown,
        );

        if ($conversion) {
            Log::info('Affiliate conversion recorded', [
                'affiliate_id' => $affiliate->getKey(),
                'order_id'     => $conversion->order_id,
                'source'       => $source,
            ]);
        }
    }

    /**
     * Fetch an order's real money breakdown.
     *
     * The webhook carries only the gross total, so tax had to be estimated
     * from configuration — which is right for a British buyer at 20% and wrong
     * for everyone else. The orders endpoint returns the actual subtotal,
     * discount and tax for that specific sale, plus whether it has since been
     * refunded.
     *
     * Returns null on any failure: an affiliate conversion recorded on the
     * gross is worse than none at all, but it is better than losing the sale
     * entirely, so the caller falls back to the estimate.
     *
     * @return array{subtotal: float, discount: float, tax: float, total: float, refunded: float}|null
     */
    public function fetchOrderBreakdown(string $orderId): ?array
    {
        $key = (string) config('billing.kelviq.server_api_key', '');
        if ($key === '' || $orderId === '') {
            return null;
        }

        $base = rtrim((string) config('billing.kelviq.api_base'), '/');

        try {
            $response = Http::withToken($key)->timeout(20)->get("{$base}/orders/{$orderId}/");
            if (! $response->successful()) {
                Log::info('KelviqService: order breakdown unavailable', [
                    'order_id' => $orderId,
                    'status'   => $response->status(),
                ]);

                return null;
            }

            $o = $response->json();
            if (! is_array($o) || ! isset($o['amountSubtotal'])) {
                return null;
            }

            return [
                'subtotal' => (float) ($o['amountSubtotal'] ?? 0),
                'discount' => (float) ($o['amountDiscount'] ?? 0),
                'tax'      => (float) ($o['amountTax'] ?? 0),
                'total'    => (float) ($o['amountTotal'] ?? 0),
                // Units, not currency — the API reports refunds in minor units.
                'refunded' => ((int) ($o['refundedTotalUnits'] ?? 0)) / 100,
            ];
        } catch (\Throwable $e) {
            Log::info('KelviqService: order lookup failed', [
                'order_id' => $orderId,
                'error'    => mb_substr($e->getMessage(), 0, 160),
            ]);

            return null;
        }
    }

    /** One-time checkout — a lifetime plan, or a credit top-up. */
    private function handleCheckoutCompleted(array $object): void
    {
        $planId = $object['plan']['identifier'] ?? null;

        // Lifetime purchase: set the tier permanently and grant its one-time
        // credit bucket. No subscription is created, so nothing renews and
        // credits_monthly stays 0 — the same shape as an AppSumo licence.
        // The UGC Test Pass is a one-time purchase like a lifetime pack, but
        // capped to one per customer: a pass someone can re-buy is a monthly
        // plan they renew by hand, at a price that undercuts Starter.
        if ($planId !== '' && $planId === (string) config('billing.kelviq.ugc_pass_plan')) {
            $this->applyUgcTestPass($object, (int) config('billing.kelviq.ugc_pass_credits', 600));

            return;
        }

        $lifetime = config('billing.kelviq.lifetime_plans')[$planId] ?? null;
        if ($lifetime) {
            $this->applyLifetimePurchase($object, $planId, $lifetime);

            return;
        }

        $credits = config('billing.kelviq.topup_plans')[$planId] ?? null;
        if (! $credits) {
            if (! empty($object['metadata']['checkout_attempt_id'])
                && ! isset(config('billing.kelviq.plan_tiers', [])[$planId])) {
                throw new \RuntimeException('Paid checkout plan is no longer configured.');
            }
            return; // not a top-up (subscription checkout is handled by subscription.*)
        }
        $workspace = $this->resolveWorkspace($object);
        if (! $workspace) {
            throw new \RuntimeException('Paid top-up has no matching workspace.');
        }
        $this->credits->grant((int) $workspace->getKey(), (int) $credits, 'topup_kelviq');
        $this->clearPendingCheckout($workspace);
        // The webhook event is claimed before this handler runs; redelivery
        // must not grant credits or queue a second confirmation.
        $owner = $workspace->owner_user_id
            ? \App\Models\User::find($workspace->owner_user_id)
            : \App\Models\User::where('workspace_id', $workspace->getKey())->orderBy('id')->first();
        if ($owner?->email) {
            rescue(fn () => \Illuminate\Support\Facades\Mail::to($owner->email)->queue(
                new \App\Mail\TopUpConfirmationMail(
                    (string) $owner->name, (int) $credits,
                    $this->credits->balance((int) $workspace->getKey()), (string) $workspace->name,
                ),
            ));
        } else {
            Log::warning('Top-up confirmation has no addressable owner', ['workspace_id' => $workspace->getKey()]);
        }

    }

    /**
     * A purchase landed for this workspace, so it is no longer abandoned.
     * Cleared on every success path — top-up, lifetime and subscription — so
     * the banner disappears and no follow-up email goes to someone who paid.
     */
    private function clearPendingCheckout(\App\Models\Workspace $workspace): void
    {
        if ($workspace->pending_checkout_at === null && $workspace->pending_checkout_plan === null) {
            return;
        }
        $workspace->forceFill([
            'pending_checkout_plan'        => null,
            'pending_checkout_at'          => null,
            'pending_checkout_reminded_at' => null,
        ])->save();
    }

    /**
     * The $9 UGC Test Pass: buy the UGC gate once, with 600 credits.
     *
     * Deliberately not routed through applyLifetimePurchase. That method
     * treats a one-time purchase as a pack *upgrade* and replaces the buyer's
     * existing bucket — which would have charged a Starter holder $9 to lose
     * their remaining lifetime credits. A pass is an add-on, not a swap.
     */
    private function applyUgcTestPass(array $object, int $credits): void
    {
        // Often someone's first contact with us: bought from the site with no
        // account yet, so build one rather than dropping a paid order.
        $workspace = $this->resolveWorkspace($object) ?? $this->provisionFromEvent($object);
        if (! $workspace) {
            throw new \RuntimeException('Paid UGC pass has no matching workspace.');
        }

        // One per customer. The ledger is the record: a grant:ugc_pass row
        // means they have had it, whatever their tier says now — they may have
        // upgraded since, or spent the pass out.
        //
        // Read under a row lock on the workspace: two webhooks for the same
        // buyer (a provider retry racing the original) would otherwise both
        // find no pass and both grant one. The lock serialises them, so the
        // second sees the first's row and falls through to credits-only.
        // The lock has to be held across the decision AND the writes it
        // guards. Locking only long enough to read let a provider retry race
        // the original: both saw no pass, both granted one.
        $hadPass = DB::transaction(function () use ($workspace, $credits): bool {
            Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->first();

            $already = \App\Models\CreditLedgerEntry::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('operation', 'grant:ugc_pass')
                ->exists();
            if ($already) {
                return true;
            }

            // Someone already on a plan with UGC does not need the gate this
            // buys. They paid, so they get the credits — tier left alone.
            if (! $this->credits->limitFor((int) $workspace->getKey(), 'ugc_ads')) {
                $workspace->forceFill([
                    'plan_tier'       => 'ugc_pass',
                    'plan_source'     => 'ugc_pass',
                    'plan_status'     => 'active',
                    'status'          => 'active',
                    'plan_renews_at'  => null,
                    'credits_monthly' => 0,
                ])->save();
            }

            $this->credits->grant((int) $workspace->getKey(), $credits, 'ugc_pass');

            return false;
        });

        if ($hadPass) {
            // They paid. Checkout refuses a second pass, so this is a race, a
            // stale tab or a direct link — but the money is real either way and
            // taking it for nothing is not an option. The pass itself is not
            // re-granted (they have had it); the credits they just bought are.
            Log::warning('KelviqService: duplicate UGC Test Pass — granting credits only', [
                'workspace_id' => $workspace->getKey(),
            ]);
            $this->credits->grant((int) $workspace->getKey(), $credits, 'ugc_pass_duplicate');
            $this->clearPendingCheckout($workspace);

            return;
        }

        $this->clearPendingCheckout($workspace);
        \App\Services\Onboarding\WelcomeMail::sendOnce($workspace->fresh());
    }

    /**
     * Apply a lifetime purchase: permanent tier + a one-time credit bucket.
     *
     * Idempotent on the credit grant — a webhook redelivery would otherwise
     * hand out the bucket twice. We record the grant against the workspace's
     * plan_source/plan_reference and skip if this exact plan was already
     * applied.
     *
     * @param array{tier:string,credits:int} $lifetime
     */
    private function applyLifetimePurchase(array $object, string $planId, array $lifetime): void
    {
        // A lifetime purchase is often someone's first contact with us — they
        // buy from the site and have no account yet, so build one rather than
        // dropping a paid order.
        $workspace = $this->resolveWorkspace($object) ?? $this->provisionFromEvent($object);
        if (! $workspace) {
            throw new \RuntimeException('Paid lifetime purchase has no matching workspace.');
        }

        $workspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
        $previousTier = (string) $workspace->plan_tier;
        $already = $workspace->plan_source === 'lifetime' && $workspace->plan_tier === $lifetime['tier'];

        // A purchase must never cost someone access they already had.
        //
        // The plans page only offers packs above the tier a customer holds, but
        // that is a filter in the browser — this endpoint accepts any pack. An
        // appsumo_agency holder buying lifetime_starter would otherwise be
        // written down to starter by the very act of paying us.
        //
        // Credits are granted either way below: they bought the bucket and the
        // bucket is additive. Only the tier, which drives feature access, is
        // held at the higher of the two.
        $keepTier = self::tierRank((string) $workspace->plan_tier) > self::tierRank($lifetime['tier']);

        $workspace->forceFill(array_merge([
            'plan_source'     => 'lifetime',
            'plan_status'     => 'active',
            'status'          => 'active',
            'plan_renews_at'  => null,
            'credits_monthly' => 0,
        ], $keepTier ? [] : ['plan_tier' => $lifetime['tier']]))->save();

        if ($keepTier) {
            Log::info('KelviqService: kept the higher tier through a lifetime purchase', [
                'workspace_id' => $workspace->getKey(),
                'kept'         => $workspace->plan_tier,
                'purchased'    => $lifetime['tier'],
            ]);
        }

        $this->clearPendingCheckout($workspace);

        if ($already) {
            Log::info('KelviqService: lifetime already applied, credits not re-granted', [
                'workspace_id' => $workspace->getKey(),
                'plan' => $planId,
            ]);

            return;
        }

        // Moving between our own one-time packs replaces the bucket rather than
        // stacking on it. Upgrading is a swap: you end on what the new tier
        // gives, not on the new tier plus whatever was left of the old one —
        // which would hand a barely-used Creator holder more credits than
        // someone who bought Agency outright, for the same money.
        //
        // Only the previous pack's own allocation is removed. Anything else in
        // the balance — top-up packs bought separately, an admin grant — was
        // paid for on its own terms and stays. Clamped to the balance, so a
        // customer who has already spent past the old allocation is not pushed
        // negative by upgrading.
        $previous = self::lifetimeBucket((string) $previousTier);
        if ($previous > 0) {
            $take = min($previous, (int) $workspace->fresh()->credits_topup);
            if ($take > 0) {
                $this->credits->deduct((int) $workspace->getKey(), $take, 'lifetime_upgrade_swap', [
                    'metadata' => ['from' => $previousTier, 'to' => $lifetime['tier'], 'replaced' => $take],
                ]);
            }
        }

        $this->credits->grant((int) $workspace->getKey(), (int) $lifetime['credits'], 'lifetime_kelviq');

        // After the grant, so the email can quote the bucket they actually have.
        \App\Services\Onboarding\WelcomeMail::sendOnce($workspace->fresh());
    }

    /**
     * Resolve a Kelviq event object to a workspace, most-reliable first:
     * our metadata.workspace_id → customerId (we pass the workspace id) →
     * stored kelviq_account_id → customer email.
     */
    /**
     * Create an account for a purchase that arrived without one.
     *
     * Every strategy in resolveWorkspace() needs the workspace to already
     * exist, which holds only when someone registered before paying. A
     * customer who buys straight from a checkout link has no account yet, and
     * before this the purchase resolved to null, logged a warning and returned
     * 200 — the money taken, nothing granted, and Kelviq with no reason to
     * retry. Provision from the customer block instead, mirroring registration
     * (AuthController), and mail a magic link so they can actually get in;
     * without that they own an account they have never heard of.
     *
     * Returns null when the event carries no email to build an account from.
     */
    private function provisionFromEvent(array $object): ?Workspace
    {
        $email = trim((string) ($object['customer']['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('KelviqService: cannot provision — no usable customer email', [
                'object_id'     => $object['id'] ?? null,
                'customer_keys' => is_array($object['customer'] ?? null) ? array_keys($object['customer']) : null,
            ]);

            return null;
        }

        $name = trim((string) ($object['customer']['name'] ?? ''));

        try {
            return DB::transaction(function () use ($email, $name, $object) {
                // Re-check inside the transaction: two events for one purchase
                // (checkout.completed then invoice.paid) can land together, and
                // only one of them may create the account.
                $existing = User::query()
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->with('workspace')
                    ->first();
                if ($existing?->workspace) {
                    return $existing->workspace;
                }

                $workspace = Workspace::query()->create([
                    'name'      => \Illuminate\Support\Str::of($email)->before('@')->headline().' Workspace',
                    'plan_tier' => 'free', // the caller applies the purchased tier
                    'status'    => 'active',
                ]);

                $user = User::query()->create([
                    'workspace_id'  => $workspace->getKey(),
                    'name'          => $name !== '' ? $name : \Illuminate\Support\Str::of($email)->before('@')->headline()->value(),
                    'email'         => $email,
                    'password_hash' => null, // magic link only until they set one
                    'timezone'      => 'UTC',
                    'role'          => 'owner',
                    'status'        => 'active',
                    'onboarding_step'         => 1,
                    'onboarding_last_sent_at' => now(),
                ]);

                $workspace->forceFill([
                    'owner_user_id'     => $user->getKey(),
                    'kelviq_account_id' => $object['customer']['id'] ?? null,
                ])->save();

                rescue(fn () => app(RewardService::class)->ensureReferralCode($workspace));

                Log::info('KelviqService: provisioned account for direct checkout', [
                    'workspace_id' => $workspace->getKey(),
                    'user_id'      => $user->getKey(),
                ]);

                // The welcome is not sent here. Provisioning only means an
                // account now exists; the caller sends it once the purchased
                // plan has actually been applied, so the email can describe it.

                return $workspace->fresh();
            });
        } catch (\Throwable $e) {
            report($e);
            Log::error('KelviqService: provisioning failed for direct checkout', [
                'object_id' => $object['id'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * What one of our own one-time packs granted, by tier.
     *
     * Deliberately blind to AppSumo tiers: those credits were bought from
     * AppSumo, not from us, and replacing them because someone later buys a
     * pack here would take away something we never sold them.
     */
    private static function lifetimeBucket(string $tier): int
    {
        foreach ((array) config('billing.kelviq.lifetime_plans') as $plan) {
            if (($plan['tier'] ?? null) === $tier) {
                return (int) ($plan['credits'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Where a tier sits, across both namespaces.
     *
     * appsumo_creator and lifetime_creator buy the same access, so they rank
     * the same; the suffix is what carries the meaning. Anything unrecognised
     * ranks lowest, which makes an unknown tier lose to a known one rather
     * than silently outranking it.
     */
    private static function tierRank(string $tier): int
    {
        return match (true) {
            str_ends_with($tier, '_agency')  => 3,
            str_ends_with($tier, '_creator') => 2,
            str_ends_with($tier, '_starter') => 1,
            default                          => 0,
        };
    }

    private function resolveWorkspace(array $object): ?Workspace
    {
        $wid = $object['metadata']['workspace_id'] ?? null;
        if ($wid && ctype_digit((string) $wid) && ($ws = Workspace::find((int) $wid))) {
            return $ws;
        }

        $cid = $object['customer']['customer_id'] ?? ($object['customer_id'] ?? null);
        if ($cid && ctype_digit((string) $cid) && ($ws = Workspace::find((int) $cid))) {
            return $ws;
        }

        $kid = $object['customer']['id'] ?? null;
        if ($kid && ($ws = Workspace::where('kelviq_account_id', $kid)->first())) {
            return $ws;
        }

        $email = $object['customer']['email'] ?? null;
        if ($email) {
            // Kelviq echoes the email as the customer typed it ("Davidmcdo@..."),
            // so compare case-insensitively on both sides.
            $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower((string) $email)])->with('workspace')->first();
            if ($user?->workspace) {
                return $user->workspace;
            }
        }
        return null;
    }

    // ── Checkout ──────────────────────────────────────────────────────────

    /**
     * Create a Kelviq checkout session and return the hosted checkout URL to
     * redirect the customer to (or null on failure). We pass the workspace id
     * as both customerId and metadata.workspace_id so the webhook resolves back.
     */
    public function createCheckoutSession(
        int $workspaceId,
        string $planIdentifier,
        string $chargePeriod,
        string $successUrl,
        ?string $cancelUrl = null,
        ?string $affiliateCode = null,
        ?string $checkoutAttemptId = null,
    ): ?string {
        $key = (string) config('billing.kelviq.server_api_key', '');
        if ($key === '') {
            return null;
        }

        $payload = [
            'planIdentifier' => $planIdentifier,
            'chargePeriod'   => $chargePeriod, // MONTHLY | ONE_TIME
            'successUrl'     => $successUrl,
            'customerId'     => (string) $workspaceId,
            // The affiliate code travels with the order so it comes back on
            // the webhook. This is the only layer that survives a purchase
            // made without ever registering, which is how direct checkout
            // works.
            'metadata'       => array_filter([
                'workspace_id'   => (string) $workspaceId,
                'affiliate_code' => $affiliateCode,
                'checkout_attempt_id' => $checkoutAttemptId,
            ]),
        ];
        if ($cancelUrl) {
            $payload['cancelUrl'] = $cancelUrl;
        }

        try {
            $resp = Http::withToken($key)
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim((string) config('billing.kelviq.api_base'), '/').'/checkout/', $payload);

            if (! $resp->successful()) {
                Log::warning('KelviqService: checkout creation failed', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 500)]);
                return null;
            }
            return $resp->json('checkoutUrl');
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Create a Kelviq customer-portal session and return the signed URL the
     * customer uses to manage/cancel their subscription, or null on failure.
     */
    public function createPortalSession(int $workspaceId, ?string $kelviqAccountId = null): ?string
    {
        $key = (string) config('billing.kelviq.server_api_key', '');
        if ($key === '') {
            return null;
        }
        // Kelviq's portal keys on the customerId WE supplied at checkout (the
        // workspace id), not on its own account UUID — passing the UUID returns
        // 400 "Invalid customer id". Since a kelviq_account_id only exists once
        // a subscription webhook has landed, preferring it broke the portal for
        // exactly the customers who have a subscription to manage.
        $customerId = (string) $workspaceId ?: (string) $kelviqAccountId;

        try {
            $resp = Http::withToken($key)
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim((string) config('billing.kelviq.api_base'), '/').'/portal/session/', [
                    'customerId' => $customerId,
                ]);

            if (! $resp->successful()) {
                Log::warning('KelviqService: portal session failed', ['status' => $resp->status()]);
                return null;
            }
            $url   = $resp->json('customerPortalUrl');
            $token = $resp->json('token');
            if (! $url) {
                return null;
            }
            return $token ? $url.(str_contains($url, '?') ? '&' : '?').'token='.$token : $url;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }
}
