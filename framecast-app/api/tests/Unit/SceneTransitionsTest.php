<?php

namespace Tests\Unit;

use App\Services\Export\SceneTransitions as T;
use Tests\TestCase;

/** Transitions between scenes (owner, 2026-11-17): twenty xfade types, none by default, the length unchanged. */
class SceneTransitionsTest extends TestCase
{
    public function test_the_catalogue_is_twenty_and_none_is_a_plain_cut(): void
    {
        $this->assertCount(20, T::CATALOGUE);
        $this->assertNull(T::parse(null));
        $this->assertNull(T::parse(''));
        $this->assertNull(T::parse('crossfade'), 'old free text is a cut, never a surprise effect');
        $this->assertNull(T::format('none'));
    }

    public function test_a_rule_round_trips_and_is_held_to_its_range(): void
    {
        $this->assertSame('fade:0.5', T::format('fade', 0.5));
        $this->assertSame(['type' => 'wipeleft', 'seconds' => 0.8], T::parse('wipeleft:0.8'));
        $this->assertSame(1.5, T::parse('fade:9')['seconds']);
        $this->assertSame(T::DEFAULT_SECONDS, T::parse('fade')['seconds']);
        $this->assertTrue(T::valid('radial:1'));
        $this->assertTrue(T::valid(''));
        $this->assertFalse(T::valid('fade:0.5;rm'));
        $this->assertFalse(T::valid('spin:1'));
    }

    public function test_plain_cuts_keep_the_old_join(): void
    {
        $this->assertNull(T::videoGraph([2.0, 3.0], [null, null]));
        $this->assertNull(T::videoGraph([2.0], [T::parse('fade:1')]), 'one scene has no cut');
    }

    public function test_the_transition_is_centred_on_the_cut_over_held_frames(): void
    {
        $g = T::videoGraph([2.0, 3.0, 2.5], [T::parse('fade:1'), null, null]);
        // Scene 1 holds its last frame 0.5 s, scene 2 its first 0.5 s; the fade starts 0.5 s before the cut at 2.0 s.
        $this->assertStringContainsString('[0:v]fps=30,format=yuv420p,setsar=1,settb=AVTB,tpad=stop_mode=clone:stop_duration=0.500[p0]', $g);
        $this->assertStringContainsString('tpad=start_mode=clone:start_duration=0.500[p1]', $g);
        $this->assertStringContainsString('xfade=transition=fade:duration=1.000:offset=1.500[j0]', $g);
        $this->assertStringContainsString('[j0][p2]concat=n=2:v=1:a=0[outv]', $g);
    }

    public function test_a_short_scene_keeps_most_of_its_own_moment(): void
    {
        $g = T::videoGraph([0.5, 3.0], [T::parse('fade:1.5')]);
        $this->assertStringContainsString('duration=0.400', $g, 'at most 80% of the shorter scene');
    }
}
