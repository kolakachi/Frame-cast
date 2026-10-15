<?php

namespace Tests\Unit;

use App\Services\Create\MotionMoves;
use App\Services\Create\Planning\PlanPrompt;
use PHPUnit\Framework\TestCase;

class MotionPurposeTest extends TestCase
{
    public function test_the_purpose_guide_names_only_real_moves_and_reaches_the_planner(): void
    {
        foreach (MotionMoves::BY_PURPOSE as $job => $moves) {
            foreach ($moves as $m) $this->assertArrayHasKey($m, MotionMoves::MOVES, "$job: $m");
        }
        $prompt = PlanPrompt::system();
        $this->assertStringContainsString('a number or a result: odometer, count, gauge', $prompt);
        $this->assertStringContainsString('the last beat never ends on bare text over a plain field', $prompt);
        $this->assertStringNotContainsString('{MOVES_BY_PURPOSE}', $prompt);
    }
}
