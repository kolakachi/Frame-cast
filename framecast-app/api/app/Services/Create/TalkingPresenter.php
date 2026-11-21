<?php
namespace App\Services\Create;

use App\Services\CreditService;

/** A quote freezes the speech route, engine, duration and price before any provider call. */
class TalkingPresenter
{
    /** Whether the plan asks this talking task for body moves (gestures, leans, steps), not only face and speech. */
    public static function moves(array $plan, string $kind): bool
    {
        $omitted = (array) ($plan['selections']['omitted_performance'] ?? []);
        return collect($plan['character_performance'] ?? [])->contains(fn ($r) => ($r['kind'] ?? '') === 'body'
            && ($r['tool'] ?? null) === $kind && ! in_array($r['id'] ?? '', $omitted, true));
    }

    /**
     * A cloned voice with body moves (a wave, a lean) goes to Seedance 2.5, which lip-syncs the referenced character
     * to the voice and acts the moves; the photo lip-sync (VEED Fabric) only moves the face (owner, 2026-11-21, ws 79).
     */
    public static function route(string $kind, ?string $voice, int $duration = 15, bool $moves = false): array
    {
        $seconds = $kind === 'talking_shot' ? 4 : max(4, min(15, $duration));
        if ($voice === 'clone' && $moves) {
            return ['speech_mode' => 'cloned_motion', 'engine' => 'seedance25', 'seconds' => $seconds,
                'credits' => $seconds * CreditService::VIDEO_ONESHOT_PER_SECOND['seedance25']];
        }
        if ($voice === 'clone') {
            return ['speech_mode' => 'cloned_lipsync', 'engine' => 'lipsync', 'seconds' => $seconds,
                'credits' => CreditService::spokespersonCost((float) $seconds)];
        }
        $engine = (string) config('create.native_talking_engine', 'omni');
        abort_unless(in_array($engine, ['omni', 'veo', 'seedance25'], true), 422, 'The native presenter engine is not configured.');
        // Veo is limited to eight seconds. Longer native performances use Omni;
        // choose the route before quoting, never as a paid retry after a rejection.
        if ($engine === 'veo') {
            if ($seconds > 8) $engine = 'omni';
            else $seconds = $seconds <= 4 ? 4 : ($seconds <= 6 ? 6 : 8);
        }
        return ['speech_mode' => 'native', 'engine' => $engine, 'seconds' => $seconds,
            'credits' => $seconds * CreditService::VIDEO_ONESHOT_PER_SECOND[$engine]];
    }
}
