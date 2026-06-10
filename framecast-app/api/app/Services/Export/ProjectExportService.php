<?php

namespace App\Services\Export;

use App\Events\ExportProgressed;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceUsageService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Queue a final-video export for a project. Extracted so both the manual
 * export endpoint (ProjectController::export) and the Cruise ExportTool run
 * the SAME readiness checks, limit enforcement, watermark policy and dispatch
 * — the assistant must not be able to bypass any of them.
 *
 * Throws RuntimeException with a user-facing message when the project isn't
 * exportable; the caller turns that into its own error shape.
 */
class ProjectExportService
{
    public function __construct(private WorkspaceUsageService $usage) {}

    /**
     * @param  array{aspect_ratio?:string, language?:string, watermark_enabled?:bool}  $opts
     */
    public function queue(Project $project, array $opts = []): ExportJob
    {
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        $this->assertExportable($project, $scenes);

        $aspectRatio = (string) ($opts['aspect_ratio'] ?? $project->aspect_ratio ?? '9:16');
        $language    = (string) ($opts['language'] ?? $project->primary_language ?? 'en');
        $titleSlug   = Str::slug((string) ($project->title ?: 'framecast-project'));

        $exportJob = ExportJob::query()->create([
            'workspace_id'      => $project->workspace_id,
            'project_id'        => $project->getKey(),
            'variant_id'        => null,
            'aspect_ratio'      => $aspectRatio,
            'language'          => $language,
            'file_name'         => "{$titleSlug}-{$aspectRatio}-{$language}.mp4",
            'watermark_enabled' => $this->shouldWatermark($project->workspace_id, (bool) ($opts['watermark_enabled'] ?? false)),
            'status'            => 'queued',
            'progress_percent'  => 0,
            'priority'          => 0,
            'queued_at'         => now(),
        ]);

        rescue(static function () use ($project, $exportJob): void {
            ExportProgressed::dispatch(
                (int) $project->getKey(),
                (int) $exportJob->getKey(),
                'queued',
                0,
                'Export queued.',
                (string) $exportJob->file_name,
                $exportJob->failure_reason,
            );
        }, false);

        ProcessExportJob::dispatch((int) $exportJob->getKey());

        return $exportJob;
    }

    /**
     * Throw if the project can't be exported yet (no scenes, missing
     * script/visual/voice, or the workspace is over its monthly export
     * limit). Mirrors ProjectController::export's gates.
     */
    public function assertExportable(Project $project, $scenes = null): void
    {
        $scenes ??= Scene::query()->where('project_id', $project->getKey())->orderBy('scene_order')->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException('At least one scene is required before export.');
        }

        $owner = User::query()->find($project->created_by_user_id);
        if ($owner && $this->usage->hasReachedExportLimit($owner)) {
            $ctx = $this->usage->exportLimitContext($owner);
            throw new RuntimeException("You've used {$ctx['used']} of {$ctx['limit']} exports on the {$ctx['plan']} plan this month.");
        }

        $visualOptionalTypes = ['text_card', 'waveform'];
        foreach ($scenes as $scene) {
            if (trim((string) $scene->script_text) === '') {
                throw new RuntimeException('Every scene needs script content before export.');
            }
            if (! $scene->visual_asset_id && ! in_array((string) $scene->visual_type, $visualOptionalTypes, true)) {
                throw new RuntimeException("Scene {$scene->scene_order} is missing its visual — generate it before exporting.");
            }
            if (! data_get($scene->voice_settings_json, 'audio_asset_id')) {
                throw new RuntimeException("Scene {$scene->scene_order} is missing its voiceover — generate it before exporting.");
            }
        }
    }

    /** Latest completed export for a project, or null. */
    public function latestCompletedExport(Project $project): ?ExportJob
    {
        return ExportJob::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'completed')
            ->latest('id')
            ->first();
    }

    private function shouldWatermark(int $workspaceId, bool $requested): bool
    {
        $planTier = Workspace::find($workspaceId)?->plan_tier ?? 'free';

        return $planTier === 'free' ? true : $requested;
    }
}
