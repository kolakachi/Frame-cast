<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Kelviq (MOR) webhook endpoint — SCAFFOLD.
 *
 * This exists so Kelviq has a live URL to register during onboarding/review.
 * It verifies the shared webhook secret, logs the event, and acknowledges
 * (200) so Kelviq stops retrying. Full event PROCESSING (subscription →
 * plan_tier + credit refill, top-up → grant, free→paid → referral reward)
 * is intentionally NOT wired yet: it mirrors FastSpringService exactly, but
 * the precise signature scheme + payload field names must be confirmed
 * against Kelviq's docs once they send sandbox credentials. See
 * spec/KELVIQ_INTEGRATION.md for the completion checklist.
 *
 * Until then FastSpring (primary) / Paddle remain the active providers via
 * config('billing.provider').
 */
class KelviqWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        $secret  = (string) config('billing.kelviq.webhook_secret', '');

        // TODO(kelviq-docs): confirm the exact signature header + scheme.
        // FastSpring uses base64 HMAC-SHA256 in `X-FS-Signature`; assume a
        // comparable `X-Kelviq-Signature` until their docs say otherwise.
        $signature = (string) $request->header('X-Kelviq-Signature', '');
        if ($secret !== '') {
            $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
            if (! hash_equals($expected, $signature)) {
                Log::warning('KelviqWebhook: invalid signature', [
                    'ip'      => $request->ip(),
                    'has_sig' => $signature !== '',
                ]);
                return response('Unauthorized', 401);
            }
        }

        $payload = json_decode($rawBody, true) ?? [];
        Log::info('KelviqWebhook: received (scaffold — not yet processed)', [
            'type'         => $payload['type'] ?? $payload['event'] ?? null,
            'payload_keys' => is_array($payload) ? array_keys($payload) : [],
        ]);

        // TODO(kelviq-docs): map events → KelviqService and process. The
        // business logic is identical to FastSpringService::applySubscriptionEvent
        // / handleTopUp (plan_tier + CreditService refill, RewardService
        // referral conversion on free→paid, top-up grant). Wire once the
        // payload shape is confirmed.

        return response('OK', 200);
    }
}
