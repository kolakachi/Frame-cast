<?php
namespace Tests\Unit;

use App\Services\Create\CreativeIntent;
use Tests\TestCase;

class CreateCreativeIntentTest extends TestCase
{
    private function educational(): array
    {
        return ['format' => 'educational', 'motion' => 'kinetic', 'timing_driver' => 'narration', 'reason' => 'Readable text and UI timed to the voice.', 'source_quote' => 'Teach people how to make UGC videos'];
    }

    public function test_intent_is_grounded_and_unknown_briefs_do_not_default_to_animation(): void
    {
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Teach people how to make UGC videos']]];
        $result = CreativeIntent::normalize($this->educational(), $ctx);
        $this->assertSame('educational', $result['format']);
        $this->assertSame('planner_interpretation', $result['provenance']);
        $this->assertNull(CreativeIntent::normalize([], $ctx));
        $this->assertNull(CreativeIntent::normalize([...$this->educational(), 'source_quote' => 'not requested'], $ctx));
        $this->assertNull(CreativeIntent::normalize([...$this->educational(), 'format' => 'invented'], $ctx));
    }

    public function test_short_edits_preserve_intent_but_timing_scope_requires_the_latest_message(): void
    {
        $previous = CreativeIntent::normalize($this->educational(), ['messages' => [['role' => 'user', 'content' => 'Teach people how to make UGC videos']]]);
        $ctx = ['previous_plan' => ['creative_intent' => $previous], 'messages' => [['role' => 'user', 'content' => 'Teach people how to make UGC videos'], ['role' => 'user', 'content' => 'Visuals run ahead of the voice']]];
        $next = CreativeIntent::normalize(['edit_scope' => 'timing_only', 'edit_source_quote' => 'Visuals run ahead of the voice'], $ctx);
        $this->assertSame('educational', $next['format']);
        $this->assertSame('timing_only', $next['edit_scope']);
        $this->assertSame('content', CreativeIntent::normalize([], [...$ctx, 'previous_plan' => ['creative_intent' => $next]])['edit_scope']);
        $this->assertSame('content', CreativeIntent::normalize(['edit_scope' => 'timing_only', 'edit_source_quote' => 'Teach people how to make UGC videos'], $ctx)['edit_scope']);
    }
}
