<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Project;
use App\Models\Scene;
use App\Models\ProjectHookOption;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Support\Facades\Log;
use App\Services\CreditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\TracksJobFailure;
use Illuminate\Foundation\Queue\Queueable;

class ScoreHooksJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        $project = Project::query()->find($this->projectId);

        if (! $project) {
            MatchVisualsJob::dispatch($this->projectId);

            return;
        }

        $hooks = ProjectHookOption::query()
            ->where('project_id', $this->projectId)
            ->orderBy('sort_order')
            ->get();

        if ($hooks->isEmpty()) {
            $this->dispatchVisualStep($project);

            return;
        }

        try {
            GenerationProgressed::dispatch($this->projectId, 'hooks_scoring', 'processing');

            $hooksPayload = $hooks->map(fn (ProjectHookOption $h): array => [
                'id' => $h->getKey(),
                'text' => $h->hook_text,
            ])->values()->toArray();

            $result = $aiGeneration->generate('score_hooks', [
                'hooks_json' => (string) json_encode($hooksPayload, JSON_UNESCAPED_SLASHES),
            ], 700, 0.2, [
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->getKey(),
                    'user_id' => $project->created_by_user_id,
                    'template' => 'score_hooks',
                ],
            ]);

            $scores = $this->extractScores($result['content'], $hooks->pluck('id')->all());

            foreach ($scores as $hookId => $data) {
                ProjectHookOption::query()
                    ->whereKey($hookId)
                    ->update([
                        'hook_score' => $data['score'],
                        'hook_score_reason' => $data['reason'],
                    ]);
            }

            GenerationProgressed::dispatch($this->projectId, 'hooks_scoring', 'completed');
        } catch (\Throwable $exception) {
            // Scoring failure is non-blocking — hooks surface without scores.
            report($exception);
            GenerationProgressed::dispatch($this->projectId, 'hooks_scoring', 'failed', $exception->getMessage());
        } finally {
            // Always advance the generation pipeline.
            $this->dispatchVisualStep($project);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);

        report($exception);
        GenerationProgressed::dispatch($this->projectId, 'hooks_scoring', 'failed', $exception->getMessage());
        $project = Project::query()->find($this->projectId);
        $this->dispatchVisualStep($project);
    }

    private function dispatchVisualStep(?Project $project): void
    {
        $mode = $project?->visual_generation_mode;

        // 'images' source type means the creator uploaded reference images for style anchoring —
        // the output is always AI-generated visuals, not stock clips.
        $useAiImages = in_array($mode, ['ai_images', 'ai_video'], true) || $project?->source_type === 'images';

        // No explicit choice used to fall through to stock footage — the
        // weakest output we make, and the majority of everything generated
        // (568 stock scenes vs 177 AI). A CREATOR-tier customer refunded over
        // exactly that, comparing it to HeyGen. Absent a choice, generate AI
        // visuals; only drop to stock when the workspace can't afford them,
        // since the AI job fails per scene rather than falling back itself.
        if ($mode === null && ! $useAiImages && $project !== null) {
            $useAiImages = $this->canAffordAiVisuals($project);
        }

        if ($useAiImages) {
            GenerateProjectAIImagesJob::dispatch($this->projectId);

            return;
        }

        if ($mode === 'waveform') {
            SetWaveformVisualsJob::dispatch($this->projectId);

            return;
        }

        MatchVisualsJob::dispatch($this->projectId);
    }

    /** Can this workspace pay for AI visuals across the project's scenes? */
    private function canAffordAiVisuals(Project $project): bool
    {
        try {
            $scenes = Scene::query()->where('project_id', $project->getKey())->count();
            if ($scenes === 0) {
                return false;
            }

            $perScene = app(\App\Services\Generation\Image\ImageAdapterFactory::class)
                ->generationCost('gpt-image-1', false);

            return app(CreditService::class)
                ->canAfford((int) $project->workspace_id, $scenes * $perScene);
        } catch (\Throwable $e) {
            Log::warning('AI visual affordability check failed — using stock', [
                'project_id' => $project->getKey(),
                'error' => mb_substr($e->getMessage(), 0, 160),
            ]);

            return false;
        }
    }

    /**
     * Parse the AI response and return [hook_id => ['score' => int, 'reason' => string]].
     *
     * @param  list<int>  $validIds
     * @return array<int, array{score:int,reason:string}>
     */
    private function extractScores(string $content, array $validIds): array
    {
        // Strip markdown fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content)) ?? $content;
        $cleaned = preg_replace('/\s*```$/', '', $cleaned) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = $decoded['scores'] ?? $decoded;

        if (! is_array($rows)) {
            return [];
        }

        $result = [];
        $validSet = array_flip($validIds);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $score = (int) ($row['score'] ?? 0);
            $reason = mb_substr(trim((string) ($row['reason'] ?? '')), 0, 255);

            if ($id <= 0 || ! isset($validSet[$id])) {
                continue;
            }

            $score = max(0, min(100, $score));

            if ($score === 0 && $reason === '') {
                continue;
            }

            $result[$id] = [
                'score' => $score,
                'reason' => $reason,
            ];
        }

        return $result;
    }
}
