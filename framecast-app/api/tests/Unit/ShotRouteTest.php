<?php

namespace Tests\Unit;

use App\Services\Create\PlanService;
use App\Services\Create\ReferenceMatch;
use App\Services\Create\ShotRoute;
use Tests\TestCase;

/**
 * Generated video goes to the model that fits each shot, within what the models
 * can do and the owner's rules: references not first frames, the user's avatar
 * never on Seedance, Veo's reference limits, prices from the list.
 */
class ShotRouteTest extends TestCase
{
    private array $ctx = ['has_avatar' => true, 'has_sheet' => true, 'aspect_ratio' => '9:16', 'video_tier' => 'standard', 'subjects' => ['Shop owner', 'Shop']];

    public function test_a_shot_with_the_users_avatar_never_goes_to_seedance(): void
    {
        foreach (['seedance25', 'seedance_lite', null] as $engine) {
            $r = ShotRoute::shot(['engine' => $engine, 'refs' => ['avatar', 'sheet'], 'seconds' => 6], $this->ctx);
            $this->assertSame('omni', $r['engine'], "asked for {$engine}");
            $this->assertSame(6 * ShotRoute::perSecond('omni'), $r['credits']);
        }
        $first = ShotRoute::shot(['engine' => 'seedance_lite', 'first_frame' => 'avatar', 'seconds' => 5], $this->ctx);
        $this->assertSame('omni', $first['engine'], 'a start frame of the user goes to Omni, not Seedance');
        $this->assertSame('avatar', $first['first_frame']);
    }

    public function test_world_shots_use_references_on_the_planners_engine(): void
    {
        $r = ShotRoute::shot(['engine' => 'seedance25', 'refs' => ['sheet'], 'seconds' => 3.4, 'aspect' => '1:1'], $this->ctx);
        $this->assertSame(['seedance25', 4, '1:1', ['sheet']], [$r['engine'], $r['seconds'], $r['aspect'], $r['refs']], 'a split half gets a square clip; 4 s is Seedance\'s shortest');
        $this->assertSame(4 * ShotRoute::perSecond('seedance25'), $r['credits']);
        $this->assertSame('omni', ShotRoute::shot(['engine' => 'omni', 'refs' => ['sheet'], 'aspect' => '1:1'], $this->ctx)['engine']);
        $this->assertSame('9:16', ShotRoute::shot(['engine' => 'omni', 'refs' => ['sheet'], 'aspect' => '1:1'], $this->ctx)['aspect'], 'Omni makes portrait or landscape only');
    }

    public function test_premium_uses_veo_only_where_veo_can_keep_the_references(): void
    {
        $premium = ['video_tier' => 'premium'] + $this->ctx;
        $portrait = ShotRoute::shot(['engine' => 'seedance25', 'refs' => ['sheet'], 'seconds' => 5, 'aspect' => '9:16'], $premium);
        $this->assertSame('seedance25', $portrait['engine'], 'Veo takes references only at 16:9; a portrait shot would be mostly cropped');
        $wide = ShotRoute::shot(['engine' => 'seedance25', 'refs' => ['sheet'], 'seconds' => 5, 'aspect' => '16:9'], $premium);
        $this->assertSame(['veo_hq', 8, '16:9'], [$wide['engine'], $wide['seconds'], $wide['aspect']]);
        $this->assertSame('seedance25', ShotRoute::shot(['engine' => 'veo_hq', 'refs' => ['sheet'], 'seconds' => 12, 'aspect' => '16:9'], $this->ctx)['engine'], 'longer than Veo makes');
    }

    public function test_references_the_plan_cannot_supply_are_dropped(): void
    {
        $r = ShotRoute::shot(['engine' => 'seedance25', 'refs' => ['avatar', 'sheet']], ['has_avatar' => false, 'has_sheet' => false] + $this->ctx);
        $this->assertSame([], $r['refs']);
        $this->assertSame('seedance25', $r['engine'], 'no avatar attached, so nothing forces Omni');
    }

    public function test_a_ugc_take_is_cut_into_segments_the_engine_can_make(): void
    {
        $narration = ['Every brand starts somewhere, usually right at a kitchen table late at night.', 'Paste one line into WyvStudio.', 'It turns it into a voiced, captioned video.', 'Schedule it to YouTube, TikTok or Instagram.', 'Start with the nine dollar Test Pass.'];
        $r = ShotRoute::take(['presenter' => 'avatar'], ['narration' => $narration] + $this->ctx);
        $this->assertSame('omni', $r['engine']);
        $this->assertGreaterThan(1, count($r['segments']));
        foreach ($r['segments'] as $seg) $this->assertLessThanOrEqual(10, $seg['seconds']);
        $this->assertSame($narration, array_merge(...array_column($r['segments'], 'lines')), 'every line is spoken, in order');
        $this->assertSame($r['seconds'] * ShotRoute::perSecond('omni'), $r['credits']);
        $this->assertSame('omni', ShotRoute::take(['presenter' => 'avatar'], ['narration' => $narration, 'video_tier' => 'premium'] + $this->ctx)['engine'], 'a portrait take stays on Omni even on Premium');
    }

    public function test_a_ugc_take_replaces_the_voiceover_and_sheet_plans_are_priced_by_route(): void
    {
        $plan = ['media' => [
            ['kind' => 'reference_sheet', 'description' => 'anime night town', 'subjects' => [['name' => 'Shop owner', 'looks' => 'young woman, apron'], ['name' => 'Shop', 'looks' => 'candle shop at night']], 'credits' => 70],
            ['kind' => 'generated_shot', 'description' => 'push through the lit window', 'engine' => 'seedance25', 'refs' => ['sheet'], 'seconds' => 5, 'credits' => 1],
            ['kind' => 'ugc_take', 'description' => 'founder to camera', 'presenter' => 'avatar', 'credits' => 1],
            ['kind' => 'voiceover', 'description' => 'narration', 'credits' => 3],
        ], 'selections' => ['narration' => ['Every brand starts here.', 'Start with the Test Pass.'], 'choices' => []], 'decisions' => [], 'shot_context' => ['has_avatar' => true, 'aspect_ratio' => '9:16']];
        $media = PlanService::selectedMedia($plan);
        $this->assertSame('reference_sheet', $media[0]['kind'], 'the sheet is made before the clips that use it');
        $this->assertNotContains('voiceover', array_column($media, 'kind'), 'the take speaks the script');
        $sheet = collect($media)->firstWhere('kind', 'reference_sheet');
        $this->assertSame(70, $sheet['credits']);
        $this->assertSame(5 * ShotRoute::perSecond('seedance25'), collect($media)->firstWhere('kind', 'generated_shot')['credits']);
        $this->assertTrue(ShotRoute::usesSheet(collect($media)->firstWhere('kind', 'generated_shot')));
        $this->assertFalse(ShotRoute::usesSheet(collect($media)->firstWhere('kind', 'ugc_take')), 'an avatar take needs no sheet');
    }

    public function test_a_short_match_reply_with_a_typo_still_answers(): void
    {
        $this->assertSame('exact', ReferenceMatch::answer('exaclty and also 16:9'));
        $this->assertSame('exact', ReferenceMatch::answer('Exactly'));
        $this->assertSame('inspired', ReferenceMatch::answer('inpsired'));
        $this->assertNull(ReferenceMatch::answer('make it nicer'));
    }

    public function test_only_an_unsent_prediction_counts_as_never_started(): void
    {
        $e = fn (string $url) => new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: api.replicate.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$url);
        $this->assertTrue(\App\Services\Create\PlanMediaService::neverConnected($e('https://api.replicate.com/v1/models/elevenlabs/music/predictions'), 'music'), 'creating it never connected: nothing exists');
        $this->assertFalse(\App\Services\Create\PlanMediaService::neverConnected($e('https://api.replicate.com/v1/predictions/abc123'), 'music'), 'polling failed: a prediction exists and may bill');
        $this->assertFalse(\App\Services\Create\PlanMediaService::neverConnected($e('https://api.replicate.com/v1/models/x/y/predictions'), 'ugc_take'), 'an earlier segment may have been made');
    }

    public function test_every_approved_word_survives_a_long_script(): void
    {
        $words = fn (array $ls) => preg_split('/\s+/u', trim(implode(' ', $ls)), -1, PREG_SPLIT_NO_EMPTY);
        $eight = array_map(fn ($i) => "Line $i says that WyvStudio turns one idea into a voiced captioned video you can post today without a camera", range(1, 8));
        $r = ShotRoute::take(['presenter' => 'avatar'], ['narration' => $eight] + $this->ctx);
        $this->assertSame($words($eight), $words(array_merge(...array_column($r['segments'], 'lines'))), 'all eight lines, every word, in order');
        foreach ($r['segments'] as $seg) $this->assertLessThanOrEqual(10, $seg['seconds']);
        $this->assertGreaterThan(6, count($r['segments']), 'more than six parts are kept, not dropped');

        $fifty = [trim(implode(' ', array_map(fn ($i) => 'word'.$i.($i % 12 === 0 ? ',' : ''), range(1, 50))))];
        $long = ShotRoute::take(['presenter' => 'avatar'], ['narration' => $fifty] + $this->ctx);
        $this->assertSame($words($fifty), $words(array_merge(...array_column($long['segments'], 'lines'))));
        $this->assertGreaterThanOrEqual(3, count($long['segments']), 'a 21 s line is split, not squeezed into 10 s');
        foreach ($long['segments'] as $seg) $this->assertLessThanOrEqual(10, ShotRoute::speechSeconds(implode(' ', $seg['lines'])) + 0.8);
    }

    public function test_scripts_without_spaces_are_timed_and_split_by_characters(): void
    {
        $ja = 'ワイブスタジオは、ひとつのアイデアを、声と字幕のついた動画に変えます。カメラも編集も必要ありません。今日から投稿できます。';
        $r = ShotRoute::take(['presenter' => 'avatar'], ['narration' => [$ja, $ja], 'language' => 'ja'] + $this->ctx);
        $this->assertSame($ja.$ja, implode('', array_merge(...array_column($r['segments'], 'lines'))), 'every character kept');
        $this->assertGreaterThan(5, ShotRoute::speechSeconds($ja, 'ja'), 'not counted as one word');
    }

    public function test_words_the_take_never_said_are_listed_in_order(): void
    {
        $missing = \App\Services\Create\PlanMediaExecutor::missingWords(['start', 'with', 'the', 'nine', 'dollar', 'test', 'pass'], ['start', 'with', 'the', 'test', 'pass', 'today']);
        $this->assertSame(['nine', 'dollar'], $missing);
        $this->assertSame([], \App\Services\Create\PlanMediaExecutor::missingWords(['a', 'b'], ['um', 'a', 'b']));
    }

    public function test_each_engine_gets_only_the_inputs_it_takes_and_says_so(): void
    {
        $omni = ShotRoute::shot(['engine' => 'omni', 'first_frame' => 'Shop owner', 'refs' => ['avatar']], $this->ctx);
        $this->assertSame(['omni', 'Shop owner', []], [$omni['engine'], $omni['first_frame'], $omni['refs']], 'Omni refuses a start frame with references: the start frame wins');
        $this->assertStringContainsString('not both', implode(' ', $omni['route_notes'] ?? []));
        $veo = ShotRoute::shot(['engine' => 'veo_hq', 'first_frame' => 'Shop owner', 'refs' => ['avatar'], 'seconds' => 8, 'aspect' => '16:9'], ['aspect_ratio' => '16:9', 'video_tier' => 'premium'] + $this->ctx);
        $this->assertSame(['veo_hq', ['avatar']], [$veo['engine'], $veo['refs']], 'Veo HQ keeps both');

        $seed = ShotRoute::shot(['engine' => 'seedance25', 'first_frame' => 'Shop owner', 'refs' => ['sheet']], ['has_avatar' => false] + $this->ctx);
        $this->assertSame(['seedance25', 'Shop owner', []], [$seed['engine'], $seed['first_frame'], $seed['refs']]);
        $this->assertStringContainsString('not both', implode(' ', $seed['route_notes']), 'dropping the references is stated');

        $kling = ShotRoute::shot(['engine' => 'kling', 'refs' => ['Shop']], ['has_avatar' => false] + $this->ctx);
        $this->assertSame('seedance25', $kling['engine'], 'a first-frame model with no start frame moves to one that takes references');
        $this->assertNotEmpty($kling['route_notes']);
    }

    public function test_an_input_the_plan_does_not_have_is_a_problem_not_a_fallback(): void
    {
        $r = ShotRoute::shot(['engine' => 'omni', 'first_frame' => 'Courier', 'refs' => ['sheet:Shop', 'Dragon']], $this->ctx);
        $this->assertNull($r['first_frame'] ?? null, 'no other image takes the unknown one\'s place');
        $this->assertSame(['sheet:Shop'], $r['refs']);
        $this->assertStringContainsString('Courier', $r['problem']);
        $this->assertStringContainsString('Dragon', $r['problem']);
        $this->assertArrayNotHasKey('problem', ShotRoute::shot(['engine' => 'omni', 'refs' => ['avatar', 'Shop owner']], $this->ctx));
        $this->assertStringContainsString('avatar', ShotRoute::shot(['refs' => ['avatar']], ['has_avatar' => false] + $this->ctx)['problem'], 'no photo attached');
        $this->assertTrue(ShotRoute::usesSheet(['kind' => 'generated_shot', 'refs' => ['Shop owner']]));
    }

    public function test_a_cloned_voice_lip_syncs_the_take_to_the_cloned_narration(): void
    {
        $take = ShotRoute::take(['presenter' => 'avatar'], ['narration' => ['Start with the Test Pass.'], 'voice' => 'clone'] + $this->ctx);
        $this->assertSame(['lipsync', 'cloned_lipsync'], [$take['engine'], $take['speech_mode']]);
        $plan = ['media' => [['kind' => 'ugc_take', 'description' => 'founder', 'presenter' => 'avatar', 'credits' => 1]],
            'selections' => ['narration' => ['Start with the Test Pass.'], 'voice' => 'clone', 'choices' => []], 'decisions' => [], 'shot_context' => ['has_avatar' => true]];
        $kinds = array_column(PlanService::selectedMedia($plan), 'kind');
        $this->assertContains('cloned_voiceover', $kinds, 'the cloned narration is bought for the take to follow');
        $plan['selections']['voice'] = 'Puck';
        $this->assertNotContains('voiceover', array_column(PlanService::selectedMedia($plan), 'kind'), 'native speech needs no narration');
        $this->assertArrayHasKey('problem', ShotRoute::take([], ['narration' => ['Hi.'], 'voice' => 'clone', 'has_avatar' => false, 'has_sheet' => false]), 'lip-sync needs a presenter image');
    }

    public function test_provider_cost_comes_from_the_providers_output_metrics_not_the_credit_price(): void
    {
        $this->assertSame(0.9248, ShotRoute::providerUsd('seedance25', ['video_output_duration_seconds' => 4, 'resolution_target' => '720p'], 5));
        $this->assertSame(0.4112, ShotRoute::providerUsd('seedance25', ['video_output_duration_seconds' => 4, 'resolution_target' => '480p'], 5));
        $this->assertSame(1.2, ShotRoute::providerUsd('omni', [], 8.0), 'the requested length when the provider reports none');
        $this->assertNull(ShotRoute::providerUsd('mystery', [], 4), 'an unknown model is unknown, not a guess');
    }

    public function test_similar_is_its_own_mode_between_exact_and_inspired(): void
    {
        $this->assertSame('similar', ReferenceMatch::answer('I love this video, I want to do something similar for wyvstudio'));
        $this->assertSame('similar', ReferenceMatch::answer('keep the drawing style'));
        $this->assertSame('similar', ReferenceMatch::answer('simlar'));
        $this->assertSame('exact', ReferenceMatch::answer('copy it exactly, same style'), 'exact wording outranks a style word');
        $this->assertSame('inspired', ReferenceMatch::answer('just inspired by it, my own take'));
        $this->assertNull(ReferenceMatch::answer('similar but loosely inspired'), 'two different answers: ask');
    }

    public function test_the_agreement_is_cleaned_and_never_without_required_items(): void
    {
        $a = PlanService::agreement(['preserve' => ['the drawn world', '', 'the drawn world'], 'required' => array_fill(0, 9, 'x'), 'junk' => ['y']]);
        $this->assertSame(['the drawn world'], $a['preserve']);
        $this->assertSame(['x'], $a['required'], 'duplicates fold');
        $this->assertSame(['preserve', 'replace', 'flexible', 'required'], array_keys($a));
    }

    public function test_a_shot_prompt_carries_its_direction_and_keeps_eyes_off_the_camera(): void
    {
        $prompt = new \ReflectionMethod(\App\Services\Create\PlanMediaExecutor::class, 'shotPrompt');
        $shot = ['action' => 'she watches the upload finish and leans forward with relief', 'gaze' => 'on her laptop', 'camera' => 'slow push in', 'end_state' => 'her smile in the screen glow', 'audio' => 'ambient'];
        $text = $prompt->invoke(app(\App\Services\Create\PlanMediaExecutor::class), 'Night kitchen, anime look', $shot, []);
        foreach (['Action: she watches', 'Gaze: on her laptop', 'Camera: slow push in', 'It ends on: her smile', 'People never look into the camera'] as $part) $this->assertStringContainsString($part, $text);
        $this->assertStringNotContainsString('centred', $text, 'the approved composition decides the framing');
        $toCamera = $prompt->invoke(app(\App\Services\Create\PlanMediaExecutor::class), 'UGC', ['gaze' => 'straight into the camera', 'audio' => 'speech', 'line' => 'Hi'], []);
        $this->assertStringNotContainsString('never look into the camera', $toCamera, 'a to-camera shot is allowed to look at us');
    }

    public function test_the_plan_sends_back_shots_without_an_action_or_a_persons_gaze(): void
    {
        $plan = ['scenes' => [['label' => 'A', 'start' => 0, 'end' => 15]], 'narration' => ['Hi.'], 'media' => [
            ['kind' => 'reference_sheet', 'subjects' => [['name' => 'Maya', 'kind' => 'character', 'looks' => 'red coat'], ['name' => 'Candle', 'kind' => 'product', 'looks' => 'amber jar']]],
            ['kind' => 'generated_shot', 'description' => 'close on Maya', 'refs' => ['Maya'], 'camera' => 'push in'],
            ['kind' => 'generated_shot', 'description' => 'Maya at the shop', 'refs' => ['Maya'], 'action' => 'she lights the candle'],
            ['kind' => 'generated_shot', 'description' => 'the candle', 'refs' => ['Candle'], 'action' => 'the flame catches and steadies'],
        ]];
        $problems = implode(' | ', \App\Services\Create\Planning\PlanPrompt::problems($plan, ['settings' => ['duration_seconds' => 15]]));
        $this->assertStringContainsString('Generated shot 1 has no action', $problems);
        $this->assertStringContainsString('Generated shot 2 shows a person but not where they look', $problems);
        $this->assertStringNotContainsString('Generated shot 3', $problems, 'a product needs no gaze');
        $this->assertSame('character', ShotRoute::sheet(['subjects' => [['name' => 'X']]])['subjects'][0]['kind']);
    }

    public function test_a_cast_sheet_brings_a_storyboard_and_every_shot_starts_from_its_panel(): void
    {
        $plan = ['media' => [
            ['kind' => 'reference_sheet', 'description' => 'anime night', 'subjects' => [['name' => 'Maya', 'kind' => 'character', 'looks' => 'red coat'], ['name' => 'Shop', 'kind' => 'place', 'looks' => 'candle shop']]],
            ['kind' => 'generated_shot', 'description' => 'Maya lights a candle', 'engine' => 'seedance25', 'refs' => ['Maya', 'Shop'], 'action' => 'she lights the wick', 'gaze' => 'on the flame', 'seconds' => 5],
            ['kind' => 'generated_shot', 'description' => 'Maya smiles at her phone', 'engine' => 'omni', 'refs' => ['Maya'], 'action' => 'she reads the post', 'gaze' => 'on her phone', 'seconds' => 5],
        ], 'selections' => ['narration' => ['Hi.'], 'choices' => [], 'panel_notes' => [2 => 'phone in her left hand']], 'decisions' => [], 'shot_context' => ['has_avatar' => false, 'aspect_ratio' => '9:16']];
        $media = PlanService::selectedMedia($plan);
        $this->assertSame(['reference_sheet', 'storyboard', 'generated_shot', 'generated_shot'], array_column($media, 'kind'), 'panels come right after the cast they are drawn from');
        $board = $media[1];
        $this->assertSame([['Maya', 'Shop'], ['Maya']], array_column($board['panels'], 'refs'), 'each panel is drawn from the cast its shot names');
        $this->assertSame('phone in her left hand', $board['panels'][1]['note']);
        $this->assertSame(2 * ShotRoute::PANEL_CREDITS, $board['credits']);
        $this->assertSame(['Panel 1', 'Panel 2'], array_column(array_slice($media, 2), 'first_frame'));
        $this->assertSame([], $media[2]['refs'], 'Seedance starts from the panel alone, which carries the cast');
        $this->assertSame([], $media[3]['refs'], 'Omni also starts from the panel alone (it refuses a start frame with references)');
        $this->assertArrayNotHasKey('problem', $media[2]);
    }

    public function test_a_panel_is_redrawn_only_when_its_direction_its_note_its_screen_or_its_own_cast_changes(): void
    {
        $panel = ['description' => 'Maya lights a candle', 'action' => 'she lights the wick', 'refs' => ['Maya']];
        $cast = [['sha256' => 'a', 'subject' => 'Maya'], ['sha256' => 'b', 'subject' => 'Shop owner']];
        $hash = fn ($p, $c) => \App\Services\Create\Storyboard::panelHash($p, $c, 'anime', '9:16');
        $h = $hash($panel, $cast);
        $this->assertSame($h, $hash($panel + ['label' => 'Panel 1', 'beat' => 'hook'], $cast), 'labels do not change what is drawn');
        $this->assertNotSame($h, $hash($panel + ['note' => 'smile'], $cast));
        $this->assertNotSame($h, $hash($panel + ['screen' => true], $cast), 'review P2: a panel that now needs a blank screen is redrawn');
        $this->assertNotSame($h, $hash($panel, [['sha256' => 'c', 'subject' => 'Maya'], ['sha256' => 'b', 'subject' => 'Shop owner']]), 'its own cast member changed');
        $this->assertSame($h, $hash($panel, [['sha256' => 'a', 'subject' => 'Maya'], ['sha256' => 'z', 'subject' => 'Shop owner']]), 'review P2: someone it does not show changed: kept');
        $candle = ['description' => 'A candle on a shelf', 'refs' => ['Candle']];
        $this->assertSame($hash($candle, [['sha256' => 'k', 'subject' => 'Candle'], ['sha256' => 'a', 'subject' => 'Maya']]), $hash($candle, [['sha256' => 'k', 'subject' => 'Candle'], ['sha256' => 'q', 'subject' => 'Maya']]));
        $this->assertNotSame($hash(['refs' => ['sheet']] + $panel, $cast), $hash(['refs' => ['sheet']] + $panel, [['sha256' => 'a', 'subject' => 'Maya'], ['sha256' => 'z', 'subject' => 'Shop owner']]), 'a whole-sheet panel follows the whole cast');
    }

    public function test_a_clip_depends_only_on_the_approved_images_it_uses(): void
    {
        $files = [['sha256' => 'maya'], ['sha256' => 'shop'], ['sha256' => 'p1'], ['sha256' => 'p2']];
        $names = ['Maya', 'Shop', 'Panel 1', 'Panel 2'];
        $shot1 = ['refs' => ['Maya'], 'first_frame' => 'Panel 1'];
        $before = ShotRoute::inputsSha($shot1, $files, $names);
        $files[3]['sha256'] = 'p2-redrawn';
        $this->assertSame($before, ShotRoute::inputsSha($shot1, $files, $names), 'redrawing panel 2 does not touch shot 1');
        $files[0]['sha256'] = 'maya-new';
        $this->assertNotSame($before, ShotRoute::inputsSha($shot1, $files, $names), 'a new Maya does');
        $this->assertNotSame(ShotRoute::inputsSha(['refs' => ['sheet']], $files, $names), ShotRoute::inputsSha(['refs' => ['Shop']], $files, $names));
    }

    public function test_a_refused_shot_is_offered_on_the_next_engine_with_its_price(): void
    {
        $item = ['engine' => 'seedance25', 'refs' => ['Maya'], 'first_frame' => 'Panel 1', 'seconds' => 5, 'aspect' => '9:16'];
        $s = ShotRoute::fallback($item, ['has_sheet' => true, 'subjects' => ['Maya'], 'panels' => ['Panel 1'], 'aspect_ratio' => '9:16']);
        $this->assertSame(['omni', 'Gemini Omni', 5 * ShotRoute::perSecond('omni')], [$s['engine'], $s['label'], $s['credits']]);
        $plan = ['media' => [['kind' => 'generated_shot', 'description' => 'x', 'engine' => 'seedance25', 'action' => 'a', 'seconds' => 5]],
            'selections' => ['narration' => [], 'choices' => [], 'engine_overrides' => ['1' => 'omni']], 'decisions' => [], 'shot_context' => ['aspect_ratio' => '9:16']];
        $this->assertSame('omni', PlanService::selectedMedia($plan)[0]['engine'], 'the user\'s choice replaces the refused engine');
    }

    public function test_an_approved_image_is_found_by_its_label_not_its_file_name(): void
    {
        $find = new \ReflectionMethod(\App\Services\Create\PlanMediaExecutor::class, 'sheetFile');
        $ctx = ['sheet_files' => [['label' => 'creator', 'name' => 'asset-1657-abc.png'], ['label' => 'Panel 2', 'name' => 'asset-1661-def.png']]];
        $this->assertSame('asset-1661-def.png', $find->invoke(app(\App\Services\Create\PlanMediaExecutor::class), 'Panel 2', $ctx)['name']);
        $this->expectExceptionMessage('not in the approved sheet');
        $find->invoke(app(\App\Services\Create\PlanMediaExecutor::class), 'asset-1661-def.png', $ctx);
    }

    public function test_the_video_type_comes_from_what_the_plan_makes(): void
    {
        $t = fn (array $kinds, array $callouts = ['x'], array $files = []) => PlanService::videoType(['media' => array_map(fn ($k) => ['kind' => $k], $kinds), 'callouts' => $callouts], ['files' => $files]);
        $this->assertSame('motion_graphics', $t(['voiceover', 'music']));
        $this->assertSame('ugc_motion', $t(['ugc_take']));
        $this->assertSame('ugc', $t(['ugc_take'], []));
        $this->assertSame('footage_motion', $t(['reference_sheet', 'generated_shot']));
        $this->assertSame('footage', $t([], [], [['purpose' => 'source', 'asset_type' => 'video']]));
        // A face kit is a talking face drawn in code, not a presenter on camera.
        $this->assertSame('motion_graphics', $t(['voiceover'], [], [['face_kit' => ['mouths' => []]]]));
    }

    public function test_a_take_speaks_the_approved_script_as_last_edited_never_cut(): void
    {
        $ctx = ['has_avatar' => true, 'aspect_ratio' => '9:16', 'language' => 'en'];
        // The user changed "ninety" to "nine" after the plan was made: the take says "nine".
        $take = ShotRoute::take(['kind' => 'ugc_take', 'presenter' => 'avatar', 'lines' => ['Start with the ninety dollar pass today.']], $ctx + ['narration' => ['Meet WyvStudio.', 'Start with the nine dollar pass today.']]);
        $said = implode(' ', array_merge(...array_column($take['segments'], 'lines')));
        $this->assertStringContainsString('nine dollar', $said);
        $this->assertStringNotContainsString('ninety', $said);
        $this->assertStringNotContainsString('Meet WyvStudio', $said, 'only the lines this take was given');
        // A take line merging two approved lines is never cut short: every approved word is spoken.
        $long = ['Paste a link to any product page and WyvStudio reads it, writes the script, picks a voice and builds the scenes for you.', 'Then it renders a captioned, ready-to-post video you can schedule straight to TikTok, YouTube or Instagram.'];
        $take = ShotRoute::take(['kind' => 'ugc_take', 'presenter' => 'avatar', 'lines' => [implode(' ', $long)]], $ctx + ['narration' => $long]);
        $this->assertSame(implode(' ', $long), implode(' ', array_merge(...array_column($take['segments'], 'lines'))), 'split into segments, every word kept');
        $this->assertStringContainsString('YouTube or Instagram.', implode(' ', array_merge(...array_column($take['segments'], 'lines'))));
    }

    public function test_a_long_planner_line_is_split_at_sentences_not_cut(): void
    {
        $line = str_repeat('This sentence is part of a long script. ', 8).'The final words must survive.';
        $parts = \App\Services\Create\PlanService::splitLine($line, 160);
        $this->assertTrue(max(array_map('mb_strlen', $parts)) <= 160);
        $this->assertSame(preg_replace('/\s+/', ' ', $line), implode(' ', $parts));
    }
}
