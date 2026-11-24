<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingWebhookLog;
use App\Services\Launch\JvzooIpn;
use App\Services\Launch\LaunchpadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * JVZoo IPN (owner, 2026-11-24): POST /api/v1/webhooks/jvzoo, set as each OfferIQ product's JVZIPN URL in JVZoo.
 *
 * Signed with cverify against JVZOO_IPN_SECRET (the product's "secret key" in JVZoo); a bad or missing signature, or
 * no secret set, is a 404. A sale grants the same OfferIQ products as Launchpad's offer 622 (WyvStudio side included)
 * through LaunchpadService, idempotent per receipt; a refund or chargeback revokes that receipt. A processing error
 * answers 500 so JVZoo retries; anything else is acknowledged and kept in the billing-webhooks log.
 */
class JvzooWebhookController extends Controller
{
    public function __construct(private readonly LaunchpadService $launch) {}

    public function __invoke(Request $request): Response
    {
        $ipn = JvzooIpn::fromBody($request->getContent());
        $fields = $ipn->fields;
        $receipt = $ipn->receipt();
        $event = $ipn->action() ?: null;

        if (! $ipn->verified((string) config('services.jvzoo.ipn_secret'))) {
            Log::warning('JvzooWebhook: bad signature', ['ip' => $request->ip()]);
            $this->log('invalid_signature', $fields, $event, $receipt, false, 404, 'Bad or missing cverify, or JVZOO_IPN_SECRET unset.', null, $request->ip());

            return response('', 404);
        }

        $product = $ipn->product();
        if ($product === null || $receipt === null) {
            Log::warning('JvzooWebhook: unrecognised product', ['product_id' => $ipn->productId(), 'title' => $fields['cprodtitle'] ?? null]);
            $this->log('ignored', $fields, $event, $receipt, true, 200, 'Unrecognised product or no receipt: nothing granted.', null, $request->ip());

            return response('OK');
        }

        try {
            if ($ipn->isSale()) {
                $out = $this->launch->grant($ipn->email(), $ipn->name(), $product, $receipt,
                    ['amount' => $ipn->amount(), 'currency' => 'USD', 'payload' => $fields], 'jvzoo');
                $this->log($out['created'] ? 'processed' : 'duplicate', $fields, $event, $receipt, true, 200,
                    'Granted: '.$product, (int) $out['purchase']->workspace_id, $request->ip());
            } elseif ($status = $ipn->revokeStatus()) {
                $rows = $this->launch->revoke($receipt, null, $status);
                $this->log($rows === [] ? 'duplicate' : 'processed', $fields, $event, $receipt, true, 200,
                    ucfirst($status).': '.(implode(', ', array_map(fn ($r) => $r->product, $rows)) ?: 'nothing active on this receipt'),
                    $rows[0]->workspace_id ?? null, $request->ip());
            } else {
                $this->log('ignored', $fields, $event, $receipt, true, 200, 'Action not acted on.', null, $request->ip());
            }
        } catch (\Throwable $e) {
            report($e);
            $this->log('error', $fields, $event, $receipt, true, 500, $e->getMessage(), null, $request->ip());

            return response('', 500);
        }

        return response('OK');
    }

    /** @param  array<string, mixed>|null  $payload */
    private function log(string $outcome, ?array $payload, ?string $event, ?string $receipt, bool $valid, int $status, ?string $message, ?int $workspaceId, ?string $ip): void
    {
        BillingWebhookLog::record(
            provider: BillingWebhookLog::PROVIDER_JVZOO,
            outcome: $outcome,
            payload: $payload,
            event: $event,
            eventId: $receipt,
            signatureValid: $valid,
            httpStatus: $status,
            message: $message,
            workspaceId: $workspaceId,
            ip: $ip,
        );
    }
}
