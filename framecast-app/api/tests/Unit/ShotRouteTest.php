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
    private array $ctx = ['has_avatar' => true, 'has_sheet' => true, 'aspect_ratio' => '9:16', 'video_tier' => 'standard'];

    public function test_a_shot_with_the_users_avatar_never_goes_to_seedance(): void
    {
        foreach (['seedance25', 'seedance_lite', null] as $engine) {
            $r = ShotRoute::shot(['engine' => $engine, 'refs' => ['avatar', 'sheet'], 'seconds' => 6], $this->ctx);
            $this->assertSame('omni', $r['engine'], "asked for {$engine}");
            $this->assertSame(6 * ShotRoute::perSecond('omni'), $r['credits']);
        }
        $first = ShotRoute::shot(['engine' => 'seedance_lite', 'first_frame' => 'avatar', 'seconds' => 5], $this->ctx);
        $this->assertSame('kling', $first['engine'], 'a first frame of the user goes to Kling, not Seedance');
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
}
