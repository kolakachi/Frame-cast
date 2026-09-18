<?php

namespace Tests\Unit;

use App\Services\Generation\TTS\RoutingTTSAdapter;
use Tests\TestCase;

/**
 * A cloned voice must reach the clone engine no matter what label rides along.
 *
 * A customer's clone was re-registered under provider "openai" by a form that
 * hardcoded it. The label won the routing, the OpenAI adapter silently swapped
 * the unknown voice for alloy, and the profile he had named "Default" spoke in
 * a stock voice until he churned. The voice being a clone is a fact about the
 * voice; when the label disagrees, the voice wins.
 */
class TtsRoutingTest extends TestCase
{
    public function test_a_clone_key_reaches_chatterbox_despite_an_openai_label(): void
    {
        $this->assertSame('chatterbox', RoutingTTSAdapter::engineFor('clone-2851', ['provider' => 'openai']));
    }

    public function test_a_clone_key_reaches_chatterbox_despite_a_google_label(): void
    {
        // The UGC path hardcodes provider "google"; a cloned voice picked there
        // used to fall through to Gemini's silent default.
        $this->assertSame('chatterbox', RoutingTTSAdapter::engineFor('clone-2851', ['provider' => 'google']));
    }

    public function test_a_clone_key_with_no_label_at_all_still_routes_to_chatterbox(): void
    {
        $this->assertSame('chatterbox', RoutingTTSAdapter::engineFor('clone-2851'));
    }

    public function test_a_reference_sample_url_routes_to_chatterbox(): void
    {
        $this->assertSame('chatterbox', RoutingTTSAdapter::engineFor('anything', ['clone_audio_url' => 'https://x/y.wav']));
    }

    public function test_the_fixed_openai_voices_still_route_to_openai(): void
    {
        $this->assertSame('openai', RoutingTTSAdapter::engineFor('alloy'));
        $this->assertSame('openai', RoutingTTSAdapter::engineFor('onyx', ['provider' => 'openai']));
    }

    public function test_gemini_stays_the_default_for_everything_else(): void
    {
        $this->assertSame('gemini', RoutingTTSAdapter::engineFor('Kore'));
        $this->assertSame('gemini', RoutingTTSAdapter::engineFor('Kore', ['provider' => 'google']));
    }
}
