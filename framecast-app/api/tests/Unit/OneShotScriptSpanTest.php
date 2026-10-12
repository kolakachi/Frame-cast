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

    private static function heard(string $text): array
    {
        $words = [];
        foreach (preg_split('/\s+/', $text) as $k => $w) $words[] = ['text' => $w, 'start' => $k * 0.3, 'end' => $k * 0.3 + 0.25];
        return ['transcript' => $text, 'provider_key' => 'openai', 'words' => $words];
    }

    public function test_a_clip_that_ran_into_the_next_line_takes_those_words_off_it(): void
    {
        $carry = GenerateOneShotUgcJob::carry(self::heard('First try of Volt Rush. Okay, cracking it'), 'First try of Volt Rush.', 'Okay, cracking it open. Mm, real citrus.');
        $this->assertSame(['drop' => 3, 'add' => ''], $carry);
        $chunk = GenerateOneShotUgcJob::carryOver(['dialogue' => 'Okay, cracking it open. Mm, real citrus.', 'prompt' => 'Scene. The presenter says: "Okay, cracking it open. Mm, real citrus." Rest.'], $carry);
        $this->assertSame('open. Mm, real citrus.', $chunk['dialogue']);
        $this->assertStringContainsString('says: "open. Mm, real citrus."', $chunk['prompt']);
    }

    public function test_a_clip_that_left_its_line_unfinished_hands_the_rest_to_the_next(): void
    {
        $carry = GenerateOneShotUgcJob::carry(self::heard('My skin looks brighter and way'), 'My skin looks brighter and way less blotchy now.', 'I am really happy with it.');
        $this->assertSame(['drop' => 0, 'add' => 'less blotchy now.'], $carry);
        $sheet = ['shot' => 's', 'start' => 'a', 'end' => '', 'camera' => 'selfie_arm', 'blocks' => [
            ['when' => '00:00-00:02', 'view' => '', 'left' => 'x', 'right' => 'y', 'product' => '', 'face' => '', 'says' => 'I am really happy', 'sound' => '', 'check' => ''],
            ['when' => '00:02-00:04', 'view' => '', 'left' => 'x', 'right' => 'y', 'product' => '', 'face' => '', 'says' => 'with it.', 'sound' => '', 'check' => '']]];
        $chunk = GenerateOneShotUgcJob::carryOver(['dialogue' => 'I am really happy with it.', 'prompt' => 'old', 'sheet' => $sheet], $carry);
        $this->assertSame('less blotchy now. I am really happy with it.', $chunk['dialogue']);
        $this->assertSame('less blotchy now. I am really happy', $chunk['sheet']['blocks'][0]['says']);
        $this->assertStringContainsString('The presenter says: "less blotchy now. I am really happy"', $chunk['prompt']);
    }

    public function test_a_doubtful_transcript_changes_nothing(): void
    {
        $this->assertNull(GenerateOneShotUgcJob::carry(self::heard('First try of Volt Rush.'), 'First try of Volt Rush.', 'Okay, cracking it open.'), 'said as planned');
        $this->assertNull(GenerateOneShotUgcJob::carry(self::heard('Something else entirely was said here.'), 'First try of Volt Rush today.', 'Okay, cracking it open.'), 'not the line: unsure');
        $this->assertNull(GenerateOneShotUgcJob::carry(self::heard('First try of Volt Rush. Okay then.'), 'First try of Volt Rush.', 'Okay, cracking it open.'), 'one word in common is not a run-on');
    }
}
