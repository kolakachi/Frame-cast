<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PaddleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly PaddleService $paddle) {}

    /**
     * Return current billing status for the workspace.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $workspace = Workspace::query()->find($user->workspace_id);

        if (! $workspace) {
            return response()->json(['error' => ['code' => 'workspace_not_found', 'message' => 'Workspace not found.']], 404);
        }

        $isSandbox  = config('billing.paddle.sandbox', true);
        $priceIds   = config('billing.paddle.price_ids', []);
        $clientToken = config('billing.paddle.client_token', '');

        return response()->json([
            'data' => [
                'billing' => [
                    'plan_tier'              => $workspace->plan_tier ?? 'free',
                    'plan_status'            => $workspace->plan_status ?? 'active',
                    'plan_renews_at'         => $workspace->plan_renews_at?->toIso8601String(),
                    'paddle_customer_id'     => $workspace->paddle_customer_id,
                    'paddle_subscription_id' => $workspace->paddle_subscription_id,
                    'paddle_client_token'    => $clientToken,
                    'paddle_sandbox'         => $isSandbox,
                    'price_ids'              => $priceIds,
                ],
            ],
            'meta' => [],
        ]);
    }

    /**
     * Return a Paddle customer portal URL so the user can manage their subscription.
     */
    public function portal(Request $request): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $workspace = Workspace::query()->find($user->workspace_id);

        if (! $workspace) {
            return response()->json(['error' => ['code' => 'workspace_not_found', 'message' => 'Workspace not found.']], 404);
        }

        if (! $workspace->paddle_customer_id) {
            return response()->json(['error' => ['code' => 'no_subscription', 'message' => 'No active subscription found.']], 422);
        }

        $url = $this->paddle->createPortalSession($workspace);

        if (! $url) {
            return response()->json(['error' => ['code' => 'portal_unavailable', 'message' => 'Could not generate billing portal link. Please try again.']], 502);
        }

        return response()->json([
            'data' => ['url' => $url],
            'meta' => [],
        ]);
    }
}
