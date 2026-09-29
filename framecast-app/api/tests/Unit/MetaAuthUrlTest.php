<?php

namespace Tests\Unit;

use App\Services\Publishing\MetaGraphHelper;
use Tests\TestCase;

class MetaAuthUrlTest extends TestCase
{
    public function test_classic_dialog_lists_scopes_including_public_profile(): void
    {
        config(['services.meta.app_id' => '123', 'services.meta.graph_version' => 'v26.0']);
        $url = MetaGraphHelper::authUrl('https://app.test/cb', ['public_profile', 'pages_show_list'], 's1');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('public_profile,pages_show_list', $q['scope']);
        $this->assertSame('code', $q['response_type']);
        $this->assertArrayNotHasKey('config_id', $q);
    }

    public function test_login_for_business_uses_the_configuration_and_keeps_the_code_flow(): void
    {
        config(['services.meta.app_id' => '123', 'services.meta.graph_version' => 'v26.0']);
        $url = MetaGraphHelper::authUrl('https://app.test/cb', ['pages_show_list'], 's1', '9876');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('9876', $q['config_id']);
        $this->assertSame('true', $q['override_default_response_type']);
        $this->assertSame('code', $q['response_type']);
        $this->assertArrayNotHasKey('scope', $q);
        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $url);
    }
}
