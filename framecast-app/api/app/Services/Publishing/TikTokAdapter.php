<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TikTokAdapter implements PlatformAdapter
{
    private const AUTH_URL   = 'https://www.tiktok.com/v2/auth/authorize/';
    private const TOKEN_URL  = 'https://open.tiktokapis.com/v2/oauth/token/';
    private const UPLOAD_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
    private const USER_URL   = 'https://open.tiktokapis.com/v2/user/info/';

    private const SCOPES = ['user.info.basic', 'video.publish', 'video.upload'];

    public function platform(): string { return 'tiktok'; }

    public function getAuthUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_key'    => config('services.tiktok.client_key'),
            'redirect_uri'  => config('services.tiktok.redirect_uri'),
            'response_type' => 'code',
            'scope'         => implode(',', self::SCOPES),
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_key'    => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'redirect_uri'  => config('services.tiktok.redirect_uri'),
            'grant_type'    => 'authorization_code',
        ])->throw()->json();

        $user = $this->fetchUserInfo($response['access_token'], $response['open_id']);

        return [
            'platform_user_id'      => $response['open_id'],
            'platform_username'     => $user['username'] ?? null,
            'platform_display_name' => $user['display_name'] ?? null,
            'platform_avatar_url'   => $user['avatar_url'] ?? null,
            'access_token'          => $response['access_token'],
            'refresh_token'         => $response['refresh_token'] ?? null,
            'token_expires_at'      => now()->addSeconds((int) ($response['expires_in'] ?? 86400)),
            'scopes'                => explode(',', $response['scope'] ?? ''),
            'platform_meta'         => ['open_id' => $response['open_id']],
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $account->refresh_token,
            'client_key'    => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'grant_type'    => 'refresh_token',
        ])->throw()->json();

        $account->update([
            'access_token'     => $response['access_token'],
            'refresh_token'    => $response['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 86400)),
            'status'           => 'active',
        ]);
    }

    public function publish(SocialAccount $account, ScheduledPost $post, string $videoPath): string
    {
        if ($account->isTokenExpired()) {
            $this->refreshToken($account);
        }

        $caption = $post->caption ?? '';
        if (! empty($post->hashtags)) {
            $tags = implode(' ', array_map(fn ($t) => '#'.ltrim($t, '#'), $post->hashtags));
            $caption = trim("{$caption} {$tags}");
        }

        $init = Http::withToken($account->access_token)
            ->post(self::UPLOAD_URL, [
                'post_info' => [
                    'title'         => mb_substr($caption, 0, 2200),
                    'privacy_level' => 'SELF_ONLY',
                    'disable_duet'  => false,
                    'disable_stitch'=> false,
                    'disable_comment' => false,
                    'video_cover_timestamp_ms' => 1000,
                ],
                'source_info' => [
                    'source'           => 'FILE_UPLOAD',
                    'video_size'       => filesize($videoPath),
                    'chunk_size'       => filesize($videoPath),
                    'total_chunk_count'=> 1,
                ],
            ])->throw()->json();

        $uploadUrl = $init['data']['upload_url'];
        $publishId = $init['data']['publish_id'];

        Http::withHeaders([
            'Content-Range' => 'bytes 0-'.(filesize($videoPath) - 1).'/'.filesize($videoPath),
            'Content-Type'  => 'video/mp4',
        ])->withBody(file_get_contents($videoPath), 'video/mp4')
            ->put($uploadUrl)
            ->throw();

        return $publishId;
    }

    private function fetchUserInfo(string $accessToken, string $openId): array
    {
        return Http::withToken($accessToken)
            ->get(self::USER_URL, ['fields' => 'open_id,display_name,avatar_url,username'])
            ->json('data.user', []);
    }

    private function privacyLevel(?string $visibility): string
    {
        return match ($visibility) {
            'private'  => 'SELF_ONLY',
            'unlisted' => 'MUTUAL_FOLLOW_FRIENDS',
            default    => 'PUBLIC_TO_EVERYONE',
        };
    }
}
