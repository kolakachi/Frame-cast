<?php

namespace Tests\Unit;

use App\Services\CreditService;
use App\Services\Generation\Video\ReplicateI2VAdapter;
use Tests\TestCase;

/** Each model's own lengths, priced per second, the clip's own sound, and Seedance 2.5 speaking a voiceover (2026-11-17). */
class VideoLengthsAndSoundTest extends TestCase
{
    private function req(string $tier, int $seconds, array $options = [], string $image = 'https://x/start.png'): array
    {
        return app(ReplicateI2VAdapter::class)->buildRequestForTier($tier, $image, 'a slow push-in', $seconds, $options + ['aspect_ratio' => '9:16'])[2];
    }

    public function test_each_model_renders_its_own_lengths(): void
    {
        $this->assertSame(30, $this->req('seedance_25', 30)['duration'], 'Seedance 2.5 runs to 30 s');
        $this->assertSame(17, $this->req('seedance_25', 17)['duration']);
        $this->assertSame(4, $this->req('seedance_25', 2)['duration'], 'held to its 4 s minimum');
        $this->assertSame(12, $this->req('seedance_pro', 20)['duration'], 'Seedance 1 Pro stops at 12 s');
        $this->assertSame(6, $this->req('veo_fast', 6)['duration'], 'Veo takes 6 s too');
        // Listed lengths keep the old rule for any other request, so callers asking 5 or 10 are unchanged.
        $this->assertSame(4, $this->req('veo_fast', 5)['duration']);
        $this->assertSame(8, $this->req('veo_fast', 10)['duration']);
        $this->assertSame(5, $this->req('quick', 7)['duration']);
        $this->assertSame(10, $this->req('quick', 8)['duration']);
        $this->assertSame(6, $this->req('balanced', 5)['duration'], 'Hailuo has no 5 s');
    }

    public function test_price_follows_the_length_by_the_second(): void
    {
        $this->assertSame(105, CreditService::animationCost('seedance_25', '480p', 5));
        $this->assertSame(630, CreditService::animationCost('seedance_25', '480p', 30));
        $this->assertSame(147, CreditService::animationCost('seedance_25', '480p', 7));
        $this->assertSame(120, CreditService::animationCost('veo_fast', '720p', 6));
        // Fixed-length models keep 10 s = 2x.
        $this->assertSame(100, CreditService::animationCost('quick', '480p', 10));
        $this->assertSame(70, CreditService::animationCost('balanced', '768p', 10));
        // What the model renders is what is priced: Veo asked for 10 s makes 8.
        $this->assertSame(CreditService::animationCost('veo_fast', '720p', 8), CreditService::animationCost('veo_fast', '720p', 10));
        $this->assertEqualsWithDelta(3.084, CreditService::animationCogsUsd('seedance_25', '480p', 30), 0.001);
    }

    public function test_create_keeps_its_subsidised_veo_price(): void
    {
        $this->assertSame(160, CreditService::animationCost('veo_fast', null, 8), 'the editor: per second');
        $ctx = ['has_avatar' => true, 'has_sheet' => true, 'aspect_ratio' => '9:16', 'video_tier' => 'standard', 'subjects' => ['Shop owner', 'Shop']];
        $r = \App\Services\Create\ShotRoute::shot(['engine' => 'veo_fast', 'first_frame' => 'sheet', 'seconds' => 8], $ctx);
        $this->assertSame('veo_fast', $r['engine']);
        $this->assertSame(8, $r['seconds']);
        $this->assertSame(80, $r['credits'], 'Create prices its 8 s Veo clip at the 4 s price');
    }

    public function test_sound_is_made_only_when_asked_and_priced_as_billed(): void
    {
        $this->assertFalse($this->req('veo_fast', 8)['generate_audio']);
        $this->assertTrue($this->req('veo_fast', 8, ['with_sound' => true])['generate_audio']);
        $this->assertTrue($this->req('seedance_25', 5, ['with_sound' => true])['generate_audio']);
        $this->assertArrayNotHasKey('generate_audio', $this->req('quick', 5, ['with_sound' => true]), 'Wan makes no sound');

        $this->assertSame(240, CreditService::animationCost('veo_fast', '720p', 8, true), 'Veo with sound: 1.5x');
        $this->assertSame(105, CreditService::animationCost('seedance_25', '480p', 5, true), 'Seedance 2.5 sound: no extra');
        $this->assertSame(50, CreditService::animationCost('quick', '480p', 5, true), 'no sound to buy on Wan');
    }

    public function test_seedance_25_speaks_the_voiceover_it_is_given(): void
    {
        $r = $this->req('seedance_25', 9, ['reference_image_urls' => ['https://x/face.png'], 'reference_audio_url' => 'https://x/voice.mp3'], '');
        $this->assertSame(['https://x/voice.mp3'], $r['reference_audios']);
        $this->assertSame(['https://x/face.png'], $r['reference_images']);
        $this->assertStringContainsString('[Audio1]', $r['prompt']);

        $lite = $this->req('seedance_lite', 9, ['reference_image_urls' => ['https://x/face.png'], 'reference_audio_url' => 'https://x/voice.mp3'], '');
        $this->assertArrayNotHasKey('reference_audios', $lite, 'only Seedance 2.5 takes audio');
    }
}
