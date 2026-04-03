<?php

namespace App\Jobs;

use App\Jobs\BreakdownScenesJob;
use App\Models\Project;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $promptTemplateKey = $project->source_type === 'url' ? 'script_from_url' : 'script_from_prompt';

        $result = $aiGeneration->generate($promptTemplateKey, [
            'tone' => $project->tone ?: 'neutral',
            'content_goal' => $project->content_goal ?: 'educational',
            'language' => $project->primary_language ?: 'en',
            'source_content' => $project->source_content_raw ?: '',
        ]);

        $project->forceFill([
            'script_text' => $result['content'],
        ])->save();

        BreakdownScenesJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);
    }
}
