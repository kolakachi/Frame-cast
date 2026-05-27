<?php

namespace App\Services\Publishing;

use App\Models\Asset;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Instagram Reels publishing via the Meta Graph API.
 *
 * Requires an Instagram Business or Creator account linked to a Facebook Page
 * the connecting user manages. OAuth runs through Facebook Login (shared with
 * FacebookAdapter); the IG Business Account ID is resolved during connect and
 * cached in `platform_meta.ig_user_id`.
 */
class InstagramAdapter implements PlatformAdapter
{
    private const SCOPES = [
        'instagram_basic',
        'instagram_content_publish',
        'pages_show_list',
        'pages_read_engagement',
        'business_management',
    ];

    private const CAPTION_LIMIT = 2200;

    public function platform(): string { return 'instagram'; }

    public function getAuthUrl(string $state): string
    {
        return MetaGraphHelper::authUrl(
            redirectUri: (string) config('services.meta.instagram_redirect_uri'),
            scopes:      self::SCOPES,
            state:       $state,
        );
    }

    public function exchangeCode(string $code): array
    {
        $token = MetaGraphHelper::exchangeCodeForLongLivedToken(
            code:        $code,
            redirectUri: (string) config('services.meta.instagram_redirect_uri'),
        );

        $userToken = $token['access_token'];
        $userInfo  = MetaGraphHelper::fetchUserInfo($userToken);
        $pages     = MetaGraphHelper::fetchPages($userToken);
        $resolved  = MetaGraphHelper::firstInstagramAccountOrFail($pages);

        $page     = $resolved['page'];
        $igUserId = $resolved['ig_user_id'];

        $igProfile = $page['instagram_business_account'] ?? [];

        return [
            'platform_user_id'      => $igUserId,
            'platform_username'     => $igProfile['username'] ?? null,
            'platform_display_name' => $igProfile['username'] ?? ($userInfo['name'] ?? null),
            'platform_avatar_url'   => $igProfile['profile_picture_url'] ?? null,
            // Store the Page access token (used for publishing). Page tokens don't expire.
            'access_token'          => (string) $page['access_token'],
            // Keep the long-lived user token in refresh_token so we can rotate it.
            'refresh_token'         => $userToken,
            'token_expires_at'      => now()->addSeconds($token['expires_in']),
            'scopes'                => self::SCOPES,
            'platform_meta'         => [
                'meta_user_id' => $userInfo['id'] ?? null,
                'page_id'      => (string) $page['id'],
                'page_name'    => $page['name'] ?? null,
                'ig_user_id'   => $igUserId,
            ],
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        $userToken = $account->refresh_token ?: $account->access_token;
        $rotated   = MetaGraphHelper::refreshLongLivedToken($userToken);

        // Re-resolve the Page token in case the user changed Page admin permissions.
        $pages = MetaGraphHelper::fetchPages($rotated['access_token']);
        $match = collect($pages)->firstWhere('id', $account->platform_meta['page_id'] ?? null) ?? $pages[0] ?? null;

        if (! $match) {
            throw new RuntimeException('Lost access to the connected Facebook Page. Reconnect Instagram in Settings.');
        }

        $account->update([
            'access_token'     => (string) $match['access_token'],
            'refresh_token'    => $rotated['access_token'],
            'token_expires_at' => now()->addSeconds($rotated['expires_in']),
            'status'           => 'active',
        ]);
    }

    public function publish(SocialAccount $account, ScheduledPost $post, string $videoPath): string
    {
        if ($account->isTokenExpired()) {
            $this->refreshToken($account);
        }

        $igUserId  = (string) ($account->platform_meta['ig_user_id'] ?? $account->platform_user_id);
        $pageToken = MetaGraphHelper::pageToken($account);

        $asset = Asset::query()->find($post->exportJob->output_asset_id);
        if (! $asset || ! $asset->storage_url) {
            throw new RuntimeException('Cannot resolve public video URL for Instagram publish.');
        }

        $publicVideoUrl = MetaGraphHelper::publicVideoUrl((string) $asset->storage_url);

        $caption = MetaGraphHelper::buildCaption($post->caption, $post->hashtags, self::CAPTION_LIMIT);

        // 1. Create the media container.
        $containerResponse = Http::post(MetaGraphHelper::graphUrl("{$igUserId}/media"), [
            'media_type'   => 'REELS',
            'video_url'    => $publicVideoUrl,
            'caption'      => $caption,
            'access_token' => $pageToken,
        ])->throw()->json();

        MetaGraphHelper::throwIfMetaError($containerResponse);

        $creationId = $containerResponse['id'] ?? null;
        if (! $creationId) {
            throw new RuntimeException('Instagram did not return a media container id.');
        }

        // 2. Poll until processing completes (or fail).
        $this->waitForContainerReady($creationId, $pageToken);

        // 3. Publish.
        $publishResponse = Http::post(MetaGraphHelper::graphUrl("{$igUserId}/media_publish"), [
            'creation_id'  => $creationId,
            'access_token' => $pageToken,
        ])->throw()->json();

        MetaGraphHelper::throwIfMetaError($publishResponse);

        $mediaId = $publishResponse['id'] ?? null;
        if (! $mediaId) {
            throw new RuntimeException('Instagram did not return a published media id.');
        }

        return (string) $mediaId;
    }

    private function waitForContainerReady(string $creationId, string $pageToken): void
    {
        $maxAttempts = 60;          // ~3 minutes (60 × 3s)
        $delaySeconds = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $status = Http::get(MetaGraphHelper::graphUrl($creationId), [
                'fields'       => 'status_code,status',
                'access_token' => $pageToken,
            ])->throw()->json();

            MetaGraphHelper::throwIfMetaError($status);

            $code = $status['status_code'] ?? null;

            if ($code === 'FINISHED') {
                return;
            }

            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new RuntimeException(
                    'Instagram failed to process the Reel: '.($status['status'] ?? $code),
                );
            }

            sleep($delaySeconds);
        }

        throw new RuntimeException(
            'Instagram is still processing the Reel after 3 minutes. Try again or shorten the video.',
        );
    }
}
