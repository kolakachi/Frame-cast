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
        $eventId = (string) ($event['id'] ?? '');
        $type    = (string) ($event['type'] ?? '');

        if ($eventId !== '' && ! $this->claimEvent($eventId, $type)) {
            return; // already processed
        }

        $object = $event['data']['object'] ?? [];
        if (! is_array($object)) {
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
            DB::table('processed_webhook_events')->insert([
                'provider'     => 'kelviq',
                'event_id'     => $eventId,
                'type'         => $type !== '' ? $type : null,
                'processed_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

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
            $update['billing_renews_at'] = $this->periodEnd($object);
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
            'billing_renews_at' => $this->periodEnd($object),
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
        $customerId = $kelviqAccountId ?: (string) $workspaceId;

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
