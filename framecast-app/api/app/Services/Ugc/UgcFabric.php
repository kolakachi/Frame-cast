<?php

namespace App\Services\Ugc;

use App\Models\User;
use App\Services\CreditService;

/**
 * The long talking route (owner, 2026-10-21: "build story time on both routes"): the presenter's dressed look, each
 * line read by the chosen voice, and VEED Fabric syncing the read onto the look at 720p, as one continuous talking take
 * of up to a minute (UGC's longest take). The face never drifts, since it is one picture; there is no camera move,
 * gesture or product handling, so it is offered for talking-to-camera types only, with a cast presenter.
 *
 * Fabric is slow (a 15 s clip took 5-10 minutes), so the take's lines are made as clips of at most CLIP_SECONDS whose
 * Fabric jobs all start at once, then are collected in order (GenerateOneShotUgcJob::startFabric).
 */
final class UgcFabric
{
    public const ENGINE = 'fabric';

    /** The lipsync engine it runs (config services.lipsync.engines). */
    public const LIPSYNC = 'fabric_720';

    public const CLIP_SECONDS = 20;

    /** The look's clothes when the brief names none: the take always speaks from a dressed look (shape and room). */
    public const WARDROBE = 'everyday casual clothes that suit the story';

    public const NEEDS = 'The talking-photo route needs a cast presenter, on Story time, Testimonial, Founder story or Listicle.';

    /** Talking-to-camera types a still, speaking picture suits. */
    public const TYPES = ['storytime', 'testimonial', 'founder_story', 'listicle'];

    /** Credits a second: Fabric's 720p cost a second at the spokesperson peg ($0.15 / $0.005 = 30). */
    public static function perSecond(): int
    {
        return (int) ceil(CreditService::lipsyncRateUsd(self::LIPSYNC) / CreditService::VIDEO_COGS_PER_CREDIT);
    }

    /** Customers once its sample takes passed (UGC_FABRIC_LIVE); staff always. */
    public static function usable(?User $user): bool
    {
        return (bool) config('services.ugc.fabric_live', false) || ($user && in_array($user->role, ['super_admin', 'platform_admin'], true));
    }

    /** For this video: a cast presenter on a talking-to-camera type. */
    public static function fits(string $type, bool $presenter): bool
    {
        return $presenter && in_array($type, self::TYPES, true);
    }
}
