<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kelviq (Merchant of Record) billing integration.
 *
 * Webhooks follow the Svix scheme: headers webhook-id / webhook-timestamp /
 * webhook-signature, signature = base64(HMAC-SHA256(secret, "id.timestamp.body")),
 * header carries space-separated `v1,<sig>` entries. Checkout is created via
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

        // Secret is `kq_whsec_<base64>` (Svix); the HMAC key is the decoded
        // portion. Fall back to the raw string forms in case Kelviq signs with
        // the literal secret.
        $stripped = preg_replace('/^kq_whsec_|^whsec_/', '', $secret);
        $candidateKeys = array_filter([
            base64_decode($stripped, true) ?: null,
            $stripped,
            $secret,
        ]);

        // Header may be "v1,<sig> v1,<sig2>"; compare against each.
        $provided = [];
        foreach (preg_split('/\s+/', trim($signatureHeader)) as $part) {
            $provided[] = str_contains($part, ',') ? substr($part, strpos($part, ',') + 1) : $part;
        }

        foreach ($candidateKeys as $key) {
            $expected = base64_encode(hash_hmac('sha256', $signedContent, $key, true));
            foreach ($provided as $sig) {
                if (hash_equals($expected, $sig)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Dispatch a verified webhook event. Idempotent per event id (Svix retries
     * resend the same id), and credit refills SET (not add) the monthly bucket,
     * so reprocessing is safe.
     *
     * @param  array<string,mixed>  $event
     */
    public function handleEvent(array $event): void
    {
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId !== '' && ! Cache::add("kelviq_evt:{$eventId}", true, 86400)) {
            return; // already processed
        }

        $type   = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        if (! is_array($object)) {
            return;
        }

        match ($type) {
            'subscription.created',
            'subscription.updated',
            'subscription.plan_changed' => $this->applySubscription($object),
            'subscription.cancelled'    => $this->markCancelled($object),
            'invoice.paid'              => $this->handleRenewal($object),
            'checkout.completed'        => $this->handleCheckoutCompleted($object),
            default                     => null,
        };
    }

    private function applySubscription(array $object): void
    {
        $workspace = $this->resolveWorkspace($object);
        if (! $workspace) {
            Log::warning('KelviqService: subscription event — no workspace', ['object_id' => $object['id'] ?? null]);
            return;
        }

        $planId = $object['plan']['identifier'] ?? null;
        $tier   = config('billing.kelviq.plan_tiers')[$planId] ?? null;
        if (! $tier) {
            Log::warning('KelviqService: unknown plan identifier', ['plan' => $planId]);
            return;
        }

        $previousTier = $workspace->plan_tier;
        $update = [
            'kelviq_account_id'      => $object['customer']['id'] ?? $workspace->kelviq_account_id,
            'kelviq_subscription_id' => $object['id'] ?? $workspace->kelviq_subscription_id,
            'plan_tier'              => $tier,
            'plan_status'            => (string) ($object['status'] ?? 'active'),
        ];
        // Refill on tier change (SET, so it's idempotent).
        if ($previousTier !== $tier) {
            $update['credits_monthly']   = CreditService::PLAN_CREDITS[$tier] ?? 0;
            $update['billing_renews_at'] = now()->addMonth();
        }
        $workspace->forceFill($update)->save();

        // First free -> paid conversion rewards the referrer (idempotent).
        if ($previousTier === 'free' && $tier !== 'free') {
            rescue(fn () => app(RewardService::class)->referralConversion($workspace->fresh()));
        }
    }

    /** Recurring charge — refill the current plan's monthly allocation. */
    private function handleRenewal(array $object): void
    {
        $workspace = $this->resolveWorkspace($object);
        if (! $workspace || $workspace->plan_tier === 'free') {
            return;
        }
        $workspace->forceFill([
            'credits_monthly'   => CreditService::PLAN_CREDITS[$workspace->plan_tier] ?? 0,
            'billing_renews_at' => now()->addMonth(),
        ])->save();
    }

    private function markCancelled(array $object): void
    {
        $workspace = $this->resolveWorkspace($object);
        if ($workspace) {
            // Keep access/credits until the period ends; just record the status.
            $workspace->forceFill(['plan_status' => 'cancelled'])->save();
        }
    }

    /** One-time checkout — grant top-up credits when it's a top-up plan. */
    private function handleCheckoutCompleted(array $object): void
    {
        $planId = $object['plan']['identifier'] ?? null;
        $credits = config('billing.kelviq.topup_plans')[$planId] ?? null;
        if (! $credits) {
            return; // not a top-up (subscription checkout is handled by subscription.*)
        }
        $workspace = $this->resolveWorkspace($object);
        if (! $workspace) {
            Log::warning('KelviqService: top-up — no workspace', ['plan' => $planId]);
            return;
        }
        $this->credits->grant((int) $workspace->getKey(), (int) $credits, 'topup_kelviq');
    }

    /**
     * Resolve a Kelviq event object to a workspace, most-reliable first:
     * our metadata.workspace_id → customerId (we pass the workspace id) →
     * stored kelviq_account_id → customer email.
     */
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
            $user = User::query()->where('email', strtolower($email))->with('workspace')->first();
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
            'metadata'       => ['workspace_id' => (string) $workspaceId],
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
}
