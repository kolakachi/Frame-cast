<?php

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Models\Asset;
use App\Services\Media\StorageService;
use App\Services\Publishing\PlatformAdapterFactory;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PublishVideoJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(public readonly int $scheduledPostId)
    {
        $this->onQueue('default');
    }

    public function handle(StorageService $storage): void
    {
        $post = ScheduledPost::query()
            ->with(['socialAccount', 'exportJob', 'project'])
            ->find($this->scheduledPostId);

        if (! $post || $post->status === 'cancelled') {
            return;
        }

        $post->update(['status' => 'processing', 'attempt_count' => $post->attempt_count + 1]);

        $exportJob = $post->exportJob;
        if (! $exportJob || $exportJob->status !== 'completed' || ! $exportJob->output_asset_id) {
            $this->fail($post, 'Export is not ready or has no output.');
            return;
        }

        $asset = Asset::query()->find($exportJob->output_asset_id);
        if (! $asset) {
            $this->fail($post, 'Export asset not found.');
            return;
        }

        $adapter = PlatformAdapterFactory::make($post->platform);

        // Pre-publish token health check — if the account's refresh token is
        // revoked/expired, mark the account as expired, cancel all pending
        // posts using it, and fire one notification (avoids N failures for N posts).
        $account = $post->socialAccount;
        if ($account && $account->isTokenExpired()) {
            try {
                $adapter->refreshToken($account);
            } catch (\Throwable $e) {
                $account->update(['status' => 'expired']);
                // Fail this post + all other pending posts on the same account
                \App\Models\ScheduledPost::query()
                    ->where('social_account_id', $account->getKey())
                    ->whereIn('status', ['scheduled', 'pending', 'processing'])
                    ->update([
                        'status'         => 'failed',
                        'failure_reason' => 'Account connection expired. Please reconnect this account in Settings to resume publishing.',
                    ]);
                $this->fail($post, 'Account connection expired. Please reconnect this account in Settings.');
                $this->notifyAccountExpired($post);
                return;
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'fc_publish_').'.'.$this->ext($asset);

        try {
            // Download from storage to a local temp file
            $contents = $storage->get((string) $asset->storage_url);
            file_put_contents($tmpPath, $contents);

            $platformPostId = $adapter->publish($post->socialAccount, $post, $tmpPath);

            $post->update([
                'status'           => 'published',
                'published_at'     => now(),
                'platform_post_id' => $platformPostId,
                'platform_post_url'=> $this->buildPostUrl($post->platform, $platformPostId, $post->socialAccount),
                'failure_reason'   => null,
            ]);

            $this->notify($post, 'success');

            // First-publish reward — credits for reaching the distribution
            // moment (the core loop). Idempotent (once per workspace) and
            // best-effort so reward bookkeeping can't fail a publish.
            $wsId = (int) ($post->project?->workspace_id ?? 0);
            if ($wsId > 0) {
                rescue(fn () => app(\App\Services\RewardService::class)->grant($wsId, 'first_publish'));
            }

        } catch (\Throwable $e) {
            Log::error('PublishVideoJob failed', [
                'post_id'  => $this->scheduledPostId,
                'platform' => $post->platform,
                'error'    => $e->getMessage(),
            ]);

            $this->fail($post, $e->getMessage());
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'scheduled_post', $this->scheduledPostId);

        $post = ScheduledPost::query()->find($this->scheduledPostId);
        if ($post) {
            $this->fail($post, $exception->getMessage());
        }
    }

    private function fail(ScheduledPost $post, string $reason): void
    {
        $post->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 500)]);
        $this->notify($post, 'failed');
    }

    private function notify(ScheduledPost $post, string $result): void
    {
        rescue(function () use ($post, $result): void {
            $title   = $result === 'success' ? 'Post published' : 'Post failed to publish';
            $message = $result === 'success'
                ? "Your video was published to {$post->platform}."
                : "Publishing to {$post->platform} failed: {$post->failure_reason}";

            \App\Models\WorkspaceNotification::query()->create([
                'workspace_id' => $post->workspace_id,
                'title'        => $title,
                'message'      => $message,
                'type'         => $result === 'success' ? 'success' : 'error',
                'user_id'      => $post->project?->created_by_user_id,
                'metadata_json'=> ['scheduled_post_id' => $post->id, 'platform' => $post->platform],
            ]);
        });
    }

    private function notifyAccountExpired(ScheduledPost $post): void
    {
        rescue(function () use ($post): void {
            \App\Models\WorkspaceNotification::query()->create([
                'workspace_id' => $post->workspace_id,
                'title'        => 'Reconnect required',
                'message'      => "Your {$post->platform} connection has expired. Please reconnect it in Settings to resume publishing.",
                'type'         => 'error',
                'user_id'      => $post->project?->created_by_user_id,
                'metadata_json'=> ['platform' => $post->platform, 'social_account_id' => $post->social_account_id],
            ]);
        });
    }

    private function buildPostUrl(?string $platform, ?string $postId, ?\App\Models\SocialAccount $account): ?string
    {
        if (! $postId) return null;
        return match ($platform) {
            'youtube'   => "https://www.youtube.com/watch?v={$postId}",
            'tiktok'    => $account?->platform_username
                ? "https://www.tiktok.com/@{$account->platform_username}/video/{$postId}"
                : "https://www.tiktok.com/video/{$postId}",
            'instagram' => "https://www.instagram.com/p/{$postId}/",
            'facebook'  => "https://www.facebook.com/{$postId}",
            default     => null,
        };
    }

    private function ext(Asset $asset): string
    {
        if (str_contains((string) $asset->mime_type, 'mp4')) return 'mp4';
        if (str_contains((string) $asset->storage_url, '.mp4')) return 'mp4';
        return 'mp4';
    }
}
