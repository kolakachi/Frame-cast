<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Issuing and revoking API keys.
 *
 * Deliberately unreachable with an API key (see AuthenticateWithJwt::API_KEY_NAMESPACE)
 * — a credential must not be able to mint more of itself.
 */
class ApiKeyController extends Controller
{
    private const MAX_ACTIVE = 5;

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($denied = $this->denyUnlessAdmin($user)) {
            return $denied;
        }

        $keys = ApiKey::query()
            ->where('workspace_id', $user->workspace_id)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $k) => [
                'id'                => $k->getKey(),
                'name'              => $k->name,
                'key'               => $k->maskedKey(),
                'last_used_at'      => $k->last_used_at,
                'expires_at'        => $k->expires_at,
                'spend_cap_credits' => $k->spend_cap_credits,
                'spent_this_month'  => $k->spentThisMonth(),
                'created_at'        => $k->created_at,
            ]);

        return response()->json([
            'data' => ['api_keys' => $keys, 'available' => $this->available($user)],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->available($user)) {
            return $this->error('api_access_not_on_plan',
                'API access is available on Creator and Agency plans.', 403);
        }

        if ($denied = $this->denyUnlessAdmin($user)) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'spend_cap_credits' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $expiresAt = isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null;

        // Count and insert under the workspace row lock, so two requests
        // racing at four active keys end with five, not six.
        $issued = DB::transaction(function () use ($user, $validated, $expiresAt): ?array {
            Workspace::query()->whereKey($user->workspace_id)->lockForUpdate()->first();
            $active = ApiKey::query()->where('workspace_id', $user->workspace_id)->whereNull('revoked_at')->count();
            if ($active >= self::MAX_ACTIVE) {
                return null;
            }

            return ApiKey::issue((int) $user->workspace_id, (int) $user->getKey(), $validated['name'], $expiresAt, $validated['spend_cap_credits'] ?? null);
        });
        if ($issued === null) {
            return $this->error('too_many_keys',
                'You already have '.self::MAX_ACTIVE.' active keys. Revoke one before creating another.', 422);
        }
        [$key, $plain] = $issued;

        return $this->issued($key, $plain);
    }

    /**
     * Replace a key with a new secret. The old key stops working at once —
     * rotation exists for "this may have leaked", where a grace period is
     * the leak. Name, expiry and spend cap carry over; the count does not
     * change, so rotation always succeeds at the five-key limit.
     */
    public function rotate(Request $request, int $keyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($denied = $this->denyUnlessAdmin($user)) {
            return $denied;
        }

        $result = DB::transaction(function () use ($user, $keyId): ?array {
            $old = ApiKey::query()->whereKey($keyId)->where('workspace_id', $user->workspace_id)->whereNull('revoked_at')->lockForUpdate()->first();
            if (! $old) {
                return null;
            }
            $old->forceFill(['revoked_at' => now()])->save();

            return ApiKey::issue((int) $user->workspace_id, (int) $user->getKey(), $old->name, $old->expires_at, $old->spend_cap_credits, (int) $old->getKey());
        });
        if ($result === null) {
            return $this->error('not_found', 'API key not found.', 404);
        }
        [$key, $plain] = $result;

        return $this->issued($key, $plain);
    }

    /** The only time the secret is ever returned. It is not recoverable. */
    private function issued(ApiKey $key, string $plain): JsonResponse
    {
        return response()->json(['data' => [
            'id'                => $key->getKey(),
            'name'              => $key->name,
            'key'               => $plain,
            'expires_at'        => $key->expires_at,
            'spend_cap_credits' => $key->spend_cap_credits,
            'rotated_from_id'   => $key->rotated_from_id,
            'note'              => 'Copy this now — it will not be shown again.',
        ], 'meta' => []], 201);
    }

    public function destroy(Request $request, int $keyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($denied = $this->denyUnlessAdmin($user)) {
            return $denied;
        }

        $key = ApiKey::query()
            ->whereKey($keyId)
            ->where('workspace_id', $user->workspace_id)
            ->whereNull('revoked_at')
            ->first();

        if (! $key) {
            return $this->error('not_found', 'API key not found.', 404);
        }

        $key->forceFill(['revoked_at' => now()])->save();

        return response()->json(['data' => ['revoked' => true], 'meta' => []]);
    }

    /**
     * Listing, issuing and revoking are all owner/admin: seeing the list is
     * seeing who can act as the workspace, and a member who cannot mint a
     * key must not be able to revoke someone else's either.
     */
    private function denyUnlessAdmin(User $user): ?JsonResponse
    {
        if (in_array($user->role, ['owner', 'admin'], true) || \App\Services\WorkspaceUsageService::isAdmin($user)) {
            return null;
        }

        return $this->error('forbidden', 'Only a workspace owner or admin can manage API keys.', 403);
    }

    private function available(User $user): bool
    {
        return (bool) app(CreditService::class)->limitFor((int) $user->workspace_id, 'api_access');
    }
}
