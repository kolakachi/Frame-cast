<?php

namespace Tests\Unit;

use App\Services\AnimatedShotPlan;
use App\Services\Generation\Video\ReplicateI2VAdapter;
use Tests\TestCase;

/**
 * Scene length is planned from AnimatedShotPlan, but the clip is produced by
 * the adapter, which snaps the request to a per-tier value the model actually
 * supports. If those two drift, scenes stop matching their footage and the
 * renderer goes back to holding a frozen frame for the difference — the defect
 * a customer reported as the animation "starting over during a scene".
 */
class AnimatedShotPlanMatchesAdapterTest extends TestCase
{
    /** Every tier the wizard and estimate endpoint accept. */
    private const TIERS = ['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'veo_fast', 'seedance_25'];

    public function test_planned_shot_length_is_what_the_model_actually_renders(): void
    {
        $adapter = app(ReplicateI2VAdapter::class);
        $build = new \ReflectionMethod($adapter, 'buildRequestForTier');
        $build->setAccessible(true);

        foreach (self::TIERS as $tier) {
            foreach (['short', 'long'] as $pacing) {
                [, , $input] = $build->invoke(
                    $adapter,
                    $tier,
                    'https://example.test/still.png',
                    'a calm establishing shot',
                    AnimatedShotPlan::requestSeconds($pacing),
                    [],
                );

                $this->assertSame(
                    AnimatedShotPlan::clipSeconds($tier, $pacing),
                    (int) $input['duration'],
                    "{$tier}/{$pacing}: the scene is planned around a clip length the model will not render.",
                );
            }
        }
    }
}
