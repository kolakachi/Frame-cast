<?php

namespace App\Services\Publishing;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared Meta Graph helpers — used by InstagramAdapter + FacebookAdapter.
 * Instagram Reels and Facebook Reels share the same OAuth (Facebook Login)
 * and the same Graph API surface.
 */
class MetaGraphHelper
{
    public const AUTH_URL  = 'https://www.facebook.com/{version}/dialog/oauth';
    public const TOKEN_URL = 'https://graph.facebook.com/{version}/oauth/access_token';
    public const GRAPH     = 'https://graph.facebook.com/{version}';

    public static function graphVersion(): string
    {
        return (string) config('services.meta.graph_version', 'v21.0');
    }

    public static function graphUrl(string $path = ''): string
    {
        $base = str_replace('{version}', self::graphVersion(), self::GRAPH);
        return $path === '' ? $base : rtrim($base, '/').'/'.ltrim($path, '/');
    }

    public static function authUrl(string $redirectUri, array $scopes, string $state): string
    {
        $base = str_replace('{version}', self::graphVersion(), self::AUTH_URL);

        return $base.'?'.http_build_query([
            'client_id'     => config('services.meta.app_id'),
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(',', $scopes),
            'state'         => $state,
        ]);
    }

    /**
     * Exchange the OAuth `code` for a short-lived user access token, then
     * upgrade to a long-lived (60-day) user token.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public static function exchangeCodeForLongLivedToken(string $code, string $redirectUri): array
    {
        $tokenUrl = str_replace('{version}', self::graphVersion(), self::TOKEN_URL);

        $short = Http::get($tokenUrl, [
            'client_id'     => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ])->throw()->json();

        self::throwIfMetaError($short);

        $long = Http::get($tokenUrl, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.meta.app_id'),
            'client_secret'     => config('services.meta.app_secret'),
            'fb_exchange_token' => $short['access_token'],
        ])->throw()->json();

        self::throwIfMetaError($long);

        return [
            'access_token' => $long['access_token'],
            'expires_in'   => (int) ($long['expires_in'] ?? 5184000),
        ];
    }

    /**
     * Fetch basic user info (id, name) for the authenticated token.
     *
     * @return array{id: string, name: string|null, email?: string}
     */
    public static function fetchUserInfo(string $userToken): array
    {
        return Http::withToken($userToken)
            ->get(self::graphUrl('me'), ['fields' => 'id,name,email'])
            ->throw()
            ->json();
    }

    /**
     * Fetch all Facebook Pages the user manages. Each item carries its own
     * non-expiring `access_token` (Page token) used for publishing.
     *
     * @return array<int, array{id:string, name:string, access_token:string, instagram_business_account?: array{id:string}}>
     */
    public static function fetchPages(string $userToken): array
    {
        $response = Http::withToken($userToken)
            ->get(self::graphUrl('me/accounts'), [
                'fields' => 'id,name,access_token,instagram_business_account{id,username,profile_picture_url}',
            ])
            ->throw()
            ->json();

        return $response['data'] ?? [];
    }

    /**
     * Pick the first Page the user manages. For multi-Page users, page
     * selection is a v2 enhancement — for v1 we use the first one and
     * leave the rest accessible via the platform_meta on the account.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<string, mixed>
     */
    public static function firstPageOrFail(array $pages): array
    {
        if (empty($pages)) {
            throw new RuntimeException(
                'No Facebook Pages found on this account. Instagram and Facebook Reels publishing requires a Facebook Page. Create one in Facebook and try again.',
            );
        }

        return $pages[0];
    }

    /**
     * Find the first Page that has a linked Instagram Business or Creator
     * account. Personal IG accounts cannot publish via the Graph API.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array{page: array<string, mixed>, ig_user_id: string}
     */
    public static function firstInstagramAccountOrFail(array $pages): array
    {
        foreach ($pages as $page) {
            $igId = $page['instagram_business_account']['id'] ?? null;
            if ($igId) {
                return ['page' => $page, 'ig_user_id' => (string) $igId];
            }
        }

        throw new RuntimeException(
            'No Instagram Business or Creator account is linked to any of your Facebook Pages. '
            .'In the Instagram app: Settings → Account → Switch to Professional Account, then link it to a Facebook Page from your Page settings.',
        );
    }

    /**
     * Throw if the Graph API payload contains an error block.
     */
    public static function throwIfMetaError(array $payload): void
    {
        if (! isset($payload['error'])) {
            return;
        }

        $message = $payload['error']['message'] ?? 'Unknown Meta Graph API error';
        $code    = $payload['error']['code'] ?? null;
        $sub     = $payload['error']['error_subcode'] ?? null;

        throw new RuntimeException(sprintf(
            'Meta Graph API error%s: %s',
            $code !== null ? " ($code".($sub ? "/$sub" : '').")" : '',
            $message,
        ));
    }

    /**
     * Refresh a long-lived user token (rolling 60-day refresh).
     * Page tokens themselves don't expire — but the user token they
     * derive from does, so we refresh it before it lapses.
     */
    public static function refreshLongLivedToken(string $currentToken): array
    {
        $tokenUrl = str_replace('{version}', self::graphVersion(), self::TOKEN_URL);

        $response = Http::get($tokenUrl, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.meta.app_id'),
            'client_secret'     => config('services.meta.app_secret'),
            'fb_exchange_token' => $currentToken,
        ])->throw()->json();

        self::throwIfMetaError($response);

        return [
            'access_token' => $response['access_token'],
            'expires_in'   => (int) ($response['expires_in'] ?? 5184000),
        ];
    }

    /**
     * Resolve the public URL Meta needs to fetch the video from.
     */
    public static function publicVideoUrl(string $storageUrl): string
    {
        return app(\App\Services\Media\StorageService::class)->url($storageUrl);
    }

    /**
     * Build a caption string from a ScheduledPost (caption + hashtags).
     */
    public static function buildCaption(?string $caption, ?array $hashtags, int $maxLength): string
    {
        $text = trim((string) $caption);

        if (! empty($hashtags)) {
            $tags = implode(' ', array_map(fn ($t) => '#'.ltrim((string) $t, '#'), $hashtags));
            $text = trim($text === '' ? $tags : $text.' '.$tags);
        }

        return mb_substr($text, 0, $maxLength);
    }

    /**
     * Resolve a SocialAccount's stored Page access token (preferred) or
     * fall back to the user token. Page tokens never expire; user tokens
     * roll every ~60 days. We always store the Page token in `access_token`.
     */
    public static function pageToken(SocialAccount $account): string
    {
        return (string) $account->access_token;
    }
}
