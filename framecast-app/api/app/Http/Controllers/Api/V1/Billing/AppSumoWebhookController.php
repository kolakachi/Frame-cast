<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingWebhookLog;
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

        $licenseKey = $payload['license_key'] ?? null;

        // Validation ping — must succeed before the API key even exists.
        if (! empty($payload['test'])) {
            $this->log('ignored', $payload, $event, $licenseKey, true, 200, 'AppSumo validation ping.', $request->ip());

            return $this->ack($event);
        }

        // Pre-launch: no key yet → acknowledge so AppSumo doesn't retry, but
        // don't provision anything.
        if (! $this->appsumo->isConfigured()) {
            Log::warning('AppSumoWebhook: received before configuration', ['event' => $event]);
            $this->log('ignored', $payload, $event, $licenseKey, false, 200, 'Received before AppSumo was configured — nothing provisioned.', $request->ip());

            return $this->ack($event);
        }

        $verified = $this->appsumo->verifyWebhook(
            $rawBody,
            (string) $request->header('X-Appsumo-Signature', ''),
            (string) $request->header('X-Appsumo-Timestamp', ''),
        );
        if (! $verified) {
            Log::warning('AppSumoWebhook: invalid signature', ['ip' => $request->ip(), 'event' => $event]);
            $this->log('invalid_signature', $payload, $event, $licenseKey, false, 401, 'Signature verification failed.', $request->ip());

            return response()->json(['event' => $event, 'success' => false], 401);
        }

        // Never let a processing error trigger an AppSumo retry storm — log and
        // acknowledge. Handlers are idempotent, so a manual replay is safe.
        $outcome = 'processed';
        $message = null;
        try {
            $this->appsumo->handleEvent($payload);
        } catch (\Throwable $e) {
            report($e);
            $outcome = 'error';
            $message = $e->getMessage();
        }

        $this->log($outcome, $payload, $event, $licenseKey, true, 200, $message, $request->ip());

        return $this->ack($event);
    }

    /**
     * Persist the delivery. AppSumo sends no event id, so the license key is
     * stored in its place — it's what you actually search by when a buyer asks
     * why their code didn't work.
     *
     * @param  array<string, mixed>  $payload
     */
    private function log(
        string $outcome,
        array $payload,
        string $event,
        ?string $licenseKey,
        bool $signatureValid,
        int $httpStatus,
        ?string $message,
        ?string $ip,
    ): void {
        BillingWebhookLog::record(
            provider: BillingWebhookLog::PROVIDER_APPSUMO,
            outcome: $outcome,
            payload: $payload,
            event: $event,
            eventId: $licenseKey,
            signatureValid: $signatureValid,
            httpStatus: $httpStatus,
            message: $message,
            workspaceId: $licenseKey
                ? \App\Models\AppSumoLicense::where('license_key', $licenseKey)->value('workspace_id')
                : null,
            ip: $ip,
        );
    }

    private function ack(string $event): JsonResponse
    {
        return response()->json(['event' => $event, 'success' => true], 200);
    }
}
