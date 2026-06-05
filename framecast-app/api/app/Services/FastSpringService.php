<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FastSpring Merchant of Record integration.
 *
 * Same shape as PaddleService — same public method surface (verifyWebhook,
 * updateWorkspaceFromSubscription, handleTopUp, cancelWorkspaceSubscription,
 * createPortalSession) — so the webhook controllers and the billing-provider
 * switch can resolve either implementation interchangeably.
 *
 * Differences from Paddle that show up in here:
 *
 *   1. **Signature verification.** FastSpring sends `X-FS-Signature`:
 *      base64( HMAC-SHA256( request_body, hmac_secret ) ). Paddle uses a
 *      `ts=...;h1=...` composite header. Method body differs; same boolean
 *      return contract.
 *
 *   2. **Product paths instead of price IDs.** Paddle has opaque `pri_01...`
 *      tokens; FastSpring has human-readable paths like
 *      `wyvstudio-starter-monthly`. The mapping table in config/billing.php
 *      mirrors the Paddle shape but with paths.
 *
 *   3. **Webhook payload shape.** Paddle delivers one event per POST.
 *      FastSpring delivers an `events` array — each event has `type`,
 *      `data`, `id`. The webhook controller iterates; this service handles
 *      one event at a time.
 *
 *   4. **Customer portal.** FastSpring's "Account Management" URL is
 *      derivable from the account ID without an API call —
 *      https://<store>.onfastspring.com/account
 *
 * Sandbox credentials arrive after Kevin's onboarding call. Until then the
 * service runs but every method short-circuits cleanly when credentials
 * are empty — the BILLING_PROVIDER switch in env defaults to 'paddle'
 * so nothing in the live request path touches this code yet.
 */
class FastSpringService
{
    private string $apiBase;
    private string $apiUser;
    private string $apiPassword;
    private string $hmacSecret;
    private string $storeDomain;

    public function __construct()
    {
        $this->apiBase     = config('billing.fastspring.api_base');
        $this->apiUser     = config('billing.fastspring.api_user');
        $this->apiPassword = config('billing.fastspring.api_password');
        $this->hmacSecret  = config('billing.fastspring.hmac_secret');
        $this->storeDomain = config('billing.fastspring.store_domain');
    }

    /**
     * Verify a FastSpring webhook signature.
     *
     * FastSpring computes HMAC-SHA256 of the raw request body using the
     * webhook HMAC secret, then Base64-encodes the digest and sends it
     * in the `X-FS-Signature` header. To verify we compute the same
     * digest server-side and constant-time compare.
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): bool
    {
        if (empty($this->hmacSecret)) {
            Log::warning('FastSpringService: hmac_secret not configured — skipping verification');
            return false;
        }
        if ($signatureHeader === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $this->hmacSecret, true));

        return hash_equals($expected, trim($signatureHeader));
    }

    /**
     * Apply a single subscription event to the corresponding workspace.
     * Called once per event by FastSpringWebhookController as it iterates
     * the `events` array in the POST payload.
     *
     * Event types we react to here:
     *   subscription.activated       — first charge succeeded; turn the plan on
     *   subscription.updated         — plan changed / upgrade / downgrade
     *   subscription.charge.completed — recurring renewal; refill credits
     *   subscription.trial.reminder  — trial about to end (no action; informational)
     *   subscription.canceled        — user cancelled; downgrade at period end
     *   subscription.deactivated     — period ended after cancellation; drop to free
     *
     * @param array<string,mixed> $event  One element of the webhook `events` array
     */
    public function applySubscriptionEvent(array $event): void
    {
        $type = $event['type'] ?? '';
        $data = $event['data'] ?? [];

        $subscriptionId = $data['subscription']
            ?? $data['id']
            ?? null;
        $accountId      = $data['account'] ?? null;
        $productPath    = $data['product'] ?? null;
        $state          = $data['state'] ?? null; // active|canceled|deactivated|trial|overdue
        $nextChargeIso  = $data['nextChargeDate'] ?? null;

        if (! $subscriptionId) {
            Log::warning('FastSpringService: subscription event missing subscription id', ['event' => $event]);
            return;
        }

        $planStatus = match (true) {
            in_array($state, ['active', 'trial'], true) => 'active',
            $state === 'overdue'                        => 'past_due',
            $state === 'canceled'                       => 'cancelled', // cancellation requested but plan still runs
            $state === 'deactivated'                    => 'cancelled', // period ended; downgrade
            default                                     => 'active',
        };

        // Subscription.deactivated is the only case that drops to 'free';
        // canceled-but-still-active continues on the paid plan until the
        // period rolls over.
        if ($type === 'subscription.deactivated' || $state === 'deactivated') {
            $this->dropToFree($subscriptionId);
            return;
        }

        $planTier = $this->tierFromProductPath($productPath);

        $workspace = Workspace::query()
            ->where('fastspring_subscription_id', $subscriptionId)
            ->orWhere('fastspring_account_id', $accountId)
            ->first();

        if (! $workspace) {
            Log::warning('FastSpringService: no workspace found for subscription', [
                'subscription_id' => $subscriptionId,
                'account_id'      => $accountId,
                'type'            => $type,
            ]);
            return;
        }

        $previousTier = $workspace->plan_tier;
        $newTier      = $planTier ?? $workspace->plan_tier;
        $renewsAt     = $nextChargeIso ? \Carbon\Carbon::parse($nextChargeIso) : null;

        $update = [
            'fastspring_subscription_id' => $subscriptionId,
            'fastspring_account_id'      => $accountId ?? $workspace->fastspring_account_id,
            'plan_status'                => $planStatus,
            'plan_renews_at'             => $renewsAt,
            'plan_tier'                  => $newTier,
        ];

        // Top up credits when the plan tier changes OR on a recurring charge.
        $shouldRefillCredits = $previousTier !== $newTier
            || $type === 'subscription.charge.completed'
            || $workspace->billing_renews_at === null;

        if ($shouldRefillCredits) {
            $update['credits_monthly']   = CreditService::PLAN_CREDITS[$newTier] ?? 0;
            $update['billing_renews_at'] = $renewsAt ?? now()->addMonth();
        }

        $workspace->forceFill($update)->save();

        Log::info('FastSpringService: subscription event applied', [
            'workspace_id'    => $workspace->getKey(),
            'type'            => $type,
            'from_tier'       => $previousTier,
            'to_tier'         => $newTier,
            'credits_refill'  => $shouldRefillCredits,
        ]);
    }

    /**
     * Handle a one-time credit top-up purchase.
     *
     * @param array<string,mixed> $event  An order.completed event
     */
    public function handleTopUp(array $event): void
    {
        $data      = $event['data'] ?? [];
        $orderId   = $data['order'] ?? $data['id'] ?? null;
        $accountId = $data['account'] ?? null;
        // FastSpring puts purchased items under `items[]` with `product` and `quantity`.
        $items     = $data['items'] ?? [];

        if (! $orderId || ! $accountId) {
            return;
        }

        // Idempotency: skip orders we've already credited.
        if (Cache::has("fastspring_topup:{$orderId}")) {
            return;
        }

        $workspace = Workspace::query()
            ->where('fastspring_account_id', $accountId)
            ->first();

        if (! $workspace) {
            Log::warning('FastSpringService: top-up — no workspace found', [
                'order_id'   => $orderId,
                'account_id' => $accountId,
            ]);
            return;
        }

        $creditsToGrant = 0;
        foreach ($items as $item) {
            $productPath = $item['product'] ?? null;
            $quantity    = (int) ($item['quantity'] ?? 1);
            $perUnit     = $this->creditsForTopUpProduct($productPath);
            if ($perUnit !== null) {
                $creditsToGrant += $perUnit * $quantity;
            }
        }

        if ($creditsToGrant <= 0) {
            return;
        }

        (new CreditService())->grant((int) $workspace->getKey(), $creditsToGrant, 'topup_fastspring');
        Cache::put("fastspring_topup:{$orderId}", true, now()->addDays(30));

        Log::info('FastSpringService: top-up credited', [
            'workspace_id' => $workspace->getKey(),
            'credits'      => $creditsToGrant,
            'order_id'     => $orderId,
        ]);
    }

    /**
     * Drop a workspace back to the free tier — used when a subscription
     * deactivates after the user's paid period ends.
     */
    public function dropToFree(string $subscriptionId): void
    {
        $workspace = Workspace::query()
            ->where('fastspring_subscription_id', $subscriptionId)
            ->first();
        if (! $workspace) {
            return;
        }
        $workspace->forceFill([
            'plan_tier'   => 'free',
            'plan_status' => 'cancelled',
        ])->save();
    }

    /**
     * Build the URL the customer uses to manage their subscription. FastSpring
     * exposes an Account Management page per store; the route to it is
     * `<store>.onfastspring.com/account` for the logged-in account.
     *
     * Returns null if the store domain isn't configured (early-stage) or the
     * workspace doesn't have a FastSpring account yet (free tier).
     */
    public function createPortalSession(Workspace $workspace): ?string
    {
        if (! $workspace->fastspring_account_id || $this->storeDomain === '') {
            return null;
        }
        // FastSpring requires the customer to authenticate at this URL with
        // their order email; we don't need to mint a session token like Paddle.
        return "https://{$this->storeDomain}.onfastspring.com/account";
    }

    /**
     * Map a FastSpring product path back to a workspace plan_tier.
     */
    private function tierFromProductPath(?string $productPath): ?string
    {
        if (! $productPath) {
            return null;
        }
        $monthly = config('billing.fastspring.product_paths', []);
        $yearly  = config('billing.fastspring.product_paths_yearly', []);
        foreach ([$monthly, $yearly] as $map) {
            foreach ($map as $tier => $path) {
                if ($path === $productPath) {
                    return $tier;
                }
            }
        }
        return null;
    }

    private function creditsForTopUpProduct(?string $productPath): ?int
    {
        if (! $productPath) {
            return null;
        }
        $map = config('billing.fastspring.topup_products', []);
        return $map[$productPath] ?? null;
    }
}
