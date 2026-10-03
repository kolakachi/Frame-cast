<?php
namespace App\Services\Create;

use App\Services\CreditService;

/** A quote freezes the speech route, engine, duration and price before any provider call. */
class TalkingPresenter
{
    public static function route(string $kind, ?string $voice, int $duration = 15): array
    {
        $seconds = $kind === 'talking_shot' ? 4 : max(4, min(15, $duration));
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
