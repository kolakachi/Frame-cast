<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcOneShotCompiler;
use PHPUnit\Framework\TestCase;

class UgcOneShotCompilerTest extends TestCase
{
    private function beats(): array
    {
        return [
            ['script_text' => 'Line one.', 'seconds' => 5.4, 'visual_brief' => 'close selfie framing', 'voice_direction' => 'doubtful'],
            ['script_text' => 'Line two.', 'seconds' => 5, 'visual_brief' => 'she holds up the bottle'],
            ['script_text' => 'Line three.', 'seconds' => 3.1, 'visual_brief' => 'closer framing, delighted'],
        ];
    }

    public function test_beats_pack_into_veo_sized_chunks(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        $this->assertCount(3, $chunks); // 5.4 | 5 | 3.1 — none pair under 8s
        foreach ($chunks as $c) {
            $this->assertGreaterThanOrEqual(UgcOneShotCompiler::MIN_SEGMENT_SECONDS, $c['seconds']);
            $this->assertLessThanOrEqual(UgcOneShotCompiler::MAX_SEGMENT_SECONDS, $c['seconds']);
        }
    }

    public function test_short_beats_share_a_generation_with_a_cut(): void
    {
        $chunks = UgcOneShotCompiler::compile([
            ['script_text' => 'A.', 'seconds' => 3, 'visual_brief' => 'wide framing'],
            ['script_text' => 'B.', 'seconds' => 4, 'visual_brief' => 'macro of the label'],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Cut to macro of the label', $chunks[0]['prompt']);
    }

    public function test_a_continuation_chunk_keeps_its_beats_framing(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        // The bug this guards: chunk 1's action ("holds up the bottle") was
        // dropped because only cuts and the first chunk carried framing.
        $this->assertStringContainsString('holds up the bottle', $chunks[1]['prompt']);
        // A clip opening on a new beat is a new shot (it may start from the reference photos)...
        $this->assertStringContainsString('The next shot of the same presenter', $chunks[1]['prompt']);
        $this->assertSame([true, true, true], array_column($chunks, 'new_shot'));
        $this->assertStringNotContainsString('continues without a break', $chunks[0]['prompt']);
        // ...a long shot split to fit carries on from the last frame.
        $long = UgcOneShotCompiler::compile([['script_text' => 'One two three four five six. Seven eight nine ten eleven twelve. Thirteen fourteen fifteen sixteen.', 'seconds' => 14, 'visual_brief' => 'kitchen']]);
        $this->assertSame(true, $long[0]['new_shot']);
        $this->assertFalse($long[1]['new_shot']);
        $this->assertStringContainsString('continues without a break', $long[1]['prompt']);
    }

    public function test_omni_clips_run_up_to_ten_seconds_unsnapped(): void
    {
        $script = 'Okay, my mornings just got so much better. This is Crunchy Clouds, honey oat cereal. Look how light and crispy it is. Just pour, add milk, done. Seriously, try this.';
        $veo = UgcOneShotCompiler::compile([['script_text' => $script, 'seconds' => 17, 'visual_brief' => 'kitchen']]);
        $omni = UgcOneShotCompiler::compile([['script_text' => $script, 'seconds' => 17, 'visual_brief' => 'kitchen']], [], UgcOneShotCompiler::OMNI_SEGMENT_SECONDS);
        $this->assertLessThan(count($veo), count($omni), 'fewer joins on Omni');
        foreach ($omni as $c) $this->assertLessThanOrEqual(10, $c['seconds']);
        $this->assertSame($script, implode(' ', array_column($omni, 'dialogue')));
    }

    public function test_every_take_is_told_how_hands_and_props_behave(): void
    {
        $chunk = UgcOneShotCompiler::compile([['script_text' => 'Hi.', 'seconds' => 4, 'visual_brief' => 'kitchen']], ['wardrobe' => 'a rust knit sweater'])[0]['prompt'];
        $this->assertStringContainsString('props the phone up or sets it down', $chunk);
        $this->assertStringContainsString('Nothing floats', $chunk);
        $this->assertStringContainsString('wearing a rust knit sweater', $chunk);
    }

    public function test_dialogue_is_quoted_verbatim_and_collected(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        $this->assertStringContainsString('"Line one."', $chunks[0]['prompt']);
        $this->assertSame('Line one.', $chunks[0]['dialogue']);
        $this->assertStringContainsString('doubtful', $chunks[0]['prompt']);
    }

    public function test_style_reaches_the_preamble_of_every_chunk(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats(), [
            'presenter' => 'a man in a grey hoodie', 'product' => 'Fernwell Sleep+',
        ]);

        foreach ($chunks as $c) {
            $this->assertStringContainsStringIgnoringCase('a man in a grey hoodie', $c['prompt']);
            $this->assertStringContainsString('Fernwell Sleep+', $c['prompt']);
        }
    }

    public function test_empty_input_compiles_to_nothing(): void
    {
        $this->assertSame([], UgcOneShotCompiler::compile([]));
    }

    public function test_a_long_shot_is_split_at_sentences_so_every_word_is_said(): void
    {
        $script = 'Okay, my mornings just got so much better. This is Crunchy Clouds — honey oat cereal. Look how light and crispy it is. Just pour, add milk, done. Light, crispy, morning-ready. Seriously, try this.';
        $chunks = UgcOneShotCompiler::compile([['script_text' => $script, 'seconds' => 18, 'visual_brief' => 'kitchen counter, holding the carton']]);

        $this->assertGreaterThan(1, count($chunks), 'an 18-second shot never goes into one 8-second clip');
        foreach ($chunks as $c) $this->assertLessThanOrEqual(UgcOneShotCompiler::MAX_SEGMENT_SECONDS, $c['seconds']);
        $this->assertSame($script, implode(' ', array_column($chunks, 'dialogue')), 'every word, once, in order');
        $this->assertGreaterThanOrEqual(18, array_sum(array_column($chunks, 'seconds')));
        foreach ($chunks as $c) $this->assertStringNotContainsString('Cut to', $c['prompt'], 'one shot carrying on, no cuts');
    }

    public function test_headline_notes_never_reach_the_video_model(): void
    {
        $chunks = UgcOneShotCompiler::compile([['script_text' => 'This lotion sinks in fast.', 'seconds' => 5,
            'visual_brief' => 'Bright bathroom, she holds the tube at chest height. Safe space left above her head for headline text. Soft daylight.']]);
        $this->assertStringContainsString('she holds the tube at chest height', $chunks[0]['prompt']);
        $this->assertStringNotContainsStringIgnoringCase('headline', $chunks[0]['prompt']);
        $this->assertStringNotContainsStringIgnoringCase('safe space', $chunks[0]['prompt']);
    }
}
