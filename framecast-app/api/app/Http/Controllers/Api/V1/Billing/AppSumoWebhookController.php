<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Services\AppSumoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AppSumo Licensing API v2 webhook endpoint (LTD accounts).
 *
 * AppSumo requires every response to be HTTP 200 with
 * `{"event": "<event>", "success": true}` — including the validation ping it
 * sends when the URL is saved in the Partner Portal. Stays inert (acknowledges
 * without provisioning) until APPSUMO_API_KEY is configured, so the route can
 * ship ahead of go-live.
 */
class AppSumoWebhookController extends Controller
{
    public function __construct(private readonly AppSumoService $appsumo) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            $payload = $request->all(); // AppSumo may send x-www-form-urlencoded
        }
        $event = (string) ($payload['event'] ?? 'purchase');

        // Validation ping — must succeed before the API key even exists.
        if (! empty($payload['test'])) {
            return $this->ack($event);
        }

        // Pre-launch: no key yet → acknowledge so AppSumo doesn't retry, but
        // don't provision anything.
        if (! $this->appsumo->isConfigured()) {
            Log::warning('AppSumoWebhook: received before configuration', ['event' => $event]);
            return $this->ack($event);
        }

        $verified = $this->appsumo->verifyWebhook(
            $rawBody,
            (string) $request->header('X-Appsumo-Signature', ''),
            (string) $request->header('X-Appsumo-Timestamp', ''),
        );
        if (! $verified) {
            Log::warning('AppSumoWebhook: invalid signature', ['ip' => $request->ip(), 'event' => $event]);
            return response()->json(['event' => $event, 'success' => false], 401);
        }

        // Never let a processing error trigger an AppSumo retry storm — log and
        // acknowledge. Handlers are idempotent, so a manual replay is safe.
        try {
            $this->appsumo->handleEvent($payload);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->ack($event);
    }

    private function ack(string $event): JsonResponse
    {
        return response()->json(['event' => $event, 'success' => true], 200);
    }
}
