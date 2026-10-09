<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{Route, URL};
use Tests\TestCase;

/** A link built for a request that came over the internal network points at the public site. */
class PublicRootUrlTest extends TestCase
{
    public function test_an_internal_host_builds_public_links_and_a_public_host_keeps_its_own(): void
    {
        config(['app.url' => 'https://app.wyvstudio.com']);
        Route::get('/_test/link', fn () => URL::temporarySignedRoute('media.assets.content', now()->addHour(), ['assetId' => 1, 'download' => 1]))->middleware(\App\Http\Middleware\PublicRootUrl::class);

        $internal = $this->get('http://nginx/_test/link')->assertOk()->getContent();
        $this->assertStringStartsWith('https://app.wyvstudio.com/media/assets/1', str_replace('http://', 'https://', $internal));
        $this->assertStringNotContainsString('nginx', $internal);

        URL::forceRootUrl(null);
        $public = $this->get('http://wyvstudio.com/_test/link')->assertOk()->getContent();
        $this->assertStringContainsString('://wyvstudio.com/media/assets/1', $public);
    }
}
