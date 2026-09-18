<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcPlan;
use Tests\TestCase;

/**
 * Two failures the first real runs surfaced in an afternoon: the model wraps
 * its JSON in prose often enough that trusting the raw string produced five
 * "Syntax error" rejections in a day, and the transcription service returns
 * word timings with no segments at all, which read as "this ad is silent"
 * while holding its entire script.
 */
class UgcModelOutputTest extends TestCase
{
    public function test_plain_json_decodes(): void
    {
        $this->assertSame(['a' => 1], UgcPlan::decodeModelJson('{"a":1}'));
    }

    public function test_fenced_json_decodes(): void
    {
        $this->assertSame(['a' => 1], UgcPlan::decodeModelJson("```json\n{\"a\":1}\n```"));
    }

    public function test_json_wrapped_in_prose_decodes(): void
    {
        $reply = "Here is the structure you asked for:\n{\"beats\":[{\"role\":\"hook\"}]}\nLet me know if you need changes.";

        $this->assertSame(['beats' => [['role' => 'hook']]], UgcPlan::decodeModelJson($reply));
    }

    public function test_no_json_at_all_still_throws(): void
    {
        $this->expectException(\JsonException::class);
        UgcPlan::decodeModelJson('I could not produce a plan.');
    }

    public function test_words_become_passages_at_sentence_ends(): void
    {
        $words = [
            ['text' => 'I', 'start' => 0.0, 'end' => 0.2],
            ['text' => 'slept', 'start' => 0.2, 'end' => 0.5],
            ['text' => 'badly.', 'start' => 0.5, 'end' => 0.9],
            ['text' => 'Then', 'start' => 1.0, 'end' => 1.2],
            ['text' => 'this.', 'start' => 1.2, 'end' => 1.6],
        ];

        $segments = UgcPlan::segmentsFromWords($words);

        $this->assertCount(2, $segments);
        $this->assertSame('I slept badly.', $segments[0]['text']);
        $this->assertSame('Then this.', $segments[1]['text']);
        $this->assertEqualsWithDelta(0.9, $segments[0]['end'], 0.01);
    }

    public function test_a_long_pause_closes_a_passage_without_punctuation(): void
    {
        $words = [
            ['text' => 'three', 'start' => 0.0, 'end' => 0.3],
            ['text' => 'months', 'start' => 0.3, 'end' => 0.6],
            ['text' => 'ago', 'start' => 2.0, 'end' => 2.3], // 1.4s gap
        ];

        $segments = UgcPlan::segmentsFromWords($words);

        $this->assertCount(2, $segments);
        $this->assertSame('three months', $segments[0]['text']);
    }
}
