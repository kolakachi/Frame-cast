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
class FacebookAdapter implements PlatformAdapter, SupportsPageSelection, ProvidesPostUrl
{
    // Minimum set for Reels-to-Page: list the user's Pages, read Page fields
    // (which is what yields the Page access token), and publish. Deliberately
    // NOT business_management — nothing here reaches Business Manager assets,
    // we only call /me/accounts, and asking for it both alarms the user on the
    // consent screen and invites heavier App Review scrutiny.
    // pages_read_engagement is NOT about reading engagement metrics here — it
    // gates the per-Page access_token that /me/accounts returns, which is the
    // token we publish with. Dropping it was tested against a clean grant on
    // 2026-08-27 and publishing broke, so it stays. Do not remove it again on
    // the reasoning that "we don't read engagement".
    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
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

        // More than one Page means we cannot know which one they meant. Hand
        // the decision back rather than guessing — publishing a Reel to the
        // wrong Page is not something the user can undo from here.
        if (count($pages) > 1) {
            return MetaGraphHelper::pageSelectionPayload(
                platform:   $this->platform(),
                userToken:  $userToken,
                expiresIn:  (int) $token['expires_in'],
                metaUserId: $userInfo['id'] ?? null,
                pages:      $pages,
            );
        }

        return $this->buildAccountData($userToken, (int) $token['expires_in'], $userInfo['id'] ?? null, MetaGraphHelper::firstPageOrFail($pages), $userInfo['name'] ?? null);
    }

    /** Resolve the user's choice into account data. */
    public function accountDataForPageId(array $pending, string $pageId): array
    {
        $userToken = (string) $pending['user_token'];
        // Re-fetch rather than trusting the cached list: Page admin rights can
        // change between connecting and choosing, and we need a fresh Page token.
        $pages = MetaGraphHelper::fetchPages($userToken);
        $page  = collect($pages)->firstWhere('id', $pageId);

        if (! $page) {
            throw new RuntimeException('That Facebook Page is no longer available on your account. Reconnect and try again.');
        }

        return $this->buildAccountData($userToken, (int) ($pending['expires_in'] ?? 0), $pending['meta_user_id'] ?? null, $page, null);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function buildAccountData(string $userToken, int $expiresIn, ?string $metaUserId, array $page, ?string $userName): array
    {
        return [
            'platform_user_id'      => (string) $page['id'],
            'platform_username'     => $page['name'] ?? null,
            'platform_display_name' => $page['name'] ?? $userName,
            'platform_avatar_url'   => null,
            // Store the Page access token (used for publishing). Page tokens don't expire.
            'access_token'          => MetaGraphHelper::requirePageToken($page),
            // Keep the long-lived user token in refresh_token so we can rotate it.
            'refresh_token'         => $userToken,
            'token_expires_at'      => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'scopes'                => self::SCOPES,
            'platform_meta'         => [
                'meta_user_id' => $metaUserId,
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

    /**
     * Facebook Reels are served from /reel/<video-id> — the bare
     * facebook.com/<id> form we used before does not resolve for a Reel.
     */
    public function postUrl(SocialAccount $account, string $postId): ?string
    {
        return "https://www.facebook.com/reel/{$postId}";
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
