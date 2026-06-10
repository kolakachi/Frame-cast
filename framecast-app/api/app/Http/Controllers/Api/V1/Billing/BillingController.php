<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\KelviqService;
use App\Services\PaddleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly PaddleService $paddle) {}

    /** Credits per top-up pack key — used to resolve the Kelviq plan identifier. */
    private const TOPUP_CREDITS = ['small' => 500, 'medium' => 1200, 'large' => 2500, 'xl' => 5000];

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
                    'provider'               => config('billing.provider', 'paddle'),
                    'plan_tier'              => $workspace->plan_tier ?? 'free',
                    'plan_status'            => $workspace->plan_status ?? 'active',
                    'plan_renews_at'         => $workspace->plan_renews_at?->toIso8601String(),
                    'paddle_customer_id'     => $workspace->paddle_customer_id,
                    'paddle_subscription_id' => $workspace->paddle_subscription_id,
                    'paddle_client_token'    => $clientToken,
                    'paddle_sandbox'         => $isSandbox,
                    'price_ids'              => $priceIds,
                    'topup_packs'            => config('billing.paddle.topup_packs', []),
                ],
            ],
            'meta' => [],
        ]);
    }

    /**
     * Create a Kelviq checkout session and return the hosted checkout URL.
     * Body: { plan: starter|creator|pro|agency } for a subscription, OR
     *       { topup: small|medium|large|xl } for a one-time credit pack.
     */
    public function kelviqCheckout(Request $request, KelviqService $kelviq): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $workspace = Workspace::query()->find($user->workspace_id);
        if (! $workspace) {
            return response()->json(['error' => ['code' => 'workspace_not_found', 'message' => 'Workspace not found.']], 404);
        }

        $validated = $request->validate([
            'plan'  => ['sometimes', 'string', 'in:starter,creator,pro,agency'],
            'topup' => ['sometimes', 'string', 'in:small,medium,large,xl'],
        ]);

        $planTiers  = config('billing.kelviq.plan_tiers', []);
        $topupPlans = config('billing.kelviq.topup_plans', []);

        if (! empty($validated['plan'])) {
            $identifier   = array_search($validated['plan'], $planTiers, true);
            $chargePeriod = 'MONTHLY';
        } elseif (! empty($validated['topup'])) {
            $identifier   = array_search(self::TOPUP_CREDITS[$validated['topup']], $topupPlans, true);
            $chargePeriod = 'ONE_TIME';
        } else {
            return response()->json(['error' => ['code' => 'missing_selection', 'message' => 'Specify a plan or a top-up pack.']], 422);
        }

        if ($identifier === false || $identifier === null) {
            return response()->json(['error' => ['code' => 'plan_not_configured', 'message' => 'This plan is not configured for checkout yet.']], 422);
        }

        $base    = rtrim((string) config('app.frontend_url'), '/');
        $url = $kelviq->createCheckoutSession(
            (int) $workspace->getKey(),
            (string) $identifier,
            $chargePeriod,
            "{$base}/settings?billing=success",
            "{$base}/settings?billing=cancelled",
        );

        if (! $url) {
            return response()->json(['error' => ['code' => 'checkout_unavailable', 'message' => 'Could not start checkout. Please try again.']], 502);
        }

        return response()->json(['data' => ['url' => $url], 'meta' => []]);
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
