<?php

namespace Tests\Unit;

use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\ScenePacing;
use Tests\TestCase;

/** The price shown before a Composer build counts scenes the way the build cuts them (fix A1, 2026-10-28). */
class ProjectEstimateTest extends TestCase
{
    public function test_a_short_prompt_is_priced_for_the_scenes_its_length_makes(): void
    {
        $credits = app(CreditService::class);
        $e = $credits->estimateProject('prompt', 'Facts about octopuses', 'ai_images', 'medium', 60);
        $target = ScenePacing::targetScenes(60, 'ai_images');
        $this->assertSame([(int) round($target * 0.8), (int) round($target * 1.2)], [$e['scenes_min'], $e['scenes_max']], 'a 60 s video is about 12 scenes, not 8 from a 3-word prompt');
        $this->assertSame(app(ImageAdapterFactory::class)->costFor(null), $e['breakdown']['visual_per_scene'], 'the image price the job charges');
        $perScene = $e['breakdown']['visual_per_scene'] + $e['breakdown']['voice_per_scene'];
        $this->assertSame($e['breakdown']['script_and_breakdown'] + $e['breakdown']['export'] + $e['scenes_min'] * $perScene, $e['credits_min']);
        $this->assertGreaterThan(400, $e['credits_max'], 'a minute of AI images is hundreds of credits, as charged');

        $long = $credits->estimateProject('prompt', 'Facts about octopuses', 'ai_images', 'medium', 180);
        $this->assertGreaterThan($e['scenes_max'], $long['scenes_min'], 'three minutes is cut into more scenes');
    }

    public function test_a_blank_project_costs_nothing_to_open(): void
    {
        $e = app(CreditService::class)->estimateProject('blank', null, 'ai_images', 'medium', 60);
        $this->assertSame([0, 0], [$e['credits_min'], $e['credits_max']]);
    }
}
