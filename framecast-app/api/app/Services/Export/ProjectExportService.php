<?php

namespace App\Services\Export;

use App\Events\ExportProgressed;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\Asset;
use App\Services\CreditService;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceUsageService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared initial-video and Cruise export service. The manual batch endpoint
 * retains its multi-ratio response and uses the same scene readiness rules.
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
        return DB::transaction(function () use ($project, $opts) {
            Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
            return $this->queueLocked(Project::whereKey($project->id)->lockForUpdate()->firstOrFail(), $opts);
        });
    }

    private function queueLocked(Project $project, array $opts): ExportJob
    {
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->lockForUpdate()
            ->get();

        $this->assertExportable($project, $scenes);

        $aspectRatio = (string) ($opts['aspect_ratio'] ?? $project->aspect_ratio ?? '9:16');
        $language    = (string) ($opts['language'] ?? $project->primary_language ?? 'en');
        $titleSlug   = Str::slug((string) ($project->title ?: 'framecast-project'));

        if (in_array(data_get($project->visual_brief, 'ugc_format'), ['one_shot', 'restyle'], true)) {
            if ($aspectRatio !== ($project->aspect_ratio ?: '9:16') || $language !== ($project->primary_language ?: 'en')) {
                throw new RuntimeException('This whole-video take can only be delivered in its original aspect ratio and language. Revise it in the creation flow.');
            }
            // Keep baked-in dialogue; scene composition would strip native audio.
            if ($this->shouldWatermark($project->workspace_id, false)) {
                throw new RuntimeException('Your current plan requires a watermark. Upgrade to download this whole-video take.');
            }
            return ExportJob::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                'aspect_ratio' => $project->aspect_ratio ?: '9:16', 'language' => $language,
                'file_name' => "{$titleSlug}-{$aspectRatio}-{$language}.mp4",
                'watermark_enabled' => false, 'status' => 'completed', 'progress_percent' => 100,
                'output_asset_id' => $scenes->first()->visual_asset_id, 'priority' => 0,
                'queued_at' => now(), 'started_at' => now(), 'completed_at' => now(),
            ]);
        }

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
            'priority'          => CreditService::exportPriorityFor($project->workspace?->plan_tier),
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

        ProcessExportJob::dispatch((int) $exportJob->getKey())->afterCommit();

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
        if ($owner) {
            // The creator may since have switched to a different client.
            $owner->workspace_id = $project->workspace_id;
            $owner->setRelation('workspace', $project->workspace);
        }
        if ($owner && $this->usage->hasReachedExportLimit($owner)) {
            $ctx = $this->usage->exportLimitContext($owner);
            throw new RuntimeException("You've used {$ctx['used']} of {$ctx['limit']} exports on the {$ctx['plan']} plan this month.", 402);
        }

        if ($owner) {
            $remaining = $this->usage->exportsRemaining($owner);
            $inFlight = ExportJob::query()->where('workspace_id', $project->workspace_id)
                ->whereIn('status', ['queued', 'processing'])->count();
            if ($remaining !== null && $inFlight >= $remaining) {
                throw new RuntimeException('Your remaining exports are already being prepared. Wait for them to finish or upgrade your plan.', 402);
            }
        }

        if (in_array(data_get($project->visual_brief, 'ugc_format'), ['one_shot', 'restyle'], true)) {
            if ($scenes->count() !== 1) throw new RuntimeException('A whole-video take must have one scene. Revise it in its creation flow.');
            if (! Asset::query()->where('workspace_id', $project->workspace_id)->where('asset_type', 'video')->whereKey($scenes->first()->visual_asset_id)->exists()) {
                throw new RuntimeException('The video has not finished generating yet.');
            }
            return;
        }

        $visualOptionalTypes = ['text_card', 'waveform'];
        foreach ($scenes as $scene) {
            $settings = $scene->image_generation_settings_json ?? [];
            foreach ([$scene->visual_asset_id, $scene->sound_asset_id, data_get($scene->voice_settings_json, 'audio_asset_id')] as $assetId) {
                if ($assetId && ! Asset::where('workspace_id', $project->workspace_id)->whereKey($assetId)->exists()) {
                    throw new RuntimeException("Scene {$scene->scene_order} references an unavailable asset.");
                }
            }
            if (trim((string) $scene->script_text) !== '' && data_get($scene->voice_settings_json, 'is_outdated')) {
                throw new RuntimeException("Scene {$scene->scene_order} has outdated narration. Re-record it before exporting.");
            }
            if (! empty($settings['animation_outdated'])) {
                throw new RuntimeException("Scene {$scene->scene_order} has outdated lip sync. Update its talking video before exporting.");
            }
            if (! empty($settings['in_progress']) || ! empty($settings['animation_in_progress']) || data_get($scene->voice_settings_json, 'in_progress')) {
                throw new RuntimeException("Scene {$scene->scene_order} is still generating. Wait before exporting.");
            }
            if (! empty($settings['last_error']) || ! empty($settings['animation_last_error']) || data_get($scene->voice_settings_json, 'last_error')) {
                throw new RuntimeException('A scene needs attention. Open the editor to repair it before finishing the video.');
            }
            if (trim((string) $scene->script_text) === '' && ! $scene->visual_asset_id && ! data_get($scene->caption_settings_json, 'ugc_headline.text')) {
                throw new RuntimeException('Every scene needs script content before export.');
            }
            if (! $scene->visual_asset_id && ! in_array((string) $scene->visual_type, $visualOptionalTypes, true)) {
                throw new RuntimeException("Scene {$scene->scene_order} is missing its visual — generate it before exporting.");
            }
            if (trim((string) $scene->script_text) !== '' && ! data_get($scene->voice_settings_json, 'audio_asset_id')) {
                throw new RuntimeException("Scene {$scene->scene_order} is missing its voiceover — generate it before exporting.");
            }
        }
    }

    /** One initial file per project, even when several jobs/tabs finish together. */
    public function finishInitial(Project $project): ?ExportJob
    {
        if (! $project->usesAutomaticFinish()) return null;
        return DB::transaction(function () use ($project) {
            // Serialize automatic quota reservations across this workspace.
            Workspace::query()->whereKey($project->workspace_id)->lockForUpdate()->first();
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = ExportJob::query()->where('project_id', $project->id)->latest('id')->first();
            if ($existing) return $existing;
            if (! $this->generationFinished($project)) return null;
            // A revision requires an explicit Update video action.
            if (data_get($project->visual_brief, 'ugc_revision_at')) return null;
            try {
                return $this->queue($project);
            } catch (RuntimeException $e) {
                // Persist a visible, retryable failure instead of an endless spinner.
                return ExportJob::query()->create([
                    'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                    'aspect_ratio' => $project->aspect_ratio ?: '9:16',
                    'language' => $project->primary_language ?: 'en', 'file_name' => 'video.mp4',
                    'watermark_enabled' => $this->shouldWatermark($project->workspace_id, false),
                    'status' => 'failed', 'progress_percent' => 0, 'priority' => 0,
                    'failure_reason' => $e->getMessage(), 'queued_at' => now(),
                ]);
            }
        });
    }

    public function generationFinished(Project $project): bool
    {
        if ($project->status !== 'ready_for_review') return false;
        if (\Illuminate\Support\Facades\Cache::has(\App\Jobs\GenerateAIMusicJob::inFlightKey($project->id))) return false;
        $scenes = $project->scenes()->get();
        if ($scenes->isEmpty()) return false;
        $wholeVideo = in_array(data_get($project->visual_brief, 'ugc_format'), ['one_shot', 'restyle'], true);
        foreach ($scenes as $scene) {
            $settings = $scene->image_generation_settings_json ?? [];
            if (! empty($settings['in_progress']) || ! empty($settings['animation_in_progress'])) return false;
            if (! $wholeVideo && (! empty($settings['auto_animate']) || ! empty($settings['planned_spokesperson']) || in_array($settings['ugc_kind'] ?? '', ['on_camera', 'reaction'], true))
                && empty($settings['animation_video_asset_id']) && empty($settings['animation_last_error']) && empty($settings['last_error'])) return false;
            if (! empty($settings['include_music']) && ! $project->music_asset_id) {
                $music = data_get(\App\Events\GenerationProgressed::getProgress($project->id), 'stages.ai_music.status');
                if (! in_array($music, ['completed', 'failed'], true)) return false;
            }
        }
        return true;
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
