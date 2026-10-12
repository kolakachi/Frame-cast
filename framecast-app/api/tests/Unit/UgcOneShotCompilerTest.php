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
        // ...and so is each piece of a long shot split to fit (owner, 2026-10-12): a scene with its own statement, opening
        // on a jump cut, never the same shot carrying on.
        $long = UgcOneShotCompiler::compile([['script_text' => 'One two three four five six. Seven eight nine ten eleven twelve. Thirteen fourteen fifteen sixteen.', 'seconds' => 14, 'visual_brief' => 'kitchen']]);
        $this->assertSame(array_fill(0, count($long), true), array_column($long, 'new_shot'));
        $this->assertStringContainsString('A jump cut to a slightly closer framing of the same moment: kitchen', $long[1]['prompt']);
        $this->assertStringNotContainsString('continues without a break', $long[1]['prompt']);
        $this->assertSame('Seven eight nine ten eleven twelve.', $long[1]['dialogue'], 'one statement per clip');
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
        $this->assertStringContainsString('never appears in the frame', $chunk);
        $this->assertStringContainsString('propped on the counter', $chunk);
        $this->assertStringContainsString('Nothing floats', $chunk);
        $this->assertStringContainsString('wearing a rust knit sweater', $chunk);
    }

    public function test_dialogue_is_quoted_verbatim_and_collected(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        $this->assertStringContainsString('"Line one."', $chunks[0]['prompt']);
        $this->assertSame('Line one.', $chunks[0]['dialogue']);
        $this->assertStringContainsString('doubtful', $chunks[0]['prompt']);
        $this->assertStringContainsString('Finish each quoted line completely before any cut', $chunks[0]['prompt']);
        $this->assertStringContainsString('for a full second', $chunks[0]['prompt']);
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

    public function test_a_beat_with_more_words_than_its_planned_time_is_still_split(): void
    {
        $script = 'This lotion changed my mornings because it sinks in fast and never leaves my hands greasy or sticky at all today honestly try it';
        $chunks = UgcOneShotCompiler::compile([['script_text' => $script, 'seconds' => 8, 'visual_brief' => 'kitchen']]);
        $this->assertGreaterThan(1, count($chunks), '25 words do not fit 8 s');
        $this->assertSame($script, implode(' ', array_column($chunks, 'dialogue')));
    }
    public function test_underestimated_short_beat_gets_enough_time_without_needing_a_split(): void
    {
        $beat = ['script_text' => 'This body lotion leaves my skin feeling soft all day.', 'seconds' => 3];
        $this->assertSame(6, UgcOneShotCompiler::compile([$beat])[0]['seconds']);
        $this->assertSame(5, UgcOneShotCompiler::compile([$beat], [], 10)[0]['seconds']);
    }

    public function test_underestimated_beats_are_not_packed_into_one_overfull_clip(): void
    {
        $beat = ['script_text' => 'This body lotion leaves my skin feeling soft all day.', 'seconds' => 2];
        $chunks = UgcOneShotCompiler::compile([$beat, $beat]);
        $this->assertCount(2, $chunks);
        $this->assertSame([6, 6], array_column($chunks, 'seconds'));
    }

    public function test_each_split_clip_has_its_own_finishing_pause(): void
    {
        $script = implode(' ', array_fill(0, 42, 'word'));
        foreach ([8, 10] as $cap) {
            $chunks = UgcOneShotCompiler::compile([['script_text' => $script, 'seconds' => 5]], [], $cap);
            $this->assertSame($script, implode(' ', array_column($chunks, 'dialogue')));
            foreach ($chunks as $chunk) {
                $minimum = count(explode(' ', $chunk['dialogue'])) / 2.5 + 1;
                $this->assertGreaterThanOrEqual($minimum, $chunk['seconds']);
                $this->assertLessThanOrEqual($cap, $chunk['seconds']);
            }
        }
    }

    public function test_slow_delivery_gets_additional_speaking_time(): void
    {
        $beat = ['script_text' => 'This body lotion leaves my skin feeling soft all day.', 'seconds' => 3, 'speed' => 0.5];
        $chunks = UgcOneShotCompiler::compile([$beat], [], 10);
        $this->assertSame(9, $chunks[0]['seconds']);
    }

    public function test_single_generation_uses_speech_budget_and_refuses_an_overfull_script(): void
    {
        $beat = ['script_text' => 'This body lotion leaves my skin feeling soft all day.', 'seconds' => 3];
        $this->assertSame(5, UgcOneShotCompiler::compileSingle([$beat])['seconds']);
        $this->assertNull(UgcOneShotCompiler::compileSingle([
            ['script_text' => implode(' ', array_fill(0, 75, 'word')), 'seconds' => 15],
        ]));
    }

    public function test_silent_native_action_is_not_shortened_or_turned_into_a_selfie(): void
    {
        $beat = ['kind' => 'b_roll', 'script_text' => '', 'seconds' => 23, 'motion_prompt' => 'Tap the glass, then slowly pour water.', 'visual_brief' => 'Macro view of the glass.'];
        $chunks = UgcOneShotCompiler::compile([$beat], ['ugc_type' => 'asmr']);
        $this->assertGreaterThanOrEqual(23, array_sum(array_column($chunks, 'seconds')));
        foreach ($chunks as $chunk) {
            $this->assertSame('', $chunk['dialogue']);
            $this->assertStringContainsString('Tap the glass', $chunk['prompt']);
            $this->assertStringContainsString('No speech', $chunk['prompt']);
            $this->assertStringNotContainsString('selfie ad', $chunk['prompt']);
        }
        $single = UgcOneShotCompiler::compileSingle([$beat], ['ugc_type' => 'asmr']);
        $this->assertSame(23, $single['seconds']);
        $this->assertStringContainsString('Tap the glass', $single['prompt']);
    }

    public function test_skit_speakers_never_share_a_generation(): void
    {
        $chunks = UgcOneShotCompiler::compile([
            ['script_text' => 'What is that?', 'seconds' => 3, 'speaker' => 1],
            ['script_text' => 'Your new favourite.', 'seconds' => 3, 'speaker' => 2],
        ], ['ugc_type' => 'skit', 'speakers' => [1 => 'Ada', 2 => 'Ben']], 10);
        $this->assertCount(2, $chunks);
        $this->assertSame([1, 2], array_column($chunks, 'speaker'));
        $this->assertStringContainsString('Ada performs', $chunks[0]['prompt']);
        $this->assertStringContainsString('Ben performs', $chunks[1]['prompt']);
        $this->assertStringNotContainsString('same presenter', $chunks[1]['prompt']);
    }

    public function test_green_screen_has_a_keyable_background_and_lists_allow_scripted_numbers(): void
    {
        $beat = ['script_text' => 'One, it is useful. Two, it is simple.', 'seconds' => 8];
        $green = UgcOneShotCompiler::compile([$beat], ['ugc_type' => 'green_screen'])[0];
        $this->assertStringContainsString('#00FF00', $green['prompt']);
        $this->assertStringContainsString('Say numbers only where they appear in the script', $green['prompt']);
        $this->assertStringNotContainsString('no counting aloud', $green['prompt']);
    }

}
