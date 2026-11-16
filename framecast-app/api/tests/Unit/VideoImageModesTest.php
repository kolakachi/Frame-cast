<?php

namespace Tests\Unit;

use App\Services\Generation\Video\ReplicateI2VAdapter;
use Tests\TestCase;

/** AI video's image modes (owner, 2026-11-16): none (words alone), start, start + end, reference. */
class VideoImageModesTest extends TestCase
{
    private function req(string $tier, string $image, array $options = []): array
    {
        return app(ReplicateI2VAdapter::class)->buildRequestForTier($tier, $image, 'a slow push-in', 5, $options + ['aspect_ratio' => '9:16'])[2];
    }

    public function test_a_clip_from_words_alone_sends_no_image_and_says_its_shape(): void
    {
        $veo = $this->req('veo_fast', '');
        $this->assertArrayNotHasKey('image', $veo);
        $this->assertSame('9:16', $veo['aspect_ratio']);

        $seedance = $this->req('seedance_25', '', ['aspect_ratio' => '16:9']);
        $this->assertArrayNotHasKey('image', $seedance);
        $this->assertSame('16:9', $seedance['aspect_ratio'], 'not "adaptive": there is no picture to follow');
    }

    public function test_reference_images_go_to_the_models_that_follow_them(): void
    {
        $lite = $this->req('seedance_lite', '', ['reference_image_urls' => ['https://x/a.png', 'https://x/b.png'], 'resolution' => '1080p']);
        $this->assertSame(['https://x/a.png', 'https://x/b.png'], $lite['reference_images']);
        $this->assertArrayNotHasKey('image', $lite);
        $this->assertSame('720p', $lite['resolution'], 'Seedance 1 Lite takes references only below 1080p');

        $this->assertTrue(ReplicateI2VAdapter::takesReferences('seedance_25'));
        $this->assertFalse(ReplicateI2VAdapter::takesReferences('veo_fast'));
        $this->assertFalse(ReplicateI2VAdapter::takesNoImage('premium'), 'Kling needs a start frame');
        $this->assertFalse(ReplicateI2VAdapter::takesNoImage('quick'), 'Wan needs a start frame');
    }

    public function test_a_start_and_end_frame_still_work_as_before(): void
    {
        $veo = $this->req('veo_fast', 'https://x/start.png', ['end_image_url' => 'https://x/end.png']);
        $this->assertSame('https://x/start.png', $veo['image']);
        $this->assertSame('https://x/end.png', $veo['last_frame']);
    }
}
