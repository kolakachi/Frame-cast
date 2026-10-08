<?php

namespace App\Services\Publishing;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

/**
 * Revokes a connected account's tokens at the platform, so a copy of them (a leaked dump, a disconnected account) no
 * longer works. revoke() is true only when the platform confirms the token is revoked or already invalid.
 */
class SocialTokenRevoker
{
    /** @return array{revoked: bool, status: int|null, code: string|null} */
    public function revoke(SocialAccount $account): array
    {
        return match ($account->platform) {
            'youtube' => $this->google($account),
            'tiktok' => $this->tiktok($account),
            'instagram', 'facebook' => $this->meta($account),
            default => ['revoked' => false, 'status' => null, 'code' => 'unsupported_platform'],
        };
    }

    /** Revoke at the platform; on confirmation wipe the tokens and ask the user to reconnect. */
    public function revokeAndWipe(SocialAccount $account): array
    {
        $result = $this->revoke($account);
        if ($result['revoked']) {
            $account->forceFill(['access_token' => '', 'refresh_token' => null, 'status' => 'expired', 'token_expires_at' => now()])->save();
        }
        return $result;
    }

    private function google(SocialAccount $account): array
    {
        $token = $account->refresh_token ?: $account->access_token;
        if (! $token) return ['revoked' => true, 'status' => null, 'code' => 'no_token'];
        // Revoking the refresh token revokes the whole grant, access tokens included.
        $r = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
        $code = is_string($r->json('error')) ? $r->json('error') : null;
        return ['revoked' => $r->successful() || ($r->status() === 400 && in_array($code, ['invalid_token', 'invalid_grant'], true)), 'status' => $r->status(), 'code' => $code];
    }

    private function tiktok(SocialAccount $account): array
    {
        $creds = ['client_key' => config('services.tiktok.client_key'), 'client_secret' => config('services.tiktok.client_secret')];
        $send = fn (string $token) => Http::asForm()->timeout(30)->post('https://open.tiktokapis.com/v2/oauth/revoke/', $creds + ['token' => $token]);
        $ok = fn ($r) => $r->successful() && in_array($this->tiktokCode($r), [null, 'ok'], true);
        $r = $account->access_token ? $send($account->access_token) : null;
        // An expired access token cannot be revoked; a fresh one from the refresh token can, and revoking it ends the grant.
        if ((! $r || ! $ok($r)) && $account->refresh_token) {
            $fresh = Http::asForm()->timeout(30)->post('https://open.tiktokapis.com/v2/oauth/token/', $creds + ['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
            if ($fresh->successful() && is_string($fresh->json('access_token'))) $r = $send($fresh->json('access_token'));
            elseif (in_array($this->tiktokCode($fresh), ['invalid_grant', 'refresh_token_invalid'], true)) return ['revoked' => true, 'status' => $fresh->status(), 'code' => 'already_invalid'];
        }
        if (! $r) return ['revoked' => true, 'status' => null, 'code' => 'no_token'];
        return ['revoked' => $ok($r), 'status' => $r->status(), 'code' => $this->tiktokCode($r)];
    }

    private function tiktokCode($r): ?string
    {
        $e = $r->json('error');
        return is_array($e) ? ($e['code'] ?? null) : (is_string($e) ? $e : null);
    }

    private function meta(SocialAccount $account): array
    {
        // The user token (kept as refresh_token) owns the app's permissions; removing them ends every token it issued.
        $token = $account->refresh_token ?: $account->access_token;
        if (! $token) return ['revoked' => true, 'status' => null, 'code' => 'no_token'];
        $r = Http::withToken($token)->timeout(30)->delete(MetaGraphHelper::graphUrl('/me/permissions'));
        $code = $r->json('error.code');
        return ['revoked' => ($r->successful() && $r->json('success') === true) || (int) $code === 190, 'status' => $r->status(), 'code' => $code === null ? null : (string) $code];
    }
}
