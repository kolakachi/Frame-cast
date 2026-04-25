<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Series;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SummarizeEpisodeJob implements ShouldQueue
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

        if (! $project || ! $project->series_id) {
            return;
        }

        $series = Series::query()->find($project->series_id);

        if (! $series || ! $series->auto_summarise) {
            return;
        }

        $scriptText = trim((string) $project->script_text);

        if ($scriptText === '') {
            return;
        }

        $episodeLabel = $project->series_episode_number
            ? "Episode {$project->series_episode_number}"
            : 'Episode';

        try {
            $result = $aiGeneration->generate('summarize_episode', [
                'series_name' => $series->name,
                'episode_label' => $episodeLabel,
                'episode_title' => $project->title ?: 'Untitled',
                'script_text' => mb_substr($scriptText, 0, 4000),
            ], 300, 0.2, [
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->getKey(),
                    'template' => 'summarize_episode',
                ],
            ]);

            $summary = trim($result['content']);

            if ($summary !== '') {
                $project->forceFill([
                    'series_episode_summary' => mb_substr($summary, 0, 600),
                ])->save();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);
    }
}
