<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\Media\MediaTranscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranscribeAssetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(
        public readonly int $assetId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(MediaTranscriptionService $transcriptionService): void
    {
        $asset = Asset::query()->find($this->assetId);

        if (! $asset || ! $this->isTranscribable($asset)) {
            return;
        }

        $asset->forceFill([
            'transcription_status' => 'processing',
            'transcription_error' => null,
        ])->save();

        $result = $transcriptionService->transcribeAsset($asset);

        $asset->forceFill([
            'transcript_text' => $result['transcript'],
            'transcription_status' => 'completed',
            'transcription_error' => null,
            'metadata_json' => array_merge($asset->metadata_json ?? [], [
                'transcription_provider' => $result['provider_key'],
                'transcription_model' => $result['model'],
                'transcribed_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        Asset::query()
            ->whereKey($this->assetId)
            ->update([
                'transcription_status' => 'failed',
                'transcription_error' => $exception->getMessage(),
            ]);
    }

    private function isTranscribable(Asset $asset): bool
    {
        return in_array($asset->asset_type, ['audio', 'video'], true)
            || str_starts_with((string) $asset->mime_type, 'audio/')
            || str_starts_with((string) $asset->mime_type, 'video/');
    }
}
