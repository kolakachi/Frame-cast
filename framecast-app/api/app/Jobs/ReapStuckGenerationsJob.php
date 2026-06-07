<?php

namespace App\Jobs;

use App\Models\Scene;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Server-side watchdog for stuck async-generation flags.
 *
 * Whenever a scene's image_generation_settings_json reports a generation
 * (image OR animation) as `in_progress` for longer than the worst-case
 * realistic run time, something has gone wrong:
 *   • the worker crashed before clearing the flag, or
 *   • the worker finished but the save() never persisted (Eloquent quirk
 *     we've seen on the prod stack), or
 *   • the queue lost the job mid-flight, or
 *   • a future async-job-with-flag pattern we haven't built yet has the
 *     same shape of bug.
 *
 * This job catches *all* of those by sweeping every 5 min and clearing
 * flags that are older than the threshold. Converts "stuck forever" into
 * "stuck for at most 15 min, then auto-recovers with a clear error."
 *
 * Thresholds:
 *   • Image gen: 10 min — gpt-image-2 character path is 30–90s typical,
 *     5-min ceiling worst case; 10 min is a generous safety margin.
 *   • Animation: 15 min — Kling 2.1 premium can take 4–5 min; 15 min
 *     covers a slow start + slow upload + room.
 */
class ReapStuckGenerationsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const IMAGE_GEN_MAX_MINUTES = 10;
    private const ANIMATION_MAX_MINUTES = 15;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'reap-stuck-generations';
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now();
        $imageCutoff = $now->subMinutes(self::IMAGE_GEN_MAX_MINUTES);
        $animCutoff  = $now->subMinutes(self::ANIMATION_MAX_MINUTES);

        $imageReaped = $this->reapImageGenerations($imageCutoff);
        $animReaped  = $this->reapAnimations($animCutoff);

        if ($imageReaped + $animReaped > 0) {
            Log::info('ReapStuckGenerationsJob: cleared stuck flags', [
                'image_generations_cleared' => $imageReaped,
                'animations_cleared'        => $animReaped,
            ]);
        }
    }

    /**
     * Clear `in_progress` on image-generation scenes whose
     * `generation_started_at` is older than the cutoff.
     */
    private function reapImageGenerations(CarbonImmutable $cutoff): int
    {
        // Postgres JSONB lookup: read the started_at out of the JSON column,
        // cast to timestamp, compare. Avoids pulling every scene into PHP.
        $stuck = Scene::query()
            ->whereRaw("(image_generation_settings_json->>'in_progress')::text = 'true'")
            ->whereRaw("(image_generation_settings_json->>'generation_started_at')::timestamp < ?", [$cutoff->toIso8601String()])
            ->get(['id', 'image_generation_settings_json']);

        $cleared = 0;
        foreach ($stuck as $scene) {
            $cfg = $scene->image_generation_settings_json ?? [];
            $cfg['in_progress']  = false;
            $cfg['needs_visual'] = true;
            $cfg['last_error']   = ($cfg['last_error'] ?? '') !== ''
                ? $cfg['last_error']
                : 'Generation timed out — please try again.';
            $cfg['reaped_at'] = now()->toIso8601String();

            $scene->forceFill(['image_generation_settings_json' => $cfg])->save();

            // Defensive raw UPDATE — mirrors GenerateAIImageJob's belt-and-suspenders
            // pattern. Catches the same Eloquent silent-save bug we saw on prod.
            DB::table('scenes')->where('id', $scene->id)->update([
                'image_generation_settings_json' => json_encode($cfg),
                'updated_at' => now(),
            ]);

            Log::warning('ReapStuckGenerationsJob: cleared stuck image generation', [
                'scene_id'   => $scene->id,
                'started_at' => $scene->image_generation_settings_json['generation_started_at'] ?? null,
            ]);
            $cleared++;
        }

        return $cleared;
    }

    /**
     * Clear `animation_in_progress` on scenes whose `animation_started_at`
     * is older than the cutoff.
     */
    private function reapAnimations(CarbonImmutable $cutoff): int
    {
        $stuck = Scene::query()
            ->whereRaw("(image_generation_settings_json->>'animation_in_progress')::text = 'true'")
            ->whereRaw("(image_generation_settings_json->>'animation_started_at')::timestamp < ?", [$cutoff->toIso8601String()])
            ->get(['id', 'project_id', 'image_generation_settings_json']);

        $cleared = 0;
        foreach ($stuck as $scene) {
            $cfg = $scene->image_generation_settings_json ?? [];

            // RESUME instead of discard: if we kept the Replicate prediction
            // id, the clip is still finishing (or done) in their cloud — the
            // cutoff is past our job timeout, so the original worker is dead.
            // Re-dispatch in resume mode to re-attach + finalize. No re-charge.
            $predictionId = $cfg['animation_prediction_id'] ?? null;
            if ($predictionId) {
                AnimateSceneJob::dispatch(
                    (int) $scene->id,
                    (int) $scene->project_id,
                    (string) ($cfg['animation_tier'] ?? 'quick'),
                    (int) ($cfg['animation_duration'] ?? 6),
                    null,
                    (string) $predictionId,
                );
                // Bump started_at so we don't re-dispatch a duplicate before
                // this resume attempt has had a full window to run.
                $cfg['animation_started_at'] = now()->toIso8601String();
                $cfg['animation_resumed_at'] = now()->toIso8601String();
                $scene->forceFill(['image_generation_settings_json' => $cfg])->save();
                DB::table('scenes')->where('id', $scene->id)->update([
                    'image_generation_settings_json' => json_encode($cfg),
                    'updated_at' => now(),
                ]);
                Log::info('ReapStuckGenerationsJob: resuming animation from prediction', [
                    'scene_id'      => $scene->id,
                    'prediction_id' => $predictionId,
                ]);
                $cleared++;
                continue;
            }

            $cfg['animation_in_progress'] = false;
            $cfg['animation_last_error']  = ($cfg['animation_last_error'] ?? '') !== ''
                ? $cfg['animation_last_error']
                : 'Animation timed out — please try again.';
            $cfg['animation_reaped_at']   = now()->toIso8601String();

            $scene->forceFill(['image_generation_settings_json' => $cfg])->save();

            DB::table('scenes')->where('id', $scene->id)->update([
                'image_generation_settings_json' => json_encode($cfg),
                'updated_at' => now(),
            ]);

            Log::warning('ReapStuckGenerationsJob: cleared stuck animation', [
                'scene_id'             => $scene->id,
                'animation_started_at' => $scene->image_generation_settings_json['animation_started_at'] ?? null,
            ]);
            $cleared++;
        }

        return $cleared;
    }
}
