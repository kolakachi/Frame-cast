<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingWebhookLog;
use App\Services\Launch\LaunchCatalogue;
use App\Services\Launch\LaunchpadIpn;
use App\Services\Launch\LaunchpadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Launchpad IPN (owner, 2026-10-21): POST /api/v1/webhooks/launchpad/{token}, set in Launchpad's Offer → Integrations.
 *
 * Launchpad signs nothing, so the secret is the token in the URL (LAUNCHPAD_IPN_TOKEN); a wrong or unset token is a
 * 404. A sale grants its products, a refund or chargeback revokes the receipt; grants are idempotent per receipt and
 * product, so a redelivery changes nothing. A processing error answers 500 so Launchpad retries; anything we choose
 * not to act on (OfferIQ, unknown products or actions) is acknowledged and kept in the billing-webhooks log.
 */
class LaunchpadWebhookController extends Controller
{
    public function __construct(private readonly LaunchpadService $launch) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('services.launchpad.ipn_token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            Log::warning('LaunchpadWebhook: wrong token', ['ip' => $request->ip()]);
            $this->log('invalid_signature', null, null, null, false, 404, 'Wrong or unset IPN token.', null, $request->ip());

            return response()->json(['success' => false], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            $payload = $request->all();
        }
        $ipn = new LaunchpadIpn($payload);
        $event = $ipn->action() ?: null;
        $receipt = $ipn->receipt();
        $products = $ipn->products();

        if ($ipn->isIgnoredProduct()) {
            $this->log('ignored', $payload, $event, $receipt, true, 200, 'Not a WyvStudio product (OfferIQ or the thank-you page).', null, $request->ip());

            return response()->json(['success' => true]);
        }
        if ($products === [] || $receipt === null) {
            Log::warning('LaunchpadWebhook: unrecognised product', ['product_id' => $ipn->productId(), 'name' => $payload['product']['name'] ?? null]);
            $this->log('ignored', $payload, $event, $receipt, true, 200, 'Unrecognised product or no transaction id: nothing granted.', null, $request->ip());

            return response()->json(['success' => true]);
        }

        try {
            if ($ipn->isSale()) {
                [$created, $workspaceId] = $this->grant($ipn, $products, $receipt);
                $this->log($created ? 'processed' : 'duplicate', $payload, $event, $receipt, true, 200,
                    'Granted: '.implode(', ', $products), $workspaceId, $request->ip());
            } elseif ($status = $ipn->revokeStatus()) {
                // A refund of the main product takes back the whole receipt (its bump too); a bump sent on its own only itself.
                $isMain = isset(LaunchpadIpn::PRODUCT_IDS[$ipn->productId()]);
                $rows = $this->launch->revoke($receipt, $isMain ? null : $products[0], $status);
                $this->log($rows === [] ? 'duplicate' : 'processed', $payload, $event, $receipt, true, 200,
                    ucfirst($status).': '.(implode(', ', array_map(fn ($r) => $r->product, $rows)) ?: 'nothing active on this receipt'),
                    $rows[0]->workspace_id ?? null, $request->ip());
            } else {
                $this->log('ignored', $payload, $event, $receipt, true, 200, 'Action not acted on.', null, $request->ip());
            }
        } catch (\Throwable $e) {
            report($e);
            $this->log('error', $payload, $event, $receipt, true, 500, $e->getMessage(), null, $request->ip());

            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Grant each product on the receipt. The main product records the total less its bump's price.
     *
     * @param  list<string>  $products
     * @return array{0: bool, 1: ?int}
     */
    private function grant(LaunchpadIpn $ipn, array $products, string $receipt): array
    {
        $created = false;
        $workspaceId = null;
        $amount = $ipn->amount();
        foreach ($products as $i => $product) {
            $price = (float) LaunchCatalogue::PRODUCTS[$product]['price'];
            $paid = count($products) === 1 ? $amount : ($i === 0 && $amount !== null ? $amount - (float) LaunchCatalogue::PRODUCTS[$products[1]]['price'] : $price);
            $out = $this->launch->grant($ipn->email(), $ipn->name(), $product, $receipt,
                ['amount' => $paid, 'currency' => 'USD', 'payload' => $ipn->payload]);
            $created = $created || $out['created'];
            $workspaceId = (int) $out['purchase']->workspace_id;
        }

        return [$created, $workspaceId];
    }

    /** @param  array<string, mixed>|null  $payload */
    private function log(string $outcome, ?array $payload, ?string $event, ?string $receipt, bool $valid, int $status, ?string $message, ?int $workspaceId, ?string $ip): void
    {
        BillingWebhookLog::record(
            provider: BillingWebhookLog::PROVIDER_LAUNCHPAD,
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
