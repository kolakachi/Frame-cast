<?php

namespace Tests\Unit;

use App\Services\AnimatedShotPlan;
use App\Services\CreditService;
use App\Services\ScenePacing;
use App\Services\Generation\Video\ReplicateI2VAdapter;
use Tests\TestCase;

class AnimatedShotPlanTest extends TestCase
{
    public function test_both_paces_match_model_requests_and_credit_estimates_for_every_tier(): void
    {
        $method = new \ReflectionMethod(ReplicateI2VAdapter::class, 'buildRequestForTier');
        foreach (['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'seedance_25', 'veo_fast'] as $tier) {
            foreach (['short', 'long'] as $pacing) {
                $seconds = AnimatedShotPlan::requestSeconds($pacing);
                $clip = AnimatedShotPlan::clipSeconds($tier, $pacing);
                [, , $input] = $method->invoke(new ReplicateI2VAdapter(), $tier, 'https://example.test/image.png', 'Move', $seconds, []);
                $this->assertSame($clip, $input['duration'], "$tier/$pacing clip length");
                $quote = (new CreditService())->estimateProject('prompt', 'A demo', 'ai_video', durationSeconds: 60, animateTier: $tier, animationPacing: $pacing);
                $target = ScenePacing::targetScenes(60, 'ai_video', true, $clip);
                $this->assertSame(max(2, (int) round($target * 0.8)), $quote['scenes_min']);
                $this->assertSame(min(30, (int) round($target * 1.2)), $quote['scenes_max']);
                $imageCost = app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null);
                $this->assertSame($imageCost + CreditService::animationCost($tier, CreditService::videoQuality($tier, null), $seconds), $quote['breakdown']['visual_per_scene']);
                $this->assertSame($clip, $quote['animation_clip_seconds']);
                $this->assertStringContainsString("about $clip seconds", ScenePacing::guidance(60, 'ai_video', true, $clip));
            }
        }
    }

    public function test_automatic_generation_dispatches_the_saved_pacing_without_charging_here(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $method = new \ReflectionMethod(\App\Jobs\GenerateProjectAIImagesJob::class, 'chainAnimationIfConfigured');
        foreach (['short' => 5, 'long' => 10] as $pacing => $expected) {
            $project = new \App\Models\Project(['visual_generation_mode' => 'ai_video',
                'visual_brief' => ['animate_tier' => 'veo_fast', 'animation_pacing' => $pacing]]);
            $project->id = 42;
            $scene = \Mockery::mock(\App\Models\Scene::class)->makePartial();
            $scene->id = $expected;
            $scene->visual_asset_id = 99;
            $scene->shouldReceive('fresh')->once()->andReturnSelf();
            $scene->shouldReceive('save')->once()->andReturn(true);
            $method->invoke(new \App\Jobs\GenerateProjectAIImagesJob(42), $project, $scene);
            \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\AnimateSceneJob::class,
                fn ($job) => $job->sceneId === $expected && $job->durationSeconds === $expected
                    && $job->sourceAssetId === 99 && $job->tier === 'veo_fast');
        }
    }

    public function test_unselected_or_legacy_pacing_keeps_short_animation_requests(): void
    {
        $this->assertSame(5, AnimatedShotPlan::requestSeconds(null));
        $this->assertSame(5, AnimatedShotPlan::requestSeconds('short'));
        $this->assertSame(10, AnimatedShotPlan::requestSeconds('long'));
    }
}
