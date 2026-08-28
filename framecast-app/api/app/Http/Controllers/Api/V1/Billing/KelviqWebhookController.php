<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingWebhookLog;
use App\Services\KelviqService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Kelviq (MOR) webhook endpoint. Verifies the Svix-style signature
 * (webhook-id / webhook-timestamp / webhook-signature), then hands the event
 * to KelviqService for processing (subscription → plan_tier + credits,
 * checkout.completed → top-up grant, etc.). Unknown/unhandled events 200 so
 * Kelviq stops retrying. See spec/KELVIQ_INTEGRATION.md.
 */
class KelviqWebhookController extends Controller
{
    public function __construct(private readonly KelviqService $kelviq) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();

        $verified = $this->kelviq->verifyWebhook(
            $rawBody,
            (string) $request->header('webhook-id', ''),
            (string) $request->header('webhook-timestamp', ''),
            (string) $request->header('webhook-signature', ''),
        );

        // Decode first so a rejected delivery is still recorded with whatever it
        // claimed to be — an unexplained 401 is exactly what made the signature
        // bug invisible for weeks.
        $event   = json_decode($rawBody, true);
        $event   = is_array($event) ? $event : null;
        $type    = $event['type'] ?? null;
        $eventId = $event['id'] ?? null;

        if (! $verified) {
            Log::warning('KelviqWebhook: invalid signature', ['ip' => $request->ip()]);
            BillingWebhookLog::record(
                provider: BillingWebhookLog::PROVIDER_KELVIQ,
                outcome: 'invalid_signature',
                payload: $event,
                event: $type,
                eventId: $eventId,
                signatureValid: false,
                httpStatus: 401,
                message: 'Signature verification failed.',
                ip: $request->ip(),
            );

            return response('Unauthorized', 401);
        }

        if ($event === null) {
            BillingWebhookLog::record(
                provider: BillingWebhookLog::PROVIDER_KELVIQ,
                outcome: 'ignored',
                signatureValid: true,
                message: 'Body was not valid JSON.',
                ip: $request->ip(),
            );

            return response('OK', 200);
        }

        Log::info('KelviqWebhook: received', ['type' => $type, 'id' => $eventId]);

        // Never let a processing error trigger a Kelviq retry storm; we log and
        // acknowledge. (Idempotency in KelviqService makes a manual replay safe.)
        $outcome = 'processed';
        $message = null;
        try {
            $this->kelviq->handleEvent($event);
        } catch (\Throwable $e) {
            report($e);
            $outcome = 'error';
            $message = $e->getMessage();
        }

        BillingWebhookLog::record(
            provider: BillingWebhookLog::PROVIDER_KELVIQ,
            outcome: $outcome,
            payload: $event,
            event: $type,
            eventId: $eventId,
            signatureValid: true,
            message: $message,
            ip: $request->ip(),
        );

        return response('OK', 200);
    }
}
