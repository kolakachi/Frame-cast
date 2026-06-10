<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
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

        if (! $verified) {
            Log::warning('KelviqWebhook: invalid signature', ['ip' => $request->ip()]);
            return response('Unauthorized', 401);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            return response('OK', 200);
        }

        Log::info('KelviqWebhook: received', ['type' => $event['type'] ?? null, 'id' => $event['id'] ?? null]);

        // Never let a processing error trigger a Kelviq retry storm; we log and
        // acknowledge. (Idempotency in KelviqService makes a manual replay safe.)
        try {
            $this->kelviq->handleEvent($event);
        } catch (\Throwable $e) {
            report($e);
        }

        return response('OK', 200);
    }
}
