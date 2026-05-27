<?php

namespace App\Services\Publishing;

use App\Models\Asset;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Facebook Reels publishing via the Meta Graph API.
 *
 * OAuth runs through Facebook Login (shared with InstagramAdapter). Reels
 * publish to the connected Facebook Page using the Page access token.
 * Videos are uploaded by URL (Meta fetches the public storage URL).
 */
class FacebookAdapter implements PlatformAdapter
{
    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'business_management',
    ];

    private const CAPTION_LIMIT = 5000;

    public function platform(): string { return 'facebook'; }

    public function getAuthUrl(string $state): string
    {
        return MetaGraphHelper::authUrl(
            redirectUri: (string) config('services.meta.facebook_redirect_uri'),
            scopes:      self::SCOPES,
            state:       $state,
        );
    }

    public function exchangeCode(string $code): array
    {
        $token = MetaGraphHelper::exchangeCodeForLongLivedToken(
            code:        $code,
            redirectUri: (string) config('services.meta.facebook_redirect_uri'),
        );

        $userToken = $token['access_token'];
        $userInfo  = MetaGraphHelper::fetchUserInfo($userToken);
        $pages     = MetaGraphHelper::fetchPages($userToken);
        $page      = MetaGraphHelper::firstPageOrFail($pages);

        return [
            'platform_user_id'      => (string) $page['id'],
            'platform_username'     => $page['name'] ?? null,
            'platform_display_name' => $page['name'] ?? ($userInfo['name'] ?? null),
            'platform_avatar_url'   => null,
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
            ],
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        $userToken = $account->refresh_token ?: $account->access_token;
        $rotated   = MetaGraphHelper::refreshLongLivedToken($userToken);

        $pages = MetaGraphHelper::fetchPages($rotated['access_token']);
        $match = collect($pages)->firstWhere('id', $account->platform_meta['page_id'] ?? $account->platform_user_id) ?? $pages[0] ?? null;

        if (! $match) {
            throw new RuntimeException('Lost access to the connected Facebook Page. Reconnect Facebook in Settings.');
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

        $pageId    = (string) ($account->platform_meta['page_id'] ?? $account->platform_user_id);
        $pageToken = MetaGraphHelper::pageToken($account);

        $asset = Asset::query()->find($post->exportJob->output_asset_id);
        if (! $asset || ! $asset->storage_url) {
            throw new RuntimeException('Cannot resolve public video URL for Facebook publish.');
        }

        $publicVideoUrl = MetaGraphHelper::publicVideoUrl((string) $asset->storage_url);

        $description = MetaGraphHelper::buildCaption($post->caption, $post->hashtags, self::CAPTION_LIMIT);

        // 1. Start the upload session — get the video_id and upload URL.
        $start = Http::post(MetaGraphHelper::graphUrl("{$pageId}/video_reels"), [
            'upload_phase' => 'start',
            'access_token' => $pageToken,
        ])->throw()->json();

        MetaGraphHelper::throwIfMetaError($start);

        $videoId = $start['video_id'] ?? null;
        if (! $videoId) {
            throw new RuntimeException('Facebook did not return a video id for the Reels upload session.');
        }

        // 2. Hosted upload — Meta fetches the file from our public URL.
        $upload = Http::withHeaders([
            'Authorization' => 'OAuth '.$pageToken,
            'file_url'      => $publicVideoUrl,
        ])->post("https://rupload.facebook.com/video-upload/".MetaGraphHelper::graphVersion()."/{$videoId}")
            ->throw()
            ->json();

        MetaGraphHelper::throwIfMetaError($upload);

        // 3. Publish — finish phase moves the Reel from staging to PUBLISHED.
        $finish = Http::post(MetaGraphHelper::graphUrl("{$pageId}/video_reels"), [
            'access_token' => $pageToken,
            'video_id'     => $videoId,
            'upload_phase' => 'finish',
            'video_state'  => 'PUBLISHED',
            'description'  => $description,
        ])->throw()->json();

        MetaGraphHelper::throwIfMetaError($finish);

        return (string) $videoId;
    }
}
