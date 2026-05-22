<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeAdapter implements PlatformAdapter
{
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL  = 'https://www.googleapis.com/upload/youtube/v3/videos';
    private const CHANNEL_URL = 'https://www.googleapis.com/youtube/v3/channels';

    private const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    public function platform(): string { return 'youtube'; }

    public function getAuthUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id'     => config('services.youtube.client_id'),
            'redirect_uri'  => config('services.youtube.redirect_uri'),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'redirect_uri'  => config('services.youtube.redirect_uri'),
            'grant_type'    => 'authorization_code',
        ])->throw()->json();

        $channel = $this->fetchChannelInfo($response['access_token']);

        return [
            'platform_user_id'      => $channel['id'],
            'platform_username'     => $channel['snippet']['customUrl'] ?? null,
            'platform_display_name' => $channel['snippet']['title'] ?? null,
            'platform_avatar_url'   => $channel['snippet']['thumbnails']['default']['url'] ?? null,
            'access_token'          => $response['access_token'],
            'refresh_token'         => $response['refresh_token'] ?? null,
            'token_expires_at'      => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
            'scopes'                => explode(' ', $response['scope'] ?? ''),
            'platform_meta'         => ['channel_id' => $channel['id'], 'channel_title' => $channel['snippet']['title'] ?? null],
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new RuntimeException('No refresh token available for YouTube account.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $account->refresh_token,
            'client_id'     => config('services.youtube.client_id'),
            'client_secret' => config('services.youtube.client_secret'),
            'grant_type'    => 'refresh_token',
        ])->throw()->json();

        $account->update([
            'access_token'    => $response['access_token'],
            'token_expires_at'=> now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
            'status'          => 'active',
        ]);
    }

    public function publish(SocialAccount $account, ScheduledPost $post, string $videoPath): string
    {
        $this->ensureFreshToken($account);

        $description = $post->description ?? $post->caption ?? '';
        $title = $post->title
            ?: $post->project?->title
            ?: mb_substr($description, 0, 100);

        $metadataJson = json_encode([
            'snippet' => [
                'title'       => $title,
                'description' => $description,
                'categoryId'  => $this->categoryId($post->category),
                'tags'        => $post->hashtags ?? [],
            ],
            'status' => [
                'privacyStatus'          => $post->visibility ?? 'public',
                'selfDeclaredMadeForKids'=> false,
            ],
        ]);

        // YouTube requires multipart/related — not multipart/form-data
        $boundary = 'wyvstudio_' . bin2hex(random_bytes(8));
        $videoBytes = file_get_contents($videoPath);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadataJson . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: video/mp4\r\n\r\n";
        $body .= $videoBytes . "\r\n";
        $body .= "--{$boundary}--";

        $response = Http::withToken($account->access_token)
            ->withHeaders(['Content-Type' => "multipart/related; boundary={$boundary}"])
            ->timeout(300)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post(self::UPLOAD_URL.'?'.http_build_query([
                'uploadType' => 'multipart',
                'part'       => 'snippet,status',
            ]))
            ->throw()
            ->json();

        return $response['id'];
    }

    private function ensureFreshToken(SocialAccount $account): void
    {
        if ($account->isTokenExpired()) {
            $this->refreshToken($account);
        }
    }

    private function fetchChannelInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(self::CHANNEL_URL, ['part' => 'snippet', 'mine' => 'true'])
            ->throw()
            ->json();

        return $response['items'][0] ?? throw new RuntimeException('No YouTube channel found for this account.');
    }

    private function categoryId(?string $category): string
    {
        return match ($category) {
            'Education'        => '27',
            'Entertainment'    => '24',
            'People & Blogs'   => '22',
            'Science & Technology' => '28',
            'News & Politics'  => '25',
            default            => '22',
        };
    }
}
