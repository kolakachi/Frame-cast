<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Services\FastSpringService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors PaddleWebhookController but for FastSpring. Two structural
 * differences:
 *
 *   - Header: `X-FS-Signature` (base64 HMAC-SHA256) vs Paddle's
 *     `Paddle-Signature` (ts=...;h1=...).
 *
 *   - Payload: FastSpring delivers an `events` array per POST; Paddle
 *     delivers one event per POST. We iterate; the service handles one
 *     event at a time.
 *
 * Unknown event types are ignored (200 OK) so FastSpring stops retrying.
 */
class FastSpringWebhookController extends Controller
{
    public function __construct(private readonly FastSpringService $fastspring) {}

    public function __invoke(Request $request): Response
    {
        $rawBody   = $request->getContent();
        $signature = $request->header('X-FS-Signature', '');

        if (! $this->fastspring->verifyWebhook($rawBody, $signature)) {
            Log::warning('FastSpringWebhook: invalid signature', [
                'ip'           => $request->ip(),
                'has_sig'      => $signature !== '',
                'body_length'  => strlen($rawBody),
            ]);
            return response('Unauthorized', 401);
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($rawBody, true) ?? [];
        $events  = $payload['events'] ?? [];

        if (! is_array($events) || $events === []) {
            Log::info('FastSpringWebhook: empty events array', ['payload_keys' => array_keys($payload)]);
            return response('OK', 200);
        }

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            Log::info('FastSpringWebhook: received', ['event_type' => $type, 'event_id' => $event['id'] ?? null]);

            match ($type) {
                'subscription.activated',
                'subscription.updated',
                'subscription.trial.reminder',
                'subscription.charge.completed',
                'subscription.canceled',
                'subscription.deactivated' => $this->fastspring->applySubscriptionEvent($event),

                // One-time credit top-up.
                'order.completed' => $this->fastspring->handleTopUp($event),

                default => null,
            };
        }

        return response('OK', 200);
    }
}
