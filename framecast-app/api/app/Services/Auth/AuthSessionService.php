<?php

namespace App\Services\Auth;

use App\Models\AuthSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthSessionService
{
    public function create(User $user, Request $request): array
    {
        $plainTextToken = Str::random(80);

        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $plainTextToken),
            'user_agent' => (string) $request->userAgent(),
            'ip_address' => $request->ip(),
            'expires_at' => CarbonImmutable::now()->addDays((int) config('auth_tokens.refresh_ttl_days')),
            'created_at' => CarbonImmutable::now(),
        ]);

        return [$session, $plainTextToken];
    }

    public function rotate(string $plainTextToken, Request $request): ?array
    {
        $tokenHash = hash('sha256', $plainTextToken);

        $existingSession = AuthSession::query()
            ->with('user.workspace')
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $existingSession) {
            return null;
        }

        if ($existingSession->revoked_at !== null) {
            AuthSession::query()
                ->where('user_id', $existingSession->user_id)
                ->update(['revoked_at' => CarbonImmutable::now()]);

            return null;
        }

        if ($existingSession->expires_at->isPast()) {
            $existingSession->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

            return null;
        }

        $existingSession->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        [$newSession, $newRefreshToken] = $this->create($existingSession->user, $request);

        return [$existingSession->user, $newSession, $newRefreshToken];
    }

    public function revokeCurrent(string $plainTextToken): void
    {
        AuthSession::query()
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->update(['revoked_at' => CarbonImmutable::now()]);
    }

    public function revokeAllForUser(User $user): void
    {
        AuthSession::query()
            ->where('user_id', $user->getKey())
            ->update(['revoked_at' => CarbonImmutable::now()]);
    }
}
