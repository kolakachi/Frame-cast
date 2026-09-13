<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TikTokAdapter implements PlatformAdapter, ProvidesPostUrl
{
    private const AUTH_URL   = 'https://www.tiktok.com/v2/auth/authorize/';
    private const TOKEN_URL  = 'https://open.tiktokapis.com/v2/oauth/token/';
    private const CREATOR_INFO_URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';
    private const UPLOAD_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
    private const USER_URL   = 'https://open.tiktokapis.com/v2/user/info/';
    private const STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';

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

        $creatorInfo = $this->queryCreatorInfo($account);
        $privacyOptions = $creatorInfo['privacy_level_options'] ?? [];

        if (! in_array('SELF_ONLY', $privacyOptions, true)) {
            throw new RuntimeException('TikTok does not currently allow private-only posting for this connected account.');
        }

        if (! $this->isPrivateAccount($privacyOptions)) {
            throw new RuntimeException(
                'TikTok blocked the publish because this app is still unaudited and the connected TikTok account is not set to Private. '
                .'In TikTok, switch the account to Private, reconnect it here, and try again.',
            );
        }

        $caption = $post->caption ?? '';
        if (! empty($post->hashtags)) {
            $tags = implode(' ', array_map(fn ($t) => '#'.ltrim($t, '#'), $post->hashtags));
            $caption = trim("{$caption} {$tags}");
        }

        $initResponse = Http::withToken($account->access_token)
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
            ]);

        $initResponse->throw();

        $init = $initResponse->json();
        $this->throwIfTikTokError($init);

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

    /**
     * Resolve the public URL for a post we just published.
     *
     * publish() can only return TikTok's publish_id — an upload token that
     * looks like `v_pub_file~v2-1.7685075526948374548` and is not the video's
     * id. Pasting it into tiktok.com/video/<id> produced a link that goes
     * nowhere; the real post carried a different id entirely.
     *
     * The id only exists once TikTok finishes processing, so it has to be read
     * back from the status endpoint, which returns it under
     * `publicaly_available_post_id` (TikTok's spelling). Processing is not
     * instant, so this polls briefly rather than asking once.
     */
    public function postUrl(SocialAccount $account, string $postId): ?string
    {
        $username = trim((string) $account->platform_username);

        try {
            // Up to ~20s: TikTok usually finishes within a few seconds, and a
            // missing link is cosmetic, so this must not hold the job open.
            foreach ([0, 3, 5, 5, 7] as $wait) {
                if ($wait > 0) {
                    sleep($wait);
                }

                $response = Http::withToken($account->access_token)
                    ->timeout(15)
                    ->post(self::STATUS_URL, ['publish_id' => $postId]);

                $data = $response->json('data', []);
                $ids = $data['publicaly_available_post_id'] ?? [];
                $videoId = is_array($ids) ? (string) ($ids[0] ?? '') : (string) $ids;

                if ($videoId !== '') {
                    return $username !== ''
                        ? "https://www.tiktok.com/@{$username}/video/{$videoId}"
                        : "https://www.tiktok.com/video/{$videoId}";
                }

                // FAILED means it will never arrive; anything else is still working.
                if (($data['status'] ?? '') === 'FAILED') {
                    break;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TikTokAdapter: post URL lookup failed', [
                'publish_id' => $postId,
                'error'      => $e->getMessage(),
            ]);
        }

        // Better a profile link than a URL built from an upload token, which
        // always 404s and looks broken to the person who just posted.
        return $username !== '' ? "https://www.tiktok.com/@{$username}" : null;
    }

    private function fetchUserInfo(string $accessToken, string $openId): array
    {
        return Http::withToken($accessToken)
            ->get(self::USER_URL, ['fields' => 'open_id,display_name,avatar_url,username'])
            ->json('data.user', []);
    }

    private function queryCreatorInfo(SocialAccount $account): array
    {
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->send('POST', self::CREATOR_INFO_URL, [
                'body' => '{}',
            ]);

        $response->throw();

        $payload = $response->json();
        $this->throwIfTikTokError($payload);

        return $payload['data'] ?? [];
    }

    private function throwIfTikTokError(array $payload): void
    {
        $errorCode = data_get($payload, 'error.code');

        if (! is_string($errorCode) || $errorCode === '' || $errorCode === 'ok') {
            return;
        }

        $message = data_get($payload, 'error.message');

        throw new RuntimeException(match ($errorCode) {
            'unaudited_client_can_only_post_to_private_accounts' => 'TikTok blocked the publish because this app is still unaudited and the connected TikTok account is not set to Private. In TikTok, switch the account to Private, reconnect it here, and try again.',
            'privacy_level_option_mismatch' => 'TikTok rejected the privacy setting for this post. Refresh the connected TikTok account settings and choose one of the privacy options returned by TikTok.',
            'scope_not_authorized' => 'TikTok did not grant the required video publishing scope. Reconnect the TikTok account and approve video publishing access.',
            'spam_risk_too_many_posts' => 'TikTok reached the daily posting cap for this creator account. Try again later.',
            'reached_active_user_cap' => 'TikTok reached the daily active-user cap for this unaudited client. Try again later or continue after your app is approved.',
            default => sprintf('TikTok publish failed with %s%s', $errorCode, is_string($message) && $message !== '' ? ': '.$message : '.'),
        });
    }

    private function isPrivateAccount(array $privacyOptions): bool
    {
        return in_array('FOLLOWER_OF_CREATOR', $privacyOptions, true);
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
