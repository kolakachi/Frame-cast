<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $keys = ApiKey::query()
            ->where('workspace_id', $user->workspace_id)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $k) => [
                'id'           => $k->getKey(),
                'name'         => $k->name,
                'key'          => $k->maskedKey(),
                'last_used_at' => $k->last_used_at,
                'created_at'   => $k->created_at,
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

        if (! in_array($user->role, ['owner', 'admin'], true)) {
            return $this->error('forbidden', 'Only a workspace owner or admin can create API keys.', 403);
        }

        $validated = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);

        $active = ApiKey::query()->where('workspace_id', $user->workspace_id)->whereNull('revoked_at')->count();
        if ($active >= self::MAX_ACTIVE) {
            return $this->error('too_many_keys',
                'You already have '.self::MAX_ACTIVE.' active keys. Revoke one before creating another.', 422);
        }

        [$key, $plain] = ApiKey::issue((int) $user->workspace_id, (int) $user->getKey(), $validated['name']);

        // The only time the secret is ever returned. It is not recoverable.
        return response()->json(['data' => [
            'id'   => $key->getKey(),
            'name' => $key->name,
            'key'  => $plain,
            'note' => 'Copy this now — it will not be shown again.',
        ], 'meta' => []], 201);
    }

    public function destroy(Request $request, int $keyId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

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

    private function available(User $user): bool
    {
        return (bool) app(CreditService::class)->limitFor((int) $user->workspace_id, 'api_access');
    }
}
