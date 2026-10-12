<?php

namespace Tests\Unit;

use App\Jobs\GenerateOneShotUgcJob;
use PHPUnit\Framework\TestCase;

/** Each clip is cut back to its own statement (owner, 2026-10-12), however the transcript writes it. */
class OneShotScriptSpanTest extends TestCase
{
    private static function words(string $text): array
    {
        $out = []; $t = 0.0;
        foreach (preg_split('/\s+/', $text) as $w) { $out[] = ['text' => $w, 'start' => $t, 'end' => $t + 0.3]; $t += 0.35; }
        return $out;
    }

    public function test_take_281s_first_clip_ends_on_its_own_line(): void
    {
        // "Okay" heard as "OK", then the label read aloud after the line.
        $said = self::words("OK, I need to talk about this body lotion. It's fragrance free.");
        $this->assertSame(['first' => 0, 'last' => 8], GenerateOneShotUgcJob::scriptSpan($said, 'Okay, I need to talk about this body lotion.'));
    }

    public function test_hyphens_mishearing_and_a_repeat_after_the_line(): void
    {
        $line = "It's fragrance-free, so it won't clash with my perfume.";
        $said = self::words("Um, it's fragrance free, so it won't clash with my perfume. It's fragrance free.");
        $span = GenerateOneShotUgcJob::scriptSpan($said, $line);
        $this->assertSame(1, $span['first'], 'the "um" before the line goes');
        $this->assertSame('perfume.', $said[$span['last']]['text'], 'the earliest full reading wins; the repeat is left out');
        $misheard = self::words("It's fragrance free, so it won't clash with my perfune.");
        $this->assertSame('perfune.', $misheard[GenerateOneShotUgcJob::scriptSpan($misheard, $line)['last']]['text'], 'a slightly misheard word still ends the line');
        $this->assertNull(GenerateOneShotUgcJob::scriptSpan(self::words('Something else entirely was said here today.'), $line));
    }
    public function test_missing_final_word_does_not_authorize_trimming(): void
    {
        $line = 'This body lotion leaves my skin feeling soft all day.';
        $this->assertNull(GenerateOneShotUgcJob::scriptSpan(
            self::words('This body lotion leaves my skin feeling soft all'), $line));
        $this->assertNull(GenerateOneShotUgcJob::scriptSpan(
            self::words('This body lotion leaves my skin feeling soft all um'), $line));
    }

    public function test_completion_gate_rejects_missing_speech_and_placeholder_transcripts(): void
    {
        $line = 'This is the complete line.';
        $this->assertNull(GenerateOneShotUgcJob::verifiedSpeechSpan(['provider_key' => 'local_fallback', 'words' => self::words($line)], $line));
        $this->assertNull(GenerateOneShotUgcJob::verifiedSpeechSpan(['words' => []], $line));
        $this->assertNull(GenerateOneShotUgcJob::verifiedSpeechSpan(['words' => self::words('This is the complete')], $line));
        $this->assertSame(['first' => 0, 'last' => 4], GenerateOneShotUgcJob::verifiedSpeechSpan(['words' => self::words($line)], $line));
        $this->assertSame(['first' => 0, 'last' => 0], GenerateOneShotUgcJob::verifiedSpeechSpan(['words' => self::words('Yes!')], 'Yes!'));
    }

}
