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
        $clip = "Preamble.\n\nThe next shot of the same presenter in the same room: same furniture. One silent shot from the first frame to the last, nothing before or after it: phone propped on the desk, framed on Aisha's hands and the open pouch over her empty palm. Action and sound: She tips the pouch and peanuts pour into her palm. Rattle sound, no speech. Staging: the phone is propped on the desk.\n\nPhysical staging, follow exactly: Phone propped. Left hand holds the open pouch.\n\nClosing rules.";
        $talk = "Preamble.\n\nKitchen counter, waist-up. Action and sound: Holds the pouch up. The presenter says: \"It is roasted peanuts.\" They say exactly these words, in this order, and nothing else.\n\nClosing rules.";
        $first = \App\Services\Ugc\UgcStoryboard::prompt($talk, 0, true, true);
        $this->assertStringStartsWith('One frame from a vertical 9:16', $first);
        $this->assertStringContainsString('Image 1 is the product: copy its packaging exactly as printed', $first, 'the product photo leads');
        $this->assertStringContainsString('Image 2 is the presenter: keep their face', $first);
        $this->assertStringContainsString('Image 3 shows only the kind of place', $first);
        $this->assertStringNotContainsString('roasted peanuts', $first, 'a still has no words to say');
        $this->assertStringNotContainsString('They say exactly', $first);

        $this->assertTrue(\App\Services\Ugc\UgcStoryboard::handsOnly($clip));
        $this->assertFalse(\App\Services\Ugc\UgcStoryboard::handsOnly($talk));
        $later = \App\Services\Ugc\UgcStoryboard::prompt($clip, 2, true, false, false);
        $this->assertStringContainsString('only their hands and sleeves', $later);
        $this->assertStringContainsString('Image 3 is the opening frame of this same video: keep its exact room', $later);
        $this->assertStringContainsString('Right after this frame: She tips the pouch and peanuts pour into her palm. This frame is the moment before it', $later);
        $this->assertStringNotContainsString('Action and sound', $later);
        $this->assertStringNotContainsString('The next shot of the same presenter', $later);
        $this->assertStringContainsString('Physical staging, follow exactly: Phone propped.', $later);
        $this->assertStringContainsString('Staging: the phone is propped on the desk.', $later);
        $this->assertStringNotContainsString('Closing rules', $later);
    }

    public function test_the_reviewer_reads_a_verdict_and_a_still_carries_the_takes_clothes(): void
    {
        $this->assertSame(['ok' => false, 'problems' => ['open the pouch']], \App\Services\Ugc\UgcReview::parse('```json {"ok": false, "problems": ["open the pouch"]} ```'));
        $this->assertSame(['ok' => false, 'problems' => ['x']], \App\Services\Ugc\UgcReview::parse('{"ok": true, "problems": ["x"]}'), 'a named problem is never a pass');
        $this->assertNull(\App\Services\Ugc\UgcReview::parse('looks fine'));
        $prompt = \App\Services\Ugc\UgcStoryboard::prompt("Pre.\n\nDesk, waist-up.\n\nClosing.", 0, true, false, true, 'a rust ribbed knit sweater');
        $this->assertStringContainsString('The presenter wears a rust ribbed knit sweater, never the clothes in their photo.', $prompt);
    }

    public function test_a_faulted_still_is_made_once_more_with_its_fixes(): void
    {
        \Illuminate\Support\Facades\Http::fake(['img.test/*' => \Illuminate\Support\Facades\Http::response('png-bytes')]);
        $asked = [];
        $images = \Mockery::mock(\App\Services\Generation\Image\NanoBananaProImageAdapter::class);
        $images->shouldReceive('generate')->twice()->andReturnUsing(function ($prompt) use (&$asked) { $asked[] = $prompt; return ['image_url' => 'https://img.test/'.count($asked)]; });
        $review = \Mockery::mock(\App\Services\Ugc\UgcReview::class);
        $review->shouldReceive('still')->twice()->andReturn(['ok' => false, 'problems' => ['The label is redrawn: copy the logo.']], ['ok' => true, 'problems' => []]);
        $board = new \App\Services\Ugc\UgcStoryboard($images, $review);

        $still = $board->one([['prompt' => "Pre.\n\nDesk, waist-up. Action and sound: Holds the pouch up.\n\nClosing.", 'wardrobe' => 'a rust sweater']], ['data:image/png;base64,AAAA', 'data:image/png;base64,BBBB'], 0, null);
        $this->assertStringStartsWith('data:image/png;base64,', $still);
        $this->assertStringContainsString('Fix these in this frame: The label is redrawn: copy the logo.', $asked[1]);
        $this->assertTrue($board->notes[0]['remade']['ok']);
    }

    public function test_a_timed_sheet_is_the_clips_whole_short_prompt_with_the_scripts_words(): void
    {
        $answer = '{"clips":[{"camera":"propped_hands","shot":"Soft window light at the desk, rust sweater sleeves.","start":"Pouch sealed, upright on the desk; both hands beside it, the phone on its stand.","blocks":['
            .'{"when":"00:00-00:02","left":"Holds the pouch at its base","right":"Pinches the top of the seal","product":"Sealed, upright","face":"Not in frame","says":"Okay, look.","sound":"","check":"Is her right hand pinching the seal?"},'
            .'{"when":"00:02-00:04","left":"Keeps holding the base","right":"Pulls the seal open","product":"Top open, peanuts visible","face":"Not in frame","says":"It opens easy.","sound":"Crisp zip tear","check":"Is the pouch open?"}],'
            .'"end":"Pouch open on the desk."}]}';
        $sheets = \App\Services\Ugc\UgcActionSheet::parse($answer, 1);
        $this->assertCount(2, $sheets[0]['blocks']);
        $this->assertSame([], \App\Services\Ugc\UgcActionSheet::parse($answer, 2), 'one sheet per clip or none');

        $long = "Pre with a long rulebook.\n\nDesk. Action and sound: Both hands pull it open.\n\nNo subtitles, no second pack, never cut mid-word.";
        $chunks = \App\Services\Ugc\UgcActionSheet::apply([['prompt' => $long, 'dialogue' => 'Okay, look. It opens easy.']], $sheets);
        $prompt = $chunks[0]['prompt'];
        $this->assertStringStartsWith(\App\Services\Ugc\UgcActionSheet::CAMERAS['propped_hands']['shot']." Soft window light at the desk, rust sweater sleeves.\nFirst frame: Pouch sealed, upright on the desk; both hands beside it, the camera on its stand.\n"
            ."[00:00-00:02] Left hand: Holds the pouch at its base. Right hand: Pinches the top of the seal. Product: Sealed, upright. Face: Not in frame. The presenter says: \"Okay, look.\"\n"
            ."[00:02-00:04] Left hand: Keeps holding the base. Right hand: Pulls the seal open. Product: Top open, peanuts visible. Face: Not in frame. The presenter says: \"It opens easy.\" SFX: Crisp zip tear.\n"
            ."Last frame: Pouch open on the desk.\nAmbient noise: quiet room tone.\n", $prompt);
        $this->assertStringContainsString('The only writing in the picture is the product\'s own label.', $prompt);
        $this->assertStringContainsString('so no phone is ever seen in the picture', $prompt);
        $this->assertStringNotContainsString('the phone on its stand', $prompt, 'the camera is never an object in the picture');
        foreach (['rulebook', 'subtitles', 'Action and sound', 'second pack'] as $gone) $this->assertStringNotContainsString($gone, $prompt, 'the sheet replaces the old prompt');
        $this->assertLessThan(1300, mb_strlen($prompt), 'short');
        $this->assertSame($sheets[0], $chunks[0]['sheet'], 'kept for the reviewer');

        // Words that are not the script's are dropped from the blocks and the line is said whole.
        $altered = \App\Services\Ugc\UgcActionSheet::apply([['prompt' => $long, 'dialogue' => 'Okay, look at this. It opens easy.']], $sheets)[0]['prompt'];
        $this->assertStringNotContainsString('says: "Okay, look."', $altered);
        $this->assertStringContainsString('The presenter says: "Okay, look at this. It opens easy."', $altered);
    }

    public function test_the_sheet_reviewer_turns_each_no_into_the_moment_to_show(): void
    {
        $qs = ['Image 1: Is her right hand on the seal?', 'Image 2: Is the pouch open?', 'Across all images: same place?', 'Across all images: free of words?', 'Across all images: same clothes?'];
        $v = \App\Services\Ugc\UgcReview::answers('{"answers":[true,false,true,true,false]}', $qs, ['Right hand: pinches the seal.', 'Right hand: pulls the seal open.']);
        $this->assertFalse($v['ok']);
        $this->assertSame(['Show this happening: Right hand: pulls the seal open.', 'No to: same clothes?'], $v['problems']);
        $this->assertNull(\App\Services\Ugc\UgcReview::answers('{"answers":[true]}', $qs, []), 'an answer per question or no verdict');
    }

    public function test_a_sheet_whose_words_or_length_are_off_is_sent_back_with_its_faults(): void
    {
        $block = fn ($when, $says) => ['when' => $when, 'left' => 'x', 'right' => 'y', 'product' => '', 'face' => '', 'says' => $says, 'sound' => '', 'check' => ''];
        $chunks = [['dialogue' => 'Listen to this. Okay, really crunchy.', 'seconds' => 9]];
        $good = [['camera' => 'selfie_arm', 'shot' => 's', 'start' => 'a', 'end' => '', 'blocks' => [$block('00:00-00:04', 'Listen to this.'), $block('00:04-00:09', 'Okay, really crunchy.')]]];
        $this->assertSame([], \App\Services\Ugc\UgcActionSheet::faults($good, $chunks));
        $bad = [['camera' => 'selfie_arm', 'shot' => 's', 'start' => 'a', 'end' => '', 'blocks' => [$block('00:00-00:03', 'Listen.'), $block('00:03-00:07', 'Okay, really crunchy.')]]];
        $faults = \App\Services\Ugc\UgcActionSheet::faults($bad, $chunks);
        $this->assertCount(2, $faults);
        $this->assertStringContainsString('must be exactly "Listen to this. Okay, really crunchy."', $faults[0]);
        $this->assertStringContainsString('must run to 00:09', $faults[1]);
    }

    public function test_the_reviewer_looks_at_a_talking_block_while_its_words_are_said(): void
    {
        $blocks = [['when' => '00:00-00:02', 'says' => 'Listen to this.'], ['when' => '00:02-00:04', 'says' => ''], ['when' => '00:04-00:06', 'says' => 'Okay, really crunchy.']];
        $heard = [['text' => 'Listen', 'start' => 0.2, 'end' => 0.5], ['text' => 'to', 'start' => 0.5, 'end' => 0.6], ['text' => 'this', 'start' => 0.6, 'end' => 1.0],
            ['text' => 'Okay', 'start' => 6.0, 'end' => 6.3], ['text' => 'really', 'start' => 6.4, 'end' => 6.7], ['text' => 'crunchy', 'start' => 6.7, 'end' => 7.2]];
        // The clip came out 9 s long against 6 planned: the silent block is scaled, the talking ones follow the words.
        $this->assertEqualsWithDelta([0.6, 4.5, 6.6], \App\Services\Ugc\UgcReview::moments($blocks, 9.0, $heard), 0.01);
    }

    public function test_the_camera_library_never_puts_a_phone_in_the_picture_and_a_move_is_timed_in_its_block(): void
    {
        foreach (\App\Services\Ugc\UgcActionSheet::CAMERAS as $key => $camera) {
            if ($key !== 'mirror_selfie') $this->assertStringNotContainsStringIgnoringCase('phone', $camera['shot'], $key);
        }
        $sheet = ['camera' => 'set_down', 'shot' => 'Kitchen counter', 'start' => 'Left hand: holds the camera', 'end' => '', 'blocks' => [
            ['when' => '00:00-00:02', 'view' => '', 'left' => 'holds the camera, out of view', 'right' => 'holds the pouch', 'product' => 'sealed', 'face' => 'on the lens', 'says' => 'Watch this.', 'sound' => '', 'check' => ''],
            ['when' => '00:02-00:03', 'view' => 'the view tips down and settles low on the counter', 'left' => 'sets the phone down facing her', 'right' => 'holds the pouch', 'product' => 'sealed', 'face' => 'on the lens', 'says' => '', 'sound' => '', 'check' => ''],
        ]];
        $prompt = \App\Services\Ugc\UgcActionSheet::prompt($sheet, 'Watch this.');
        $this->assertStringStartsWith(\App\Services\Ugc\UgcActionSheet::CAMERAS['set_down']['shot'], $prompt);
        $this->assertStringContainsString('[00:02-00:03] View: the view tips down and settles low on the counter. Left hand: sets the camera down facing her.', $prompt);
    }

    public function test_the_look_keeps_the_person_dresses_them_and_makes_its_own_version_of_the_place(): void
    {
        $prompt = \App\Services\Ugc\UgcLook::prompt('a rust ribbed knit sweater', 'this kind of place: a home desk with a laptop', true);
        $this->assertStringContainsString('exactly the same face, skin tone, hair, any head covering and age', $prompt);
        $this->assertStringContainsString('They wear a rust ribbed knit sweater.', $prompt);
        $this->assertStringContainsString('They are in a home desk with a laptop, the same kind of place as image 2 with the same feel, but your own version of it', $prompt);
    }
}
