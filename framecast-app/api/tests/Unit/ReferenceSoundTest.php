<?php

namespace Tests\Unit;

use App\Services\Create\PlanService;
use App\Services\Create\References\ReferenceStudy;
use Tests\TestCase;

class ReferenceSoundTest extends TestCase
{
    /** 10 ms levels: a quiet floor with a burst from $at for $frames frames at $db. */
    private function levels(float $seconds, array $bursts = []): array
    {
        $b = array_fill(0, (int) ($seconds * 100), -60.0);
        foreach ($bursts as [$at, $frames, $db]) for ($i = (int) round($at * 100); $i < (int) round($at * 100) + $frames; $i++) $b[$i] = $db;
        return $b;
    }

    public function test_a_reference_with_nothing_besides_the_voice_has_no_effects(): void
    {
        $quiet = $this->levels(10);
        $r = ReferenceStudy::soundEvents($quiet, $quiet, $quiet, -15.0, null, null, []);
        $this->assertSame(0, $r['count']);
        $this->assertSame(45.0, $r['below_voice_db']);
    }

    public function test_a_short_burst_is_a_pop_at_its_time_and_one_on_a_cut_says_so(): void
    {
        $high = $this->levels(10, [[2.0, 8, -20.0], [6.0, 40, -22.0]]);
        $r = ReferenceStudy::soundEvents($high, $this->levels(10), $high, -15.0, null, null, [6.0]);
        $this->assertSame(2, $r['count']);
        $this->assertSame(['at' => 2.0, 'kind' => 'pop', 'on_cut' => false], array_intersect_key($r['events'][0], array_flip(['at', 'kind', 'on_cut'])));
        $this->assertSame(['at' => 6.0, 'kind' => 'whoosh', 'on_cut' => true], array_intersect_key($r['events'][1], array_flip(['at', 'kind', 'on_cut'])));
        $this->assertSame(['pop' => 1, 'whoosh' => 1], $r['kinds']);
    }

    public function test_quiet_bursts_under_the_voice_level_and_low_hits_on_the_music_beat_do_not_count(): void
    {
        $high = $this->levels(10, [[2.0, 8, -45.0]]);
        $this->assertSame(0, ReferenceStudy::soundEvents($high, $this->levels(10), $high, -15.0, null, null, [])['count'], 'too quiet to be heard beside the voice');
        $low = $this->levels(10, [[3.0, 10, -20.0]]);
        $this->assertSame('impact', ReferenceStudy::soundEvents($this->levels(10), $low, $low, -15.0, null, null, [])['events'][0]['kind']);
        $this->assertSame(0, ReferenceStudy::soundEvents($this->levels(10), $low, $low, -15.0, null, ['present' => true, 'beats' => [3.0]], [])['count'], 'the music\'s own beat');
    }

    public function test_without_separation_only_the_gaps_between_words_are_read(): void
    {
        $high = $this->levels(10, [[2.0, 8, -20.0], [5.0, 8, -20.0]]);
        $speech = ['words' => [['hello', 1.5, 2.5]]];
        $r = ReferenceStudy::soundEvents($high, $this->levels(10), $high, -15.0, $speech, null, []);
        $this->assertSame([5.0], array_column($r['events'], 'at'));
    }

    public function test_the_planner_reads_the_effects_and_none_says_none(): void
    {
        $brief = PlanService::studyBrief(7, ['duration_seconds' => 10, 'sound' => ['count' => 0, 'per_10_seconds' => 0, 'kinds' => [], 'events' => [], 'method' => 'separated']]);
        $this->assertSame(['count' => 0, 'per_10_seconds' => 0, 'kinds' => [], 'on_cuts' => null, 'read' => 'the whole video, voice removed', 'events' => []], $brief['sound_effects']);
    }
}
