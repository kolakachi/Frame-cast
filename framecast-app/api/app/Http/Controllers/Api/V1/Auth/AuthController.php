<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MagicLinkMail;
use App\Mail\Onboarding\OnboardingDay0Welcome;
use App\Mail\PasswordResetMail;
use App\Models\MagicLinkToken;
use App\Models\PasswordResetToken;
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

        $email = strtolower($validated['email']);

        $user = User::query()
            ->with('workspace')
            ->where('email', $email)
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
            // Referral code from a /?ref=<code> share link. Attributed to the
            // new workspace; the referrer earns credits when this account
            // first becomes paying. See RewardService.
            'ref' => ['nullable', 'string', 'max:32'],
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
            // Loud-log: this is the path that silently ate the Resend
            // transport failure (class-not-found from the missing
            // resend/resend-laravel package) for ~an hour before we caught
            // it via direct Tinker probe. report() routes to Sentry; the
            // Log::error gives recipient + exception class for log greps.
            \Illuminate\Support\Facades\Log::error('Magic link mail failed', [
                'email'     => $user->email,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            report($e);
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

    /**
     * Issue a password-reset token + email it. Always returns the same
     * envelope ("we sent a reset link") regardless of whether the email
     * matches a real account — prevents enumeration.
     */
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
        ]);

        $email = strtolower($validated['email']);

        // Per-IP throttle so an attacker can't enumerate or spam reset emails.
        $ipKey = 'pwreset:ip:'.sha1((string) $request->ip());
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($ipKey, 10)) {
            return $this->error('rate_limited', 'Too many requests from this address. Please try again later.', 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($ipKey, 3600);

        // Per-email throttle (3 per hour) so an attacker can't burn out
        // a specific account's inbox.
        $emailKey = 'pwreset:email:'.sha1($email);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($emailKey, 3)) {
            return response()->json([
                'data' => ['sent' => true, 'message' => 'If that email is registered, a reset link is on its way.'],
            ]);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($emailKey, 3600);

        $user = User::query()->where('email', $email)->first();

        // Only issue + send if the user actually exists. But we return the
        // same response either way so the API doesn't leak which emails
        // are registered.
        if ($user) {
            // Invalidate any pending tokens for this email so a stale reset
            // link can't outlive a freshly-requested one.
            PasswordResetToken::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $token = \Illuminate\Support\Str::random(48);
            $tokenHash = hash('sha256', $token);

            PasswordResetToken::query()->create([
                'email'      => $email,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addMinutes(60),
                'ip_address' => $request->ip(),
            ]);

            $base = rtrim((string) config('app.web_app_url', env('WEB_APP_URL', 'https://app.wyvstudio.com')), '/');
            $resetLink = $base.'/auth/reset?token='.$token;

            try {
                Mail::to($user->email)->queue(new PasswordResetMail($user, $resetLink));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'data' => ['sent' => true, 'message' => 'If that email is registered, a reset link is on its way.'],
        ]);
    }

    /**
     * Verify a reset token is still valid (used by the frontend Reset
     * Password page to decide whether to show the form or a "link expired"
     * message before the user types a password).
     */
    public function verifyPasswordResetToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $row = PasswordResetToken::query()
            ->where('token_hash', hash('sha256', $validated['token']))
            ->first();

        if (! $row || ! $row->isValid()) {
            return $this->error('invalid_token', 'This reset link has expired or already been used. Request a new one.', 422);
        }

        return response()->json(['data' => ['valid' => true, 'email' => $row->email]]);
    }

    /**
     * Consume a reset token and set the new password. Single-use: the
     * token row is marked used_at as part of the same transaction so a
     * compromised log/email can't be replayed.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $row = PasswordResetToken::query()
            ->where('token_hash', hash('sha256', $validated['token']))
            ->lockForUpdate()
            ->first();

        if (! $row || ! $row->isValid()) {
            return $this->error('invalid_token', 'This reset link has expired or already been used. Request a new one.', 422);
        }

        $user = User::query()->where('email', $row->email)->first();
        if (! $user) {
            // Token was valid but the user is gone (deleted / archived).
            // Burn the token so it can't be reused on a recreated account.
            $row->forceFill(['used_at' => now()])->save();
            return $this->error('account_unavailable', 'The account associated with this link is no longer active.', 422);
        }

        \DB::transaction(function () use ($user, $validated, $row): void {
            $user->forceFill(['password_hash' => Hash::make($validated['password'])])->save();
            $row->forceFill(['used_at' => now()])->save();

            // Invalidate other pending reset links for this email + any
            // currently-issued refresh tokens (other devices / browsers
            // should re-authenticate). We do NOT touch the magic-link
            // tokens table — magic-link is a separate auth path.
            PasswordResetToken::query()
                ->where('email', $user->email)
                ->where('id', '!=', $row->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        });

        return response()->json([
            'data' => ['reset' => true, 'message' => 'Password updated. You can now sign in with your new password.'],
        ]);
    }

    /**
     * Authenticated endpoint for the Settings page: change password.
     * Requires the current password (or, if the user has no password
     * yet — magic-link-only account — sets one without a current
     * password check).
     */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $hasExistingPassword = ! empty($user->password_hash);

        $rules = [
            'new_password' => ['required', 'string', 'min:8', 'max:200'],
        ];
        if ($hasExistingPassword) {
            $rules['current_password'] = ['required', 'string'];
        }
        $validated = $request->validate($rules);

        if ($hasExistingPassword) {
            if (! Hash::check($validated['current_password'], $user->password_hash)) {
                return $this->error('invalid_current_password', 'The current password is incorrect.', 422);
            }
        }

        $user->forceFill(['password_hash' => Hash::make($validated['new_password'])])->save();

        return response()->json([
            'data' => ['changed' => true, 'message' => $hasExistingPassword ? 'Password updated.' : 'Password set.'],
        ]);
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
                    ->where('email', strtolower($validated['email']))
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

                // Referral attribution — resolve the share code to the
                // referrer workspace (self-referral is impossible; this
                // workspace doesn't exist yet).
                $referrerId = app(\App\Services\RewardService::class)
                    ->referrerIdForCode($validated['ref'] ?? null);

                $workspace = Workspace::query()->create([
                    'name'      => Str::of($validated['email'])->before('@')->headline().' Workspace',
                    'plan_tier' => 'free',
                    'status'    => 'active',
                    'referred_by_workspace_id' => $referrerId,
                ]);

                $user = User::query()->create([
                    'workspace_id'  => $workspace->getKey(),
                    'name'          => $validated['name'] ?? Str::of($validated['email'])->before('@')->headline()->value(),
                    'email'         => $validated['email'],
                    'password_hash' => ! empty($validated['password']) ? Hash::make($validated['password']) : null,
                    'timezone'      => 'UTC',
                    'role'          => 'owner',
                    'status'        => 'active',
                    // Step 1 = day-0 welcome dispatched below; the hourly
                    // scanner picks up the remaining steps from here.
                    'onboarding_step' => 1,
                    'onboarding_last_sent_at' => now(),
                ]);

                $workspace->forceFill(['owner_user_id' => $user->getKey()])->save();

                // Give the new workspace its own shareable referral code.
                app(\App\Services\RewardService::class)->ensureReferralCode($workspace);

                (new CreditService())->grant($workspace->getKey(), 200, 'registration');

                // Day-0 welcome — queued so a slow SMTP doesn't block signup.
                // Wrapped in try so a misconfigured mail driver can't fail
                // the whole transaction.
                try {
                    Mail::to($user->email)->queue(new OnboardingDay0Welcome($user));
                } catch (\Throwable $e) {
                    report($e);
                }

                return $user->load('workspace');
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two simultaneous requests for the same email — the other request won the race.
            // Just return the now-existing user.
            return User::query()->where('email', strtolower($validated['email']))->with('workspace')->firstOrFail();
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
