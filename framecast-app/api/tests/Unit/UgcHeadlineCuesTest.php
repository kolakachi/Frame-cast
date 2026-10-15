<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcHeadline;
use Tests\TestCase;

class UgcHeadlineCuesTest extends TestCase
{
    public function test_a_headline_holds_until_the_next_and_stretches_to_the_real_length(): void
    {
        $cues = UgcHeadline::cues([
            ['headline' => 'POV: your desk finally makes sense', 'seconds' => 3],
            ['headline' => '', 'seconds' => 2],
            ['headline' => 'Ten seconds. Done.', 'seconds' => 3],
        ]);
        $this->assertSame([['text' => 'POV: your desk finally makes sense', 'start' => 0.0, 'end' => 5.0], ['text' => 'Ten seconds. Done.', 'start' => 5.0, 'end' => 8.0]], $cues);
        $ass = UgcHeadline::assTimed($cues, 720, 1280, 10.0);
        $this->assertSame(1, substr_count($ass, '[Script Info]'));
        $this->assertStringContainsString('Dialogue: 1,0:00:00.00,0:00:06.25,Headline', $ass);
        $this->assertStringContainsString('Dialogue: 1,0:00:06.25,0:00:10.00,Headline', $ass);
    }
}
