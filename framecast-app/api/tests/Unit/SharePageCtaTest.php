<?php

namespace Tests\Unit;

use App\Http\Controllers\Web\SharePageController;
use PHPUnit\Framework\TestCase;

class SharePageCtaTest extends TestCase
{
    public function test_a_campaign_visitor_keeps_their_tags_and_others_count_as_the_share_page(): void
    {
        $this->assertSame('https://wyvstudio.com/?utm_source=sendfox&utm_medium=email&utm_campaign=one-imageurl-multiple-viral-videos',
            SharePageController::ctaUrl(['utm_source' => 'sendfox', 'utm_medium' => 'email', 'utm_campaign' => 'one-imageurl-multiple-viral-videos', 'ref' => 'x']));
        $this->assertSame('https://wyvstudio.com/?utm_source=share_page&utm_medium=video', SharePageController::ctaUrl([]));
        $this->assertSame('https://wyvstudio.com/?utm_source=share_page&utm_medium=video', SharePageController::ctaUrl(['utm_source' => ['a']]), 'only plain values');
    }
}
