<?php

namespace Tests\Unit;

use App\Jobs\DrawStoryboardJob;
use App\Services\Generation\StoryboardBeats;
use PHPUnit\Framework\TestCase;

/** The storyboard is drawn from three planned moments, not the description three times (owner, 2026-10-31). */
class StoryboardBeatsTest extends TestCase
{
    private const ANSWER = 'Plan: {"frames":["She sits at a desk at sitting height, slumped","She presses the button, the desk half way up","She stands at the desk at standing height, smiling"],'
        .'"keep":"a woman in a navy blouse, a bright home office","motion":["The desk rises and she stands up.","The desk finishes rising.","She settles and types."]}';

    public function test_the_answer_is_read_as_three_frames_and_their_motion(): void
    {
        $beats = StoryboardBeats::parse(self::ANSWER);
        $this->assertCount(3, $beats['frames']);
        $this->assertSame('a woman in a navy blouse, a bright home office', $beats['keep']);
        $this->assertSame('The desk finishes rising.', $beats['motion'][1]);
        $this->assertNull(StoryboardBeats::parse('{"frames":["one","two"]}'), 'two frames is no storyboard');
        $this->assertNull(StoryboardBeats::parse('no json'));
        $this->assertSame([], StoryboardBeats::parse('{"frames":["a","b","c"],"motion":["only one"]}')['motion'], 'motion is all three or none');
    }

    public function test_the_first_frame_follows_its_moment_over_the_reference(): void
    {
        $beats = StoryboardBeats::parse(self::ANSWER);
        $first = DrawStoryboardJob::beatPrompt($beats, 0, true, true);
        $this->assertStringContainsString('slumped', $first);
        $this->assertStringContainsString('even where the reference shows a different moment', $first, 'the scene image showed her already standing');
        $this->assertStringContainsString('In every frame: a woman in a navy blouse', $first);

        $this->assertStringContainsString('the previous frame of the same shot', DrawStoryboardJob::beatPrompt($beats, 2, true, true));
        $this->assertStringNotContainsString('reference', DrawStoryboardJob::beatPrompt($beats, 0, false, false), 'no picture, no talk of one');
    }
}
