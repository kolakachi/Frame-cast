<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OAuth\AuthorizationServer;
use App\Services\OAuth\OAuthException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP face of the authorization server. Public endpoints answer in RFC
 * 6749 error shape; the two session-authenticated consent endpoints answer
 * in the app's envelope because the SPA calls them.
 */
class OAuthController extends Controller
{
    public function __construct(private readonly AuthorizationServer $server)
    {
    }

    public function metadata(): JsonResponse
    {
        return response()->json($this->server->metadata())->header('Cache-Control', 'public, max-age=3600');
    }

    public function register(Request $request): JsonResponse
    {
        try {
            return response()->json($this->server->register($request->all()), 201)->header('Cache-Control', 'no-store');
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }
    }

    public function token(Request $request): JsonResponse
    {
        try {
            return response()->json($this->server->token($request->all()))->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if ($token !== '') {
            $this->server->revoke($token);
        }

        return response()->json(['revoked' => true])->header('Cache-Control', 'no-store');
    }

    /** What the consent page shows. Session-authenticated. */
    public function context(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $ctx = $this->server->context($user, $request->all());
        } catch (OAuthException $e) {
            return $this->error($e->error, $e->getMessage(), $e->status === 403 ? 403 : 422);
        }

        return response()->json(['data' => [
            'client' => ['id' => $ctx['client']->getKey(), 'name' => $ctx['client']->name],
            'scopes' => $ctx['scopes'],
            'permissions' => ['Estimate, create and fetch videos', 'Spend the workspace\'s credits, up to any cap you set', 'See the workspace\'s plan, limits and balance'],
            'workspaces' => $ctx['workspaces'],
            'key_lifetime_days' => (int) config('developer.oauth.key_ttl_days'),
        ], 'meta' => []]);
    }

    /** Approve or deny. Session-authenticated; owner/admin of the chosen workspace. */
    public function decide(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,deny'],
            'workspace_id' => ['required_if:decision,approve', 'nullable', 'integer'],
        ]);

        try {
            $url = $validated['decision'] === 'approve'
                ? $this->server->approve($user, $request->all(), (int) $validated['workspace_id'])
                : $this->server->deny($request->all());
        } catch (OAuthException $e) {
            return $this->error($e->error, $e->getMessage(), $e->status === 403 ? 403 : 422);
        }

        return response()->json(['data' => ['redirect_url' => $url], 'meta' => []]);
    }

    private function oauthError(OAuthException $e): JsonResponse
    {
        return response()->json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status)
            ->header('Cache-Control', 'no-store');
    }
}
