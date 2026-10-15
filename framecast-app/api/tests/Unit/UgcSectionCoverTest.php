<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Ugc\UgcSectionController;
use App\Services\CreditService;
use Tests\TestCase;

class UgcSectionCoverTest extends TestCase
{
    public function test_a_section_covers_what_carries_straight_on_from_it_at_the_take_s_rates(): void
    {
        $recipe = ['engine' => 'omni', 'chunks' => [['seconds' => 6], ['seconds' => 6, 'voice' => ['id' => 'Puck']], ['seconds' => 4], ['seconds' => 5]]];
        $sections = [['i' => 0, 'carried' => false], ['i' => 1, 'carried' => false], ['i' => 2, 'carried' => true], ['i' => 3, 'carried' => false]];
        $cover = UgcSectionController::cover($recipe, $sections, 1);
        $this->assertSame([1, 2], $cover['sections'], 'section 3 carried straight on from 2');
        $this->assertSame(10, $cover['seconds']);
        $this->assertSame(10 * CreditService::VIDEO_ONESHOT_PER_SECOND['omni'] + 6 * CreditService::VIDEO_LIPSYNC_PER_SECOND, $cover['credits'], 'lip-sync only on the spoken clip');
        $this->assertSame([3], UgcSectionController::cover($recipe, $sections, 3)['sections']);
        $recipe['chunks'][0]['music'] = 'a bed';
        $this->assertSame(5 * CreditService::VIDEO_ONESHOT_PER_SECOND['omni'] + CreditService::AI_MUSIC, UgcSectionController::cover($recipe, $sections, 3)['credits'], 'the music bed is made again to the new length');
    }
}
