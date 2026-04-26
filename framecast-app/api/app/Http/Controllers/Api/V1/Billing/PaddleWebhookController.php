<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Services\PaddleService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PaddleWebhookController extends Controller
{
    public function __construct(private readonly PaddleService $paddle) {}

    public function __invoke(Request $request): Response
    {
        $rawBody   = $request->getContent();
        $signature = $request->header('Paddle-Signature', '');

        if (! $this->paddle->verifyWebhook($rawBody, $signature)) {
            Log::warning('PaddleWebhook: invalid signature', ['ip' => $request->ip()]);
            return response('Unauthorized', 401);
        }

        /** @var array<string, mixed> $payload */
        $payload   = json_decode($rawBody, true) ?? [];
        $eventType = $payload['event_type'] ?? '';

        Log::info('PaddleWebhook: received', ['event_type' => $eventType]);

        match ($eventType) {
            'subscription.created',
            'subscription.updated',
            'subscription.past_due',
            'subscription.paused',
            'subscription.resumed'  => $this->paddle->updateWorkspaceFromSubscription($payload),

            'subscription.cancelled' => $this->paddle->cancelWorkspaceSubscription($payload),

            default => null, // ignore other events
        };

        return response('OK', 200);
    }
}
