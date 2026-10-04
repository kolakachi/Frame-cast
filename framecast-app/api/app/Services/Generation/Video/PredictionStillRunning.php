<?php

namespace App\Services\Generation\Video;

use RuntimeException;

/**
 * Our wait ran out but the provider is still making the clip. Not a failure:
 * the caller keeps the prediction, charges stand, and the reaper re-attaches
 * later (a backed-up Seedance Pro took 71–96 minutes; giving up at six refunded
 * clips we were then billed for and never delivered).
 */
class PredictionStillRunning extends RuntimeException
{
    public function __construct(public readonly string $predictionId, public readonly string $model = '')
    {
        parent::__construct('The video model is still working on this clip.');
    }
}
