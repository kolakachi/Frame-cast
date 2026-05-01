<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Project;
use App\Models\Scene;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SetWaveformVisualsJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(): void
    {
        GenerationProgressed::dispatch($this->projectId, 'visual_match', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $waveformSettings = is_array($project->waveform_settings_json) ? $project->waveform_settings_json : [];

        Scene::query()
            ->where('project_id', $this->projectId)
            ->whereNull('visual_asset_id')
            ->orderBy('scene_order')
            ->get()
            ->each(function (Scene $scene) use ($waveformSettings): void {
                $scene->forceFill([
                    'visual_type' => 'waveform',
                    'image_generation_settings_json' => $waveformSettings !== []
                        ? array_merge($scene->image_generation_settings_json ?? [], $waveformSettings)
                        : $scene->image_generation_settings_json,
                ])->save();
            });

        GenerationProgressed::dispatch($this->projectId, 'visual_match', 'completed');
        GenerateTTSJob::dispatch($this->projectId);
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);
        GenerationProgressed::dispatch($this->projectId, 'visual_match', 'failed', $exception->getMessage());
    }
}
