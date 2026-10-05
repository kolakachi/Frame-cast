<?php
namespace App\Services\Generation;

use Illuminate\Support\Facades\{Cache, DB};

/**
 * How long each animation model has been taking lately, from the clips finished in the last six hours: so the editor
 * can say "Seedance Pro is slow right now (about 70 min)" before someone picks it. A backed-up model once took 71-96
 * minutes a clip while users waited on a spinner.
 */
class AnimationSpeed
{
    /** Usual minutes a clip takes, per tier; "slow" is well past this. */
    public const USUAL = ['quick' => 2, 'balanced' => 3, 'premium' => 5, 'seedance_lite' => 2, 'seedance_pro' => 3, 'veo_fast' => 2, 'seedance_25' => 4];

    /** @return array<string, array{median_minutes: float, samples: int, slow: bool}> */
    public static function recent(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return [];
        return Cache::remember('animation-speed', 300, function () {
            $rows = DB::select("select image_generation_settings_json->>'animation_tier' as tier,
                    extract(epoch from ((image_generation_settings_json->>'animation_completed_at')::timestamptz
                        - coalesce(image_generation_settings_json->>'animation_prediction_started_at', image_generation_settings_json->>'animation_started_at')::timestamptz)) / 60 as minutes
                from scenes
                where image_generation_settings_json->>'animation_completed_at' is not null
                  and (image_generation_settings_json->>'animation_completed_at')::timestamptz > now() - interval '6 hours'");
            $by = [];
            foreach ($rows as $r) if ($r->tier && is_numeric($r->minutes) && $r->minutes > 0) $by[$r->tier][] = (float) $r->minutes;
            $out = [];
            foreach ($by as $tier => $m) {
                sort($m); $median = $m[intdiv(count($m), 2)];
                $out[$tier] = ['median_minutes' => round($median, 1), 'samples' => count($m), 'slow' => count($m) >= 2 && $median > 3 * (self::USUAL[$tier] ?? 3) && $median >= 10];
            }
            return $out;
        });
    }
}
