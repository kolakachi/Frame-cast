<?php

namespace App\Jobs;

use App\Jobs\BreakdownScenesJob;
use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Niche;
use App\Models\Project;
use App\Models\Series;
use App\Services\Media\MediaTranscriptionService;
use App\Services\Media\StorageService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Traits\TracksJobFailure;
use Illuminate\Support\Str;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

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
        $options = $project->source_type === 'images'
            ? ['images' => $this->imageInputsForGeneration($project)]
            : [];
        $options['usage_context'] = $this->usageContext($project, [
            'template' => $promptTemplateKey,
        ]);

        $seriesContext = $this->buildSeriesContext($project);
        if ($seriesContext !== '') {
            $options['system_prefix'] = $seriesContext;
        }

        $niche = $project->niche_id ? Niche::query()->find($project->niche_id) : null;
        $nicheLabel = $niche ? trim((string) $niche->name) : '';
        $nicheTone = $niche ? trim((string) ($niche->default_voice_tone ?: '')) : '';

        $result = $aiGeneration->generate($promptTemplateKey, [
            'source_type' => $project->source_type ?: 'prompt',
            'tone' => $project->tone ?: ($nicheTone ?: 'neutral'),
            'content_goal' => $project->content_goal ?: 'educational',
            'language' => $project->primary_language ?: 'en',
            'niche' => $nicheLabel !== '' ? $nicheLabel : 'general',
            'source_content' => $sourceContent,
        ], 1400, 0.35, $options);

        $project->forceFill([
            'script_text' => $result['content'],
        ])->save();

        GenerationProgressed::dispatch($this->projectId, 'script', 'completed');
        BreakdownScenesJob::dispatch($project->getKey());
    }

    private function buildSeriesContext(Project $project): string
    {
        if (! $project->series_id) {
            return '';
        }

        $series = Series::query()->with('characters')->find($project->series_id);

        if (! $series) {
            return '';
        }

        $parts = ['=== SERIES CONTEXT ===', "Series: {$series->name}"];

        if ($series->concept_text) {
            $parts[] = "\nSeries Bible:\n{$series->concept_text}";
        }

        if ($series->audience_text) {
            $parts[] = "\nTarget Audience: {$series->audience_text}";
        }

        if ($series->tone) {
            $parts[] = "\nSeries Tone: {$series->tone}";
        }

        if ($series->episode_format_template) {
            $parts[] = "\nEpisode Format:\n{$series->episode_format_template}";
        }

        $alwaysTags = array_filter((array) ($series->always_include_tags ?? []));
        if ($alwaysTags !== []) {
            $parts[] = "\nAlways include: ".implode(', ', $alwaysTags);
        }

        $neverTags = array_filter((array) ($series->never_include_tags ?? []));
        if ($neverTags !== []) {
            $parts[] = "\nNever include: ".implode(', ', $neverTags);
        }

        $characters = $series->characters->where('status', 'active');
        if ($characters->isNotEmpty()) {
            $parts[] = "\n--- Characters ---";
            foreach ($characters as $character) {
                $line = $character->name;
                if ($character->personality_notes) {
                    $line .= ': '.$character->personality_notes;
                }
                $parts[] = $line;
            }
        }

        $memoryWindow = (int) $series->memory_window;
        if ($memoryWindow > 0) {
            $pastSummaries = Project::query()
                ->where('series_id', $series->getKey())
                ->whereNotNull('series_episode_summary')
                ->where('series_episode_summary', '!=', '')
                ->orderByDesc('series_episode_number')
                ->limit($memoryWindow)
                ->pluck('series_episode_summary', 'series_episode_number')
                ->sortKeys()
                ->all();

            if ($pastSummaries !== []) {
                $parts[] = "\n--- Episode Memory (last {$memoryWindow} episodes) ---";
                foreach ($pastSummaries as $epNum => $summary) {
                    $parts[] = "Episode {$epNum}: {$summary}";
                }
            }
        }

        if ($series->visual_description) {
            $parts[] = "\nVisual Style: {$series->visual_description}";
        }

        $parts[] = '\n=== END SERIES CONTEXT ===';

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function usageContext(Project $project, array $extra = []): array
    {
        return [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'user_id' => $project->created_by_user_id,
            ...$extra,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);

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
            'images' => 'script_from_images',
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

        if ($project->source_type === 'images') {
            return $this->imageSourceContent($project, $source);
        }

        if ($project->source_type !== 'url') {
            return $source;
        }

        return $this->fetchUrlContent($source) ?: "URL: {$source}";
    }

    private function imageSourceContent(Project $project, string $source): string
    {
        $assets = $this->sourceImageAssets($project);
        $lines = [];

        foreach ($assets as $index => $asset) {
            $lines[] = sprintf(
                'Image %d: asset_id:%d title:%s mime_type:%s',
                $index + 1,
                $asset->getKey(),
                $asset->title ?: 'Uploaded image',
                $asset->mime_type ?: 'image/*',
            );
        }

        if ($source !== '') {
            $lines[] = 'User context: '.$source;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{url:string,title:string}>
     */
    private function imageInputsForGeneration(Project $project): array
    {
        $inputs = [];

        foreach ($this->sourceImageAssets($project) as $asset) {
            $url = $this->temporaryAssetUrl($asset);

            if ($url === null) {
                continue;
            }

            $inputs[] = [
                'url' => $url,
                'title' => $asset->title ?: 'Uploaded image',
            ];
        }

        return $inputs;
    }

    /**
     * @return list<Asset>
     */
    private function sourceImageAssets(Project $project): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $project->source_image_asset_ids ?? [],
        )));

        if ($ids === []) {
            return [];
        }

        $positionById = array_flip($ids);

        /** @var list<Asset> $assets */
        $assets = Asset::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('asset_type', 'image')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (Asset $asset): int => $positionById[(int) $asset->getKey()] ?? 0)
            ->values()
            ->all();

        return $assets;
    }

    private function temporaryAssetUrl(Asset $asset): ?string
    {
        $storageUrl = trim((string) $asset->storage_url);

        if ($storageUrl === '') {
            return null;
        }

        $storage = app(StorageService::class);

        if (! $storage->isManagedUrl($storageUrl)) {
            return $storageUrl;
        }

        return $storage->url($storageUrl);
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
