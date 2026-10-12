<?php

namespace Tests\Unit;

use App\Services\Ugc\{UgcOneShotCompiler, UgcPlan, UgcStaging};
use Tests\TestCase;

/** Take 283 (2026-10-12): the pouch opened in every clip, and she held the phone, the pouch and the peanuts at once. */
class UgcPhysicsTest extends TestCase
{
    private function take283(): array
    {
        return [['kind' => 'on_camera', 'seconds' => 22, 'visual_brief' => 'Handheld phone-camera framing at a casual desk in an open-plan office, soft window light.',
            'script_text' => 'Okay, this Crunchy Pea pack has become my new quick snack. It is roasted peanuts, extra crunchy, and honestly perfect when I am working. Listen to that crunch. Seriously, grab one for your desk.',
            'motion_prompt' => 'She holds the pouch up near the lens. She peels open the resealable top. She tips a few peanuts into her palm. She takes a crunchy bite. She shows the pack again.']];
    }

    public function test_a_split_shot_shares_its_actions_out_and_never_repeats_them(): void
    {
        $clips = UgcOneShotCompiler::compile($this->take283(), [], UgcOneShotCompiler::OMNI_SEGMENT_SECONDS);
        $this->assertGreaterThan(1, count($clips));
        $peels = array_sum(array_map(fn ($c) => substr_count($c['prompt'], 'Action and sound: ') && str_contains(explode('Action and sound: ', $c['prompt'])[1] ?? '', 'peels open') ? 1 : 0, $clips));
        $this->assertSame(1, $peels, 'the pouch is opened in one clip only');
        $this->assertStringContainsString('Already done in the earlier shots, so never repeat it', $clips[1]['prompt']);
        $this->assertStringContainsString('in the same room: same furniture, walls, window', $clips[1]['prompt']);
    }

    public function test_two_handed_actions_prop_the_phone(): void
    {
        $clips = UgcOneShotCompiler::compile($this->take283(), [], UgcOneShotCompiler::OMNI_SEGMENT_SECONDS);
        $opening = collect($clips)->first(fn ($c) => str_contains($c['prompt'], 'peels open'));
        $this->assertStringContainsString('the phone is propped on the desk, table or counter for this shot', $opening['prompt']);
        $this->assertStringNotContainsString('one continuous handheld take', $opening['prompt']);
        $this->assertFalse(UgcOneShotCompiler::twoHanded('A bright open-plan office; cut to a close-up as she smiles.'), 'places and camera language are not actions');
        $this->assertTrue(UgcOneShotCompiler::twoHanded('She tips a few peanuts into her palm.'));
    }

    public function test_a_plan_shot_doing_three_things_goes_back_for_repair(): void
    {
        try {
            UgcPlan::normalise($this->take283(), 'direct_camera');
            $this->fail('a shot that opens, pours and tastes should be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Give each action its own short shot', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(['open', 'taste'], UgcPlan::actionKinds('In an open-plan office she opens the pouch and takes a bite.'));
    }

    public function test_the_ready_check_puts_each_shots_staging_before_the_closing_rules(): void
    {
        $this->assertSame([], UgcStaging::parse('{"shots":[{"staging":"one"}]}', 2), 'one line per shot or none');
        $lines = UgcStaging::parse('Sure: {"shots":[{"staging":"Phone propped. Left hand holds the open pouch."},{"staging":"Pouch stays open."}]}', 2);
        $chunks = UgcStaging::apply([['prompt' => "pre\n\nscene one\n\nclosing"], ['prompt' => "pre\n\nscene two\n\nclosing"]], $lines);
        $this->assertSame("pre\n\nscene one\n\nPhysical staging, follow exactly: Phone propped. Left hand holds the open pouch.\n\nclosing", $chunks[0]['prompt']);
        $this->assertSame([['prompt' => 'x']], UgcStaging::apply([['prompt' => 'x']], []), 'no check, no change');
    }

    public function test_a_silent_cutaway_with_no_source_is_generated_or_their_footage(): void
    {
        $plan = UgcPlan::normalise([
            ['kind' => 'on_camera', 'seconds' => 3, 'script_text' => 'Okay, this is my new snack.', 'visual_brief' => 'Kitchen counter.'],
            ['kind' => 'b_roll', 'seconds' => 2, 'script_text' => '', 'source' => null, 'visual_brief' => 'Close-up of her mouth.', 'motion_prompt' => 'Teeth bite a peanut with a crunch.', 'headline' => 'Crunch'],
            ['kind' => 'b_roll', 'seconds' => 2, 'script_text' => '', 'asset_id' => 77, 'visual_brief' => 'Product on the counter.', 'motion_prompt' => 'The pouch on the counter.', 'headline' => 'Crunch'],
        ], 'story');
        $this->assertSame(['generate', 'upload'], [$plan[1]['source'], $plan[2]['source']]);
    }

    public function test_a_silent_shot_is_only_that_shot_and_later_clips_keep_the_room(): void
    {
        $clips = UgcOneShotCompiler::compile([
            ['kind' => 'on_camera', 'seconds' => 3, 'script_text' => 'Listen to this crunch.', 'visual_brief' => 'Kitchen counter, waist-up.'],
            ['kind' => 'b_roll', 'seconds' => 2, 'script_text' => '', 'visual_brief' => 'Extreme close-up on her mouth.', 'motion_prompt' => 'Teeth bite a peanut with a crunch.'],
        ], ['presenter' => 'Aisha', 'setting' => 'a home kitchen', 'place_note' => 'If a reference image shows a place, choose your own spot.'], UgcOneShotCompiler::OMNI_SEGMENT_SECONDS);
        $this->assertStringContainsString('choose your own spot', $clips[0]['prompt']);
        $this->assertStringNotContainsString('choose your own spot', $clips[1]['prompt'], 'a later clip continues the room');
        $this->assertStringContainsString('The next shot of the same presenter in the same room', $clips[1]['prompt']);
        $this->assertStringContainsString('One silent shot from the first frame to the last', $clips[1]['prompt']);
        $this->assertStringNotContainsString('talks directly', $clips[1]['prompt']);
        $this->assertStringNotContainsString('The delivery is', $clips[1]['prompt']);
        $this->assertSame(2, $clips[1]['seconds'], 'a 2-second action is not padded to 4');
        $this->assertStringNotContainsString('never from the reference\'s own background', $clips[1]['prompt']);
    }

    public function test_a_storyboard_still_is_asked_for_the_shot_without_its_words_and_later_ones_keep_the_first_room(): void
    {
        $clip = "Preamble.\n\nThe next shot of the same presenter in the same room. Kitchen counter, waist-up. Action and sound: Tilts the pouch. The presenter says: \"It is roasted peanuts.\" They say exactly these words, in this order, and nothing else.\n\nPhysical staging, follow exactly: Phone propped. Left hand holds the open pouch.\n\nClosing rules.";
        $first = \App\Services\Ugc\UgcStoryboard::prompt($clip, 0, true, true);
        $later = \App\Services\Ugc\UgcStoryboard::prompt($clip, 2, true, false);
        $this->assertStringNotContainsString('roasted peanuts', $first, 'a still has no words to say');
        $this->assertStringNotContainsString('They say exactly', $first);
        $this->assertStringContainsString('Physical staging, follow exactly: Phone propped.', $first);
        $this->assertStringContainsString('Image 3 shows only the kind of place', $first);
        $this->assertStringContainsString('Image 2 is the opening frame of this same video: keep its exact room', $later);
        $this->assertStringNotContainsString('Closing rules', $later);
    }
}
