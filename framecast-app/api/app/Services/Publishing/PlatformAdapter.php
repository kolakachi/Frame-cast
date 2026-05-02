<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;
use App\Models\SocialAccount;

interface PlatformAdapter
{
    /**
     * Return the OAuth authorisation URL to redirect the user to.
     */
    public function getAuthUrl(string $state): string;

    /**
     * Exchange an OAuth code for tokens and return a SocialAccount-ready array.
     *
     * @return array{
     *   platform_user_id: string,
     *   platform_username: string|null,
     *   platform_display_name: string|null,
     *   platform_avatar_url: string|null,
     *   access_token: string,
     *   refresh_token: string|null,
     *   token_expires_at: \DateTimeInterface|null,
     *   scopes: list<string>,
     *   platform_meta: array<string, mixed>,
     * }
     */
    public function exchangeCode(string $code): array;

    /**
     * Refresh an expired access token in-place on the account.
     */
    public function refreshToken(SocialAccount $account): void;

    /**
     * Upload the video file and publish/schedule the post.
     * Returns the platform's post ID on success.
     */
    public function publish(SocialAccount $account, ScheduledPost $post, string $videoPath): string;

    public function platform(): string;
}
