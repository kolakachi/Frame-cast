<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Project;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Media\StorageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateVisualBriefJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        $project = Project::query()->find($this->projectId);

        if (! $project || ! $project->script_text) {
            GenerateHooksJob::dispatch($this->projectId);

            return;
        }

        try {
            $result = $aiGeneration->generate('visual_brief', [
                'tone' => $project->tone ?: 'neutral',
                'script_text' => mb_substr((string) $project->script_text, 0, 2000),
            ], 300, 0.3, [
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->getKey(),
                    'user_id' => $project->created_by_user_id,
                    'template' => 'visual_brief',
                ],
            ]);

            $brief = $this->parseBrief($result['content']) ?? [];

            // When the creator uploaded reference images, analyze them with vision
            // to extract a style description that anchors every AI-generated scene image.
            if ($project->source_type === 'images') {
                $referenceStyle = $this->analyzeReferenceImages($aiGeneration, $project);

                if ($referenceStyle !== null) {
                    $brief['reference_style'] = $referenceStyle;
                }
            }

            // For AI image projects without uploaded references, generate a visual
            // consistency card that locks character appearance, lighting, and color
            // grade so every scene looks like it belongs to the same video.
            if (
                in_array($project->visual_generation_mode, ['ai_images', 'ai_broll'], true) &&
                ! isset($brief['reference_style']) &&
                ! empty($project->script_text)
            ) {
                try {
                    $cardResult = $aiGeneration->generate('visual_consistency_card', [
                        'script_text'  => mb_substr((string) $project->script_text, 0, 2000),
                        'visual_style' => $project->ai_broll_style ?? $project->default_visual_style ?? 'cinematic',
                        'tone'         => $project->tone ?: 'neutral',
                    ], 200, 0.3, [
                        'usage_context' => [
                            'workspace_id' => $project->workspace_id,
                            'project_id'   => $project->getKey(),
                            'user_id'      => $project->created_by_user_id,
                            'template'     => 'visual_consistency_card',
                        ],
                    ]);

                    $card = trim($cardResult['content']);
                    if ($card !== '') {
                        $brief['consistency_card'] = $card;
                    }
                } catch (\Throwable) {
                    // Non-fatal — generation continues without the consistency card
                }
            }

            if ($brief !== []) {
                $project->forceFill(['visual_brief' => $brief])->save();
            }
        } catch (\Throwable) {
            // Non-fatal — visual matching will fall back to scene-only queries
        }

        GenerateHooksJob::dispatch($this->projectId);
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);

        // Non-fatal — don't mark project as failed, just continue the chain
        GenerateHooksJob::dispatch($this->projectId);
    }

    private function analyzeReferenceImages(AIGenerationAdapter $aiGeneration, Project $project): ?string
    {
        $assetIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) ($project->source_image_asset_ids ?? []),
        )));

        if ($assetIds === []) {
            return null;
        }

        $assets = Asset::query()
            ->whereIn('id', $assetIds)
            ->where('workspace_id', $project->workspace_id)
            ->where('asset_type', 'image')
            ->get();

        $images = [];

        foreach ($assets as $asset) {
            $url = $this->resolvePublicUrl((string) $asset->storage_url);

            if ($url !== null) {
                $images[] = [
                    'url' => $url,
                    'title' => $asset->title ?: 'Reference image',
                ];
            }
        }

        if ($images === []) {
            return null;
        }

        try {
            $result = $aiGeneration->generate('visual_reference_style', [
                'tone' => $project->tone ?: 'neutral',
            ], 200, 0.2, [
                'images' => $images,
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->getKey(),
                    'user_id' => $project->created_by_user_id,
                    'template' => 'visual_reference_style',
                ],
            ]);

            $style = trim($result['content']);

            return $style !== '' ? $style : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePublicUrl(string $storageUrl): ?string
    {
        $storage = app(StorageService::class);

        if ($storage->isManagedUrl($storageUrl)) {
            try {
                return $storage->url($storageUrl);
            } catch (\Throwable) {
                return null;
            }
        }

        if (filter_var($storageUrl, FILTER_VALIDATE_URL)) {
            return $storageUrl;
        }

        return null;
    }

    /**
     * @return array{subject:string,setting:string,palette:string,keywords:list<string>}|null
     */
    private function parseBrief(string $content): ?array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return null;
        }

        $subject = trim((string) ($decoded['subject'] ?? ''));
        $setting = trim((string) ($decoded['setting'] ?? ''));
        $palette = trim((string) ($decoded['palette'] ?? ''));
        $keywords = array_values(array_filter(
            array_map(
                static fn (mixed $k): string => trim((string) $k),
                (array) ($decoded['keywords'] ?? []),
            ),
            static fn (string $k): bool => $k !== '',
        ));

        if ($subject === '' || $keywords === []) {
            return null;
        }

        return [
            'subject' => $subject,
            'setting' => $setting,
            'palette' => $palette,
            'keywords' => array_slice($keywords, 0, 6),
        ];
    }
}
