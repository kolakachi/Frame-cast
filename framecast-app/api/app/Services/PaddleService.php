<?php

namespace App\Services;

use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaddleService
{
    private string $apiBase;
    private string $apiKey;
    private string $webhookSecret;

    public function __construct()
    {
        $this->apiBase       = config('billing.paddle.api_base');
        $this->apiKey        = config('billing.paddle.api_key');
        $this->webhookSecret = config('billing.paddle.webhook_secret');
    }

    /**
     * Verify a Paddle webhook signature.
     * Paddle uses ts;h1=<hex_signature> in the Paddle-Signature header.
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): bool
    {
        if (empty($this->webhookSecret)) {
            Log::warning('PaddleService: webhook_secret not configured — skipping verification');
            return false;
        }

        // Header format: ts=<timestamp>;h1=<hmac_sha256_hex>
        $parts = [];
        foreach (explode(';', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        if (empty($parts['ts']) || empty($parts['h1'])) {
            return false;
        }

        $signed   = $parts['ts'] . ':' . $rawBody;
        $expected = hash_hmac('sha256', $signed, $this->webhookSecret);

        return hash_equals($expected, $parts['h1']);
    }

    /**
     * Update workspace fields from a Paddle subscription event payload.
     * Works for subscription.created, subscription.updated, subscription.cancelled, subscription.past_due.
     *
     * @param array<string, mixed> $payload  Full Paddle webhook event payload (already decoded JSON)
     */
    public function updateWorkspaceFromSubscription(array $payload): void
    {
        $data = $payload['data'] ?? [];

        $subscriptionId = $data['id'] ?? null;
        $customerId     = $data['customer_id'] ?? null;
        $status         = $data['status'] ?? null; // active | past_due | paused | cancelled | trialing
        $renewsAt       = $data['next_billed_at'] ?? null;

        // Map Paddle subscription status to our plan_status
        $planStatus = match ($status) {
            'active', 'trialing' => 'active',
            'past_due'           => 'past_due',
            'paused'             => 'paused',
            'cancelled'          => 'cancelled',
            default              => 'active',
        };

        // Determine plan tier from the price ID on the first item
        $priceId  = $data['items'][0]['price']['id'] ?? null;
        $planTier = $this->tierFromPriceId($priceId);

        if (! $subscriptionId) {
            Log::warning('PaddleService: subscription event missing subscription id', ['payload' => $payload]);
            return;
        }

        // Find workspace by subscription id or customer id (handle first-time created)
        $workspace = Workspace::query()
            ->where('paddle_subscription_id', $subscriptionId)
            ->orWhere('paddle_customer_id', $customerId)
            ->first();

        if (! $workspace) {
            Log::warning('PaddleService: no workspace found for subscription', [
                'subscription_id' => $subscriptionId,
                'customer_id'     => $customerId,
            ]);
            return;
        }

        $previousTier = $workspace->plan_tier;
        $newTier      = $planTier ?? $workspace->plan_tier;
        $renewsAtCarbon = $renewsAt ? \Carbon\Carbon::parse($renewsAt) : null;

        $update = [
            'paddle_subscription_id' => $subscriptionId,
            'paddle_customer_id'     => $customerId ?? $workspace->paddle_customer_id,
            'plan_status'            => $planStatus,
            'plan_renews_at'         => $renewsAtCarbon,
            'plan_tier'              => $newTier,
        ];

        // Reset credits when plan changes or on first subscription event
        if ($previousTier !== $newTier || $workspace->billing_renews_at === null) {
            $update['credits_monthly']   = \App\Services\CreditService::PLAN_CREDITS[$newTier] ?? 0;
            $update['billing_renews_at'] = $renewsAtCarbon ?? now()->addMonth();
        }

        $workspace->forceFill($update)->save();

        // Referral conversion reward on first free -> paid move (idempotent).
        if ($previousTier === 'free' && $newTier !== 'free') {
            rescue(fn () => app(\App\Services\RewardService::class)->referralConversion($workspace->fresh()));
        }
    }

    /**
     * Handle a top-up purchase (one-time credit pack).
     *
     * @param array<string, mixed> $payload
     */
    public function handleTopUp(array $payload): void
    {
        $data       = $payload['data'] ?? [];
        $customerId = $data['customer_id'] ?? null;
        $priceId    = $data['items'][0]['price']['id'] ?? null;
        $status     = $data['status'] ?? '';

        // Only credit on completed transactions
        if ($status !== 'completed') {
            return;
        }

        $creditsToGrant = $this->creditsForTopUpPrice($priceId);
        if (! $creditsToGrant || ! $customerId) {
            return;
        }

        $workspace = Workspace::query()->where('paddle_customer_id', $customerId)->first();
        if (! $workspace) {
            Log::warning('PaddleService: top-up — no workspace found', ['customer_id' => $customerId]);
            return;
        }

        // Idempotency: skip if we've already processed this transaction
        $txId = $data['id'] ?? null;
        if ($txId && \Illuminate\Support\Facades\Cache::has("paddle_topup:{$txId}")) {
            return;
        }

        (new CreditService())->grant((int) $workspace->getKey(), $creditsToGrant, 'topup_paddle');

        if ($txId) {
            \Illuminate\Support\Facades\Cache::put("paddle_topup:{$txId}", true, now()->addDays(30));
        }

        Log::info('PaddleService: top-up credited', [
            'workspace_id' => $workspace->getKey(),
            'credits'      => $creditsToGrant,
            'tx_id'        => $txId,
        ]);
    }

    private function creditsForTopUpPrice(?string $priceId): ?int
    {
        if (! $priceId) return null;
        $map = config('billing.paddle.topup_prices', []);
        return $map[$priceId] ?? null;
    }

    /**
     * Handle a subscription cancellation — downgrade to free.
     *
     * @param array<string, mixed> $payload
     */
    public function cancelWorkspaceSubscription(array $payload): void
    {
        $data           = $payload['data'] ?? [];
        $subscriptionId = $data['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $workspace = Workspace::query()
            ->where('paddle_subscription_id', $subscriptionId)
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
     * Create a Paddle customer portal session URL so users can manage their subscription.
     * Returns null if Paddle credentials are not configured.
     */
    public function createPortalSession(Workspace $workspace): ?string
    {
        if (! $workspace->paddle_customer_id || empty($this->apiKey)) {
            return null;
        }

        $response = Http::withToken($this->apiKey)
            ->post("{$this->apiBase}/customers/{$workspace->paddle_customer_id}/portal-sessions", []);

        if (! $response->successful()) {
            Log::warning('PaddleService: failed to create portal session', [
                'workspace_id' => $workspace->getKey(),
                'status'       => $response->status(),
                'body'         => $response->body(),
            ]);
            return null;
        }

        return $response->json('data.urls.general.overview') ?? null;
    }

    /**
     * Map a Paddle price ID back to a workspace plan_tier string.
     */
    private function tierFromPriceId(?string $priceId): ?string
    {
        if (! $priceId) {
            return null;
        }

        $monthly = config('billing.paddle.price_ids', []);
        $yearly  = config('billing.paddle.price_ids_yearly', []);

        foreach ([$monthly, $yearly] as $map) {
            foreach ($map as $tier => $id) {
                if ($id === $priceId) {
                    return $tier;
                }
            }
        }

        return null;
    }
}
