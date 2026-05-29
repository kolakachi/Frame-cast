<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MagicLinkMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AuthSessionService;
use App\Services\Auth\JwtService;
use App\Services\CreditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthSessionService $authSessionService,
        private readonly JwtService $jwtService,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()
            ->with('workspace')
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! $user->password_hash || ! Hash::check($validated['password'], $user->password_hash)) {
            return $this->error('invalid_credentials', 'Invalid email or password.', 422);
        }

        return $this->issueSessionResponse($user, $request);
    }

    public function magicLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $email = strtolower($validated['email']);
        $ip    = (string) $request->ip();

        // ── Abuse defenses ──────────────────────────────────────────────
        // 1. Block disposable / throwaway email domains. These are the bulk of
        //    real-world signup abuse and cost us free-tier credits + email send.
        if (\App\Services\DisposableEmailBlocker::isDisposable($email)) {
            return $this->error(
                'disposable_email',
                'Please use a permanent email address. Disposable inboxes are not supported.',
                422,
            );
        }

        // 2. Per-IP rate limit — caps mass-signup attempts from one source.
        $ipKey = 'magic:ip:'.sha1($ip);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($ipKey, 5)) {
            $retry = \Illuminate\Support\Facades\RateLimiter::availableIn($ipKey);
            return $this->error(
                'rate_limited',
                "Too many magic link requests from this device. Try again in {$retry} seconds.",
                429,
            );
        }
        \Illuminate\Support\Facades\RateLimiter::hit($ipKey, 600); // 5 attempts / 10 min per IP

        // 3. Per-email rate limit — stops the same address from being targeted.
        $emailKey = 'magic:email:'.sha1($email);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($emailKey, 3)) {
            $retry = \Illuminate\Support\Facades\RateLimiter::availableIn($emailKey);
            return $this->error(
                'rate_limited',
                "We've already sent a few magic links to this address. Try again in {$retry} seconds, or check your inbox.",
                429,
            );
        }
        \Illuminate\Support\Facades\RateLimiter::hit($emailKey, 3600); // 3 attempts / hour per email

        // 4. Per-IP signup cap — blocks one source from creating many accounts.
        //    Counts users (not magic-link requests) created from this IP in 24h.
        $signupKey = 'signup:ip:'.sha1($ip);
        $existingByEmail = \App\Models\User::query()->where('email', $email)->exists();
        if (! $existingByEmail) {
            if ((int) (\Illuminate\Support\Facades\Cache::get($signupKey, 0)) >= 5) {
                return $this->error(
                    'signup_quota_exceeded',
                    'This network has reached the daily new-account limit. Please try again tomorrow.',
                    429,
                );
            }
        }

        $user = $this->findOrCreateUser($validated);

        // Bump the signup-IP counter ONLY when the user is brand new.
        if (! $existingByEmail) {
            \Illuminate\Support\Facades\Cache::put(
                $signupKey,
                (int) \Illuminate\Support\Facades\Cache::get($signupKey, 0) + 1,
                now()->addDay(),
            );
        }

        // Expire any previous unused tokens for this user
        MagicLinkToken::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $plainTextToken = Str::random(96);

        MagicLinkToken::query()->create([
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
            'created_at' => CarbonImmutable::now(),
        ]);

        $magicLink = sprintf(
            '%s/auth/magic?token=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $plainTextToken,
        );

        try {
            Mail::to($user->email)->send(new MagicLinkMail($user, $magicLink));
        } catch (\Throwable $e) {
            return $this->error(
                'email_delivery_failed',
                'We could not deliver the magic link to this email address. Please check the address and try again.',
                422
            );
        }

        return response()->json([
            'data' => [
                'email' => $user->email,
                'sent' => true,
            ],
            'meta' => [],
        ]);
    }

    public function verifyMagicLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $tokenHash = hash('sha256', $validated['token']);

        $magicLinkToken = MagicLinkToken::query()
            ->with('user.workspace')
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $magicLinkToken || $magicLinkToken->used_at || $magicLinkToken->expires_at->isPast() || ! $magicLinkToken->user) {
            return $this->error('invalid_magic_link', 'Magic link is invalid or expired.', 422);
        }

        $magicLinkToken->forceFill([
            'used_at' => CarbonImmutable::now(),
        ])->save();

        return $this->issueSessionResponse($magicLinkToken->user, $request);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = (string) $request->cookie(config('auth_tokens.refresh_cookie_name'));

        if ($refreshToken === '') {
            return $this->error('missing_refresh_token', 'Refresh token cookie is missing.', 401);
        }

        $rotated = $this->authSessionService->rotate($refreshToken, $request);

        if (! $rotated) {
            return $this->clearRefreshCookie(
                $this->error('invalid_refresh_token', 'Refresh token is invalid or expired.', 401),
            );
        }

        [$user, $session, $newRefreshToken] = $rotated;

        return $this->sessionResponse($user->fresh('workspace'), $session, $newRefreshToken);
    }

    public function logout(Request $request): JsonResponse
    {
        $refreshToken = (string) $request->cookie(config('auth_tokens.refresh_cookie_name'));

        if ($refreshToken !== '') {
            $this->authSessionService->revokeCurrent($refreshToken);
        }

        return $this->clearRefreshCookie(response()->json([
            'data' => [
                'logged_out' => true,
            ],
            'meta' => [],
        ]));
    }

    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authSessionService->revokeAllForUser($user);

        return $this->clearRefreshCookie(response()->json([
            'data' => [
                'logged_out_all' => true,
            ],
            'meta' => [],
        ]));
    }

    private function issueSessionResponse(User $user, Request $request): JsonResponse
    {
        [$session, $refreshToken] = $this->authSessionService->create($user, $request);

        return $this->sessionResponse($user, $session, $refreshToken);
    }

    private function findOrCreateUser(array $validated): User
    {
        try {
            return DB::transaction(function () use ($validated): User {
                // Lock the row if it exists to prevent race conditions
                $existingUser = User::query()
                    ->where('email', $validated['email'])
                    ->lockForUpdate()
                    ->first();

                if ($existingUser) {
                    $updates = [];
                    if (! empty($validated['name']) && ! $existingUser->name) {
                        $updates['name'] = $validated['name'];
                    }
                    if (! empty($validated['password']) && ! $existingUser->password_hash) {
                        $updates['password_hash'] = Hash::make($validated['password']);
                    }
                    if ($updates !== []) {
                        $existingUser->fill($updates)->save();
                    }
                    return $existingUser->fresh('workspace');
                }

                $workspace = Workspace::query()->create([
                    'name'      => Str::of($validated['email'])->before('@')->headline().' Workspace',
                    'plan_tier' => 'free',
                    'status'    => 'active',
                ]);

                $user = User::query()->create([
                    'workspace_id'  => $workspace->getKey(),
                    'name'          => $validated['name'] ?? Str::of($validated['email'])->before('@')->headline()->value(),
                    'email'         => $validated['email'],
                    'password_hash' => ! empty($validated['password']) ? Hash::make($validated['password']) : null,
                    'timezone'      => 'UTC',
                    'role'          => 'owner',
                    'status'        => 'active',
                ]);

                $workspace->forceFill(['owner_user_id' => $user->getKey()])->save();

                (new CreditService())->grant($workspace->getKey(), 200, 'registration');

                return $user->load('workspace');
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two simultaneous requests for the same email — the other request won the race.
            // Just return the now-existing user.
            return User::query()->where('email', $validated['email'])->with('workspace')->firstOrFail();
        }
    }

    private function sessionResponse(User $user, $session, string $refreshToken): JsonResponse
    {
        $workspace = $user->workspace;
        $accessToken = $this->jwtService->issue($user, $workspace, $session);

        return $this->withRefreshCookie(response()->json([
            'data' => [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => (int) config('auth_tokens.access_ttl_minutes') * 60,
                'user' => [
                    'id' => $user->getKey(),
                    'workspace_id' => $user->workspace_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'preferences' => array_merge(
                        ['auto_generate_captions' => true, 'preview_before_render' => true, 'auto_music' => true, 'watermark_enabled' => false, 'onboarded' => false],
                        $user->preferences_json ?? [],
                    ),
                ],
            ],
            'meta' => [],
        ]), $refreshToken);
    }

    private function withRefreshCookie(JsonResponse $response, string $refreshToken): JsonResponse
    {
        return $response->cookie(
            config('auth_tokens.refresh_cookie_name'),
            $refreshToken,
            (int) config('auth_tokens.refresh_ttl_days') * 24 * 60,
            '/',
            null,
            app()->isProduction(),
            true,
            false,
            'lax',
        );
    }

    private function clearRefreshCookie(JsonResponse $response): JsonResponse
    {
        return $response->withoutCookie(
            config('auth_tokens.refresh_cookie_name'),
            '/',
        );
    }

    protected function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
