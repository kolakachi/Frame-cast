<?php

namespace Tests\Unit;

use App\Services\Create\StepNudge;
use PHPUnit\Framework\TestCase;

class StepNudgeTest extends TestCase
{
    public function test_only_a_short_go_on_counts(): void
    {
        foreach (['continue fazendo o video', 'Continue', 'ok', 'sim', 'pode seguir', 'Aprovado!', 'go ahead', 'keep going', 'segue', 'make the video'] as $yes) {
            $this->assertTrue(StepNudge::isGoOn($yes), $yes);
        }
        foreach (['make her older', 'continue but change the background to green', 'Ads & Promo: Create a 30-second vertical video for a lottery shop', ''] as $no) {
            $this->assertFalse(StepNudge::isGoOn($no), $no);
        }
    }

    public function test_the_reply_points_at_the_step_s_approve_in_the_user_s_language(): void
    {
        $this->assertStringContainsString('O personagem está esperando', StepNudge::reply('character', 'continue fazendo o video'));
        $this->assertStringContainsString('Approve · make video', StepNudge::reply('storyboard', 'go on'));
        $this->assertStringStartsWith('The character is waiting', StepNudge::reply('character', 'continue'));
    }
}
