<?php

namespace Tests\Unit;

use App\Services\Create\CapabilityCatalogue;
use App\Services\Create\PlanService;
use Tests\TestCase;

/** A talking clip is made from an approved character image, so a plan with one always buys that image (2026-11-20, ws 79). */
class PlanCharacterLookTest extends TestCase
{
    public function test_a_talking_take_without_a_character_image_gets_one_first(): void
    {
        $media = PlanService::withCharacterLook([
            ['requirement_ids' => ['req-a'], 'kind' => 'talking_take', 'description' => 'Brett waves and says hello.', 'subject' => 'source', 'credits' => 330],
            ['requirement_ids' => [], 'kind' => 'cloned_voiceover', 'description' => 'Narration', 'credits' => 2],
        ]);
        $this->assertSame(['character_poses', 'talking_take', 'cloned_voiceover'], array_column($media, 'kind'));
        $this->assertSame(CapabilityCatalogue::CHARACTER_MASTER_CREDITS, $media[0]['credits']);
        $this->assertSame(['req-a'], $media[0]['requirement_ids']);
        $this->assertStringContainsString('Brett waves', $media[0]['description']);
    }

    public function test_plans_that_already_have_a_look_or_need_none_are_unchanged(): void
    {
        $withPoses = [['kind' => 'character_poses', 'description' => 'x', 'credits' => 35], ['kind' => 'talking_take', 'description' => 'y', 'credits' => 330]];
        $this->assertSame($withPoses, PlanService::withCharacterLook($withPoses));
        $withSheet = [['kind' => 'reference_sheet', 'description' => 'x', 'credits' => 40], ['kind' => 'talking_shot', 'description' => 'y', 'credits' => 90]];
        $this->assertSame($withSheet, PlanService::withCharacterLook($withSheet));
        $plain = [['kind' => 'stock_video', 'description' => 'x', 'credits' => 0], ['kind' => 'animate_image', 'subject' => 'source', 'description' => 'y', 'credits' => 20]];
        $this->assertSame($plain, PlanService::withCharacterLook($plain));
    }
}
