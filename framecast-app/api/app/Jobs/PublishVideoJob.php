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

        $tmpPath = tempnam(sys_get_temp_dir(), 'fc_publish_').'.'.$this->ext($asset);

        try {
            // Download from storage to a local temp file
            $contents = $storage->get((string) $asset->storage_url);
            file_put_contents($tmpPath, $contents);

            $adapter = PlatformAdapterFactory::make($post->platform);
            $platformPostId = $adapter->publish($post->socialAccount, $post, $tmpPath);

            $post->update([
                'status'           => 'published',
                'published_at'     => now(),
                'platform_post_id' => $platformPostId,
                'failure_reason'   => null,
            ]);

            $this->notify($post, 'success');

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

    private function ext(Asset $asset): string
    {
        if (str_contains((string) $asset->mime_type, 'mp4')) return 'mp4';
        if (str_contains((string) $asset->storage_url, '.mp4')) return 'mp4';
        return 'mp4';
    }
}
