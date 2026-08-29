<?php

namespace App\Jobs;

use App\Jobs\BreakdownScenesJob;
use App\Events\GenerationProgressed;
use App\Services\CreditService;
use App\Models\Asset;
use App\Models\Niche;
use App\Models\Project;
use App\Models\Series;
use App\Services\Media\MediaTranscriptionService;
use App\Services\Media\StorageService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        // Audio/video uploads transcribe (inside sourceContentForGeneration)
        // before the script is written. Surface that as its OWN stage instead
        // of hiding it under "Writing script".
        $isMedia = in_array($project->source_type, ['audio_upload', 'video_upload'], true);
        GenerationProgressed::dispatch($this->projectId, $isMedia ? 'transcription' : 'script', 'processing');

        $promptTemplateKey = $this->promptTemplateKey((string) $project->source_type);
        $sourceContent = $this->sourceContentForGeneration($project, $transcriptionService);

        // Intent lock: a user-pasted script is kept verbatim by default. Only
        // when they tick "polish my script" does the model touch it — and then
        // it's a light edit (script_polish), never a from-scratch rewrite.
        $isUserScript = $project->source_type === 'script';
        $verbatimScript = $isUserScript && ! (bool) $project->allow_script_edit;
        if ($isUserScript && ! $verbatimScript) {
            $promptTemplateKey = 'script_polish';
        }

        if ($isMedia) {
            GenerationProgressed::dispatch($this->projectId, 'transcription', 'completed');
            GenerationProgressed::dispatch($this->projectId, 'script', 'processing');
        }
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

        if ($verbatimScript) {
            // Kept exactly as the user wrote it — only the scene breakdown splits it.
            $project->forceFill(['script_text' => \App\Support\Utf8::clean(trim((string) $sourceContent))])->save();
        } else {
            $result = $aiGeneration->generate($promptTemplateKey, [
                'source_type' => $project->source_type ?: 'prompt',
                'tone' => $project->tone ?: ($nicheTone ?: 'neutral'),
                'content_goal' => $project->content_goal ?: 'educational',
                'language' => $project->primary_language ?: 'en',
                'niche' => $nicheLabel !== '' ? $nicheLabel : 'general',
                'niche_guidance' => $niche ? $niche->guidance() : Niche::guidanceForSlug(null),
                'platform' => $project->platform_target ?: 'general short-form',
                'duration' => (int) ($project->duration_target_seconds ?: 60),
                'source_content' => $sourceContent,
            ], 1400, 0.35, $options);

            // The model echoes source text back, so a bad byte in the source
            // reaches this save even when the extractor was clean.
            $project->forceFill(['script_text' => \App\Support\Utf8::clean($result['content'])])->save();
        }

        // Infer a title from the finished script when the user didn't set one —
        // reflects what they're actually making, not a truncated prompt. Guarded
        // so a title miss never blocks the pipeline.
        if (trim((string) $project->title) === '') {
            rescue(function () use ($aiGeneration, $project, $nicheLabel): void {
                $titleResult = $aiGeneration->generate('video_title', [
                    'niche' => $nicheLabel !== '' ? $nicheLabel : 'general',
                    'content_goal' => $project->content_goal ?: 'educational',
                    'script_text' => \Illuminate\Support\Str::limit((string) $project->script_text, 1200, ''),
                ], 40, 0.6, ['usage_context' => $this->usageContext($project, ['template' => 'video_title'])]);

                $title = trim((string) ($titleResult['content'] ?? ''));
                $title = trim($title, "\"' \t\n");
                $title = \Illuminate\Support\Str::limit($title, 80, '');

                if ($title !== '') {
                    $project->forceFill(['title' => $title])->save();
                }
            });
        }

        GenerationProgressed::dispatch($this->projectId, 'script', 'completed');
        rescue(fn () => app(CreditService::class)->deduct(
            (int) $project->workspace_id,
            CreditService::SCRIPT,
            'script',
            ['project_id' => $project->getKey(), 'user_id' => $project->created_by_user_id],
        ));
        BreakdownScenesJob::dispatch($project->getKey());
    }

    private function buildSeriesContext(Project $project): string
    {
        if (! $project->series_id) {
            return '';
        }

        $series = Series::query()->find($project->series_id);

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

        // NOTE: the series-level characters() relationship was removed in
        // May 2026 (workspace-level App\Models\Character is the supported home).
        // Eager-loading / reading it here threw RelationNotFoundException, which
        // failed every series episode at the first generation step.

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
            'pdf_upload' => 'script_from_pdf',
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

        if ($project->source_type === 'pdf_upload') {
            return $this->pdfSourceContent($project, $source);
        }

        if ($project->source_type !== 'url') {
            return $source;
        }

        // Throws (with a user-facing message) rather than falling back to
        // "URL: <the url>" — that fallback made the model write a video ABOUT
        // the link instead of its contents, with no error shown. This runs
        // before any credits are deducted, so a failed import costs nothing.
        return app(\App\Services\Generation\UrlContentExtractor::class)->extract($source);
    }

    /**
     * Read an uploaded PDF into prose.
     *
     * Long documents are CONDENSED rather than truncated. Cutting a 40-page
     * report at 6,000 characters would script its first few pages and present
     * the result as a video about the whole document — the same class of
     * confidently-wrong output the URL importer was fixed to stop.
     *
     * Runs before any credit deduction, so a PDF we can't read costs nothing.
     */
    private function pdfSourceContent(Project $project, string $source): string
    {
        $assetId = $this->extractAssetId($source);
        $asset   = $assetId
            ? Asset::query()->whereKey($assetId)->where('workspace_id', $project->workspace_id)->first()
            : null;

        if (! $asset) {
            throw new \RuntimeException('The uploaded PDF could not be found. Please upload it again.');
        }

        // The parser needs a real file on disk; assets live in B2. Pull the
        // bytes to a temp file and always clean it up — a PDF left in /tmp is
        // how a disk fills.
        $bytes = app(StorageService::class)->get($asset->storage_url);

        if ($bytes === null || $bytes === '') {
            throw new \RuntimeException('The uploaded PDF could not be downloaded. Please upload it again.');
        }

        $local = tempnam(sys_get_temp_dir(), 'wyv-pdf-').'.pdf';
        file_put_contents($local, $bytes);

        // Only render scanned pages if the user agreed to pay for it, and only
        // as many as their plan allows. Both come from the dry run they were
        // shown before creating the project.
        $wantsVision = (bool) $project->pdf_read_scanned;
        $visionCap   = $this->visionPageCap($project);

        try {
            $result = app(\App\Services\Generation\PdfContentExtractor::class)
                ->extract($local, $asset->title, $wantsVision && $visionCap > 0, $visionCap);
        } finally {
            rescue(fn () => @unlink($local), null, false);
        }

        $text = $result['text'];

        // Transcribe any rendered pages and fold them in.
        if (! empty($result['renders'])) {
            $text = $this->transcribeRenderedPages($project, $result['renders'], $text);
        }

        // Condense first when the document is bigger than a prompt can carry.
        if ($result['truncated']) {
            $text = $this->summariseDocument($project, $text, $asset->title, $result['pages']);
        }

        $header = "Document: {$asset->title} ({$result['pages']} page".($result['pages'] === 1 ? '' : 's').')';

        return $header."\n\n".$text;
    }

    /**
     * Scanned pages we'll read from this document. Always an int — the plan
     * limit floored by the per-document ceiling. Shared with the dry run so
     * the estimate the user accepted is the amount actually charged.
     */
    private function visionPageCap(Project $project): int
    {
        return CreditService::pdfVisionPageCap($project->workspace?->plan_tier);
    }

    /**
     * Read rendered scanned pages with a vision model and merge them into the
     * document text.
     *
     * Charges per page RESERVED UP FRONT and refunds pages that fail, mirroring
     * the image/animation jobs. CREDIT_CALIBRATION.md records what happens
     * otherwise: jobs that deducted at the end handed out free output whenever
     * a balance hit zero mid-run.
     *
     * @param  list<array<string, mixed>>  $renders
     */
    private function transcribeRenderedPages(Project $project, array $renders, string $text): string
    {
        $credits    = app(CreditService::class);
        $workspace  = (int) $project->workspace_id;
        $reserve    = count($renders) * CreditService::PDF_VISION_PAGE;

        if (! $credits->deduct($workspace, $reserve, 'pdf_vision', [
            'project_id' => $project->getKey(),
            'user_id'    => $project->created_by_user_id,
            'metadata'   => ['pages' => count($renders)],
        ])) {
            // Not enough credits — proceed with the text we already have rather
            // than failing a generation the user has otherwise paid for.
            \Illuminate\Support\Facades\Log::warning('GenerateScriptJob: insufficient credits for PDF vision', [
                'project_id' => $project->getKey(),
                'needed'     => $reserve,
            ]);

            return $text;
        }

        $adapter    = app(AIGenerationAdapter::class);
        $transcribed = [];
        $failed      = 0;

        foreach ($renders as $render) {
            $page = (int) ($render['page'] ?? 0);
            $b64  = (string) ($render['base64'] ?? '');

            if ($b64 === '') {
                $failed++;
                continue;
            }

            $result = rescue(fn () => $adapter->generate('transcribe_document_page', [], 1200, 0.0, [
                // A data URI keeps the page bytes out of storage — these are
                // transient reads, not assets worth keeping.
                'images'        => [['url' => 'data:image/png;base64,'.$b64]],
                // Measured: 'low' transcribes fluently but WRONGLY. Reading a
                // document demands 'high'.
                'image_detail'  => 'high',
                'usage_context' => $this->usageContext($project, ['template' => 'transcribe_document_page']),
            ]), null, false);

            $content = trim((string) ($result['content'] ?? ''));

            if ($content === '') {
                $failed++;
                continue;
            }

            $transcribed[] = "[Page {$page}]\n".\App\Support\Utf8::clean($content);
        }

        if ($failed > 0) {
            rescue(fn () => $credits->refund($workspace, $failed * CreditService::PDF_VISION_PAGE, 'pdf_vision'), null, false);
        }

        if ($transcribed === []) {
            return $text;
        }

        return trim($text."\n\n".implode("\n\n", $transcribed));
    }

    /**
     * Condense an over-long document. Falls back to the leading extract if the
     * summariser fails — a partial script beats failing the whole generation
     * at this point, and the header still tells the model what it's holding.
     */
    private function summariseDocument(Project $project, string $text, string $title, int $pages): string
    {
        $summary = rescue(function () use ($project, $text) {
            $result = app(AIGenerationAdapter::class)->generate('summarize_document', [
                'source_content' => $text,
            ], 900, 0.2, ['usage_context' => $this->usageContext($project, ['template' => 'summarize_document'])]);

            return trim((string) ($result['content'] ?? ''));
        }, null, false);

        if (! $summary) {
            \Illuminate\Support\Facades\Log::warning('GenerateScriptJob: document summary failed, using leading extract', [
                'project_id' => $project->getKey(),
                'title'      => $title,
            ]);

            return $text;
        }

        return "Condensed from a {$pages}-page document:\n{$summary}";
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

}
