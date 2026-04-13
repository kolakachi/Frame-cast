<?php

namespace App\Jobs;

use App\Jobs\BreakdownScenesJob;
use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Services\Media\MediaTranscriptionService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration, MediaTranscriptionService $transcriptionService): void
    {
        GenerationProgressed::dispatch($this->projectId, 'script', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $promptTemplateKey = $this->promptTemplateKey((string) $project->source_type);
        $sourceContent = $this->sourceContentForGeneration($project, $transcriptionService);

        $result = $aiGeneration->generate($promptTemplateKey, [
            'source_type' => $project->source_type ?: 'prompt',
            'tone' => $project->tone ?: 'neutral',
            'content_goal' => $project->content_goal ?: 'educational',
            'language' => $project->primary_language ?: 'en',
            'source_content' => $sourceContent,
        ]);

        $project->forceFill([
            'script_text' => $result['content'],
        ])->save();

        GenerationProgressed::dispatch($this->projectId, 'script', 'completed');
        BreakdownScenesJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'script', 'failed', $exception->getMessage());
    }

    private function promptTemplateKey(string $sourceType): string
    {
        return match ($sourceType) {
            'url' => 'script_from_url',
            'product_description' => 'script_from_product',
            'csv_topic' => 'script_from_csv',
            'audio_upload' => 'script_from_audio_reference',
            'video_upload' => 'script_from_video_reference',
            default => 'script_from_prompt',
        };
    }

    private function sourceContentForGeneration(Project $project, MediaTranscriptionService $transcriptionService): string
    {
        $source = trim((string) $project->source_content_raw);

        if (in_array($project->source_type, ['audio_upload', 'video_upload'], true)) {
            return $this->mediaSourceContent($project, $source, $transcriptionService);
        }

        if ($project->source_type !== 'url') {
            return $source;
        }

        return $this->fetchUrlContent($source) ?: "URL: {$source}";
    }

    private function mediaSourceContent(Project $project, string $source, MediaTranscriptionService $transcriptionService): string
    {
        $assetId = $this->extractAssetId($source);

        if (! $assetId) {
            return $source;
        }

        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('workspace_id', $project->workspace_id)
            ->first();

        if (! $asset) {
            return $source;
        }

        if (trim((string) $asset->transcript_text) !== '') {
            return "Media asset: {$asset->title}\nTranscript:\n{$asset->transcript_text}";
        }

        $asset->forceFill([
            'transcription_status' => 'processing',
            'transcription_error' => null,
        ])->save();

        try {
            $result = $transcriptionService->transcribeAsset($asset);
        } catch (\Throwable $exception) {
            $asset->forceFill([
                'transcription_status' => 'failed',
                'transcription_error' => $exception->getMessage(),
            ])->save();

            return "Media asset: {$asset->title}\nTranscript unavailable. Use the uploaded media as a source reference and create a repurposing draft.";
        }

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

        return "Media asset: {$asset->title}\nTranscript:\n{$result['transcript']}";
    }

    private function extractAssetId(string $source): ?int
    {
        if (preg_match('/asset_id\s*[:=]\s*(\d+)/i', $source, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^asset:(\d+)$/i', $source, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function fetchUrlContent(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'FramecastBot/1.0'])
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            $text = html_entity_decode(strip_tags((string) $response->body()));
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            return $text !== '' ? Str::limit($text, 6000, '') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
