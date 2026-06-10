<?php

namespace App\Services\Generation;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Safety net for the project status lifecycle. In the normal one-shot flow
 * GenerateTTSJob flips the project to ready_for_review — but RESUMED runs
 * (re-dispatched images/animations after a failure) never re-run TTS, so the
 * project stayed 'generating' forever: the progress page froze short of 100%
 * and never opened the editor.
 *
 * maybeMarkReady() is called by the generation jobs when they finish; it
 * flips a 'generating' project to ready_for_review once NOTHING is left in
 * flight (no image/animation in progress, every scripted scene has a voice).
 * Best-effort and idempotent — wrap calls in rescue().
 */
class PipelineStatusService
{
    public function maybeMarkReady(int $projectId): void
    {
        $project = Project::query()->find($projectId);
        if (! $project || $project->status !== 'generating') {
            return;
        }

        // Any image or animation still cooking? Not ready.
        $inFlight = DB::table('scenes')
            ->where('project_id', $projectId)
            ->where(function ($q): void {
                $q->whereRaw("image_generation_settings_json::jsonb->>'in_progress' = 'true'")
                    ->orWhereRaw("image_generation_settings_json::jsonb->>'animation_in_progress' = 'true'");
            })
            ->exists();
        if ($inFlight) {
            return;
        }

        // Voice still missing on a scripted scene? TTS is running (or will
        // flip the status itself when it lands) — don't preempt it.
        $missingVoice = DB::table('scenes')
            ->where('project_id', $projectId)
            ->whereNotNull('script_text')
            ->where('script_text', '!=', '')
            ->where(function ($q): void {
                $q->whereNull('voice_settings_json')
                    ->orWhereRaw("coalesce(voice_settings_json::jsonb->>'audio_asset_id', '0') = '0'");
            })
            ->exists();
        if ($missingVoice) {
            return;
        }

        $project->forceFill(['status' => 'ready_for_review'])->save();
    }
}
