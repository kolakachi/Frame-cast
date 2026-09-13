<?php

namespace Tests\Unit;

use App\Services\Media\CaptionAlignment;
use Tests\TestCase;

class CaptionAlignmentTest extends TestCase
{
    /** @param list<array{string,float,float}> $triples */
    private function asr(array $triples): array
    {
        return array_map(fn ($t) => ['text' => $t[0], 'start' => $t[1], 'end' => $t[2]], $triples);
    }

    private function texts(array $words): array
    {
        return array_column($words, 'text');
    }

    public function test_a_word_the_recogniser_dropped_is_still_captioned(): void
    {
        // The exact failure seen on project 181: "spend" missing, "Ugh" invented.
        $script = 'I used to spend hours turning one content idea into an actual video.';
        $words = CaptionAlignment::align($script, $this->asr([
            ['Ugh', 0.00, 0.78], ['I', 0.78, 1.16], ['used', 1.16, 1.28], ['to', 1.28, 1.82],
            ['hours', 1.82, 2.58], ['turning', 2.58, 2.98], ['one', 2.98, 3.68],
            ['content', 3.68, 4.26], ['idea', 4.26, 4.72], ['into', 4.72, 4.98],
            ['an', 4.98, 5.24], ['actual', 5.24, 5.78], ['video.', 5.78, 6.20],
        ]), 6.2);

        $this->assertSame(explode(' ', $script), $this->texts($words), 'every script word must be captioned');
        $this->assertNotContains('Ugh', $this->texts($words), 'invented words must be discarded');
    }

    public function test_a_recovered_word_sits_between_its_neighbours(): void
    {
        $words = CaptionAlignment::align('I used to spend hours', $this->asr([
            ['I', 0.0, 0.5], ['used', 0.5, 1.0], ['to', 1.0, 1.5], ['hours', 2.0, 2.5],
        ]), 2.5);

        $spend = collect($words)->firstWhere('text', 'spend');
        $this->assertNotNull($spend);
        $this->assertGreaterThanOrEqual(1.5, $spend['start'], 'starts after the preceding word');
        $this->assertLessThanOrEqual(2.0, $spend['end'], 'ends before the following word');
    }

    public function test_every_word_has_an_increasing_span_so_none_is_discarded_downstream(): void
    {
        // The exporter drops any word whose end is not after its start.
        $words = CaptionAlignment::align('one two three four five', $this->asr([
            ['one', 0.0, 0.4], ['five', 1.8, 2.2],
        ]), 2.2);

        $this->assertCount(5, $words);
        foreach ($words as $w) {
            $this->assertGreaterThan($w['start'], $w['end'], "'{$w['text']}' must have a positive span");
        }
    }

    public function test_order_is_preserved_when_the_recogniser_repeats_a_word(): void
    {
        $words = CaptionAlignment::align('go now go faster', $this->asr([
            ['go', 0.0, 0.3], ['go', 0.3, 0.6], ['now', 0.6, 0.9], ['go', 0.9, 1.2], ['faster', 1.2, 1.6],
        ]), 1.6);

        $this->assertSame(['go', 'now', 'go', 'faster'], $this->texts($words));
        for ($i = 1; $i < count($words); $i++) {
            $this->assertGreaterThanOrEqual($words[$i - 1]['start'], $words[$i]['start'], 'timings must not go backwards');
        }
    }

    public function test_punctuation_and_case_do_not_break_matching(): void
    {
        $words = CaptionAlignment::align("Don't stop — keep going!", $this->asr([
            ['dont', 0.0, 0.4], ['STOP', 0.4, 0.8], ['keep', 0.9, 1.2], ['going', 1.2, 1.6],
        ]), 1.6);

        $this->assertSame(["Don't", 'stop', '—', 'keep', 'going!'], $this->texts($words));
        $this->assertSame(0.0, $words[0]['start'], 'a matched word keeps the recognised timing');
    }

    public function test_no_usable_timing_still_captions_the_whole_script(): void
    {
        $words = CaptionAlignment::align('alpha beta gamma', [], 3.0);

        $this->assertSame(['alpha', 'beta', 'gamma'], $this->texts($words));
        $this->assertEqualsWithDelta(3.0, end($words)['end'], 0.01, 'spread across the audio');
    }

    public function test_an_empty_script_produces_nothing(): void
    {
        $this->assertSame([], CaptionAlignment::align('   ', $this->asr([['x', 0.0, 1.0]]), 1.0));
    }
}
