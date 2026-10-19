<?php

namespace Tests\Feature;

use App\Services\Create\{PartSwap, SpokenFit, TemplateCutout, VoiceSwap};
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SWAP-SAFE (owner, 2026-10-19): a template copy with the customer's own words never falls into a priced build. Today's
 * outreach copies are the cases: Kailua's address line, Beelite's web address and "100% pure beeswax", MOOSH's cord.
 */
class SwapSafeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.anthropic.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_spoken_length_reads_numbers_symbols_and_addresses_as_they_are_said(): void
    {
        $this->assertGreaterThan(SpokenFit::syllables('Pure beeswax'), SpokenFit::syllables('100% pure beeswax'), '"one hundred percent" is long to say');
        $this->assertGreaterThan(SpokenFit::syllables('Shop Beelite'), SpokenFit::syllables('beelitecandles.com'), 'a web address is said with its "dot com"');
        $this->assertGreaterThanOrEqual(8, SpokenFit::syllables('54 Maluniu Ave'), '"Ave" is said "avenue"');
        $this->assertSame(2, SpokenFit::syllables('RM'), 'letters without vowels are said one by one');
    }

    public function test_a_line_that_would_not_fit_is_said_shorter_and_the_others_stay(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"lines": {"1": "Shop Beelite."}}']]])]);
        $old = ['Meet the Fieldwell bottle.', 'Shop Fieldwell.'];
        $new = ['Meet our hand-dipped tapers.', 'Shop at beelitecandles dot com today.'];
        $out = SpokenFit::fit($old, $new);
        $this->assertSame('Meet our hand-dipped tapers.', $out[0], 'a line that fits is voiced as written');
        $this->assertSame('Shop Beelite.', $out[1], 'the long line is voiced shorter');
        Http::assertSentCount(1);
    }

    public function test_lines_that_fit_need_no_model_call(): void
    {
        Http::fake();
        $this->assertSame(['Pure beeswax.', 'Shop Beelite.'], SpokenFit::fit(['Cold for twenty-four hours.', 'Shop Fieldwell.'], ['Pure beeswax.', 'Shop Beelite.']));
        Http::assertNothingSent();
    }

    public function test_a_shortening_that_is_still_too_long_is_not_used(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"lines": {"0": "Come and visit our lovely little honey shop on Maluniu Avenue in Kailua."}}']]])]);
        $this->assertSame(['Find us at 54 Maluniu Avenue in Kailua, Oahu.'], SpokenFit::fit(['Open seven to ten.'], ['Find us at 54 Maluniu Avenue in Kailua, Oahu.']));
    }

    public function test_what_the_worker_measured_shortens_a_line_on_the_next_try(): void
    {
        $measured = SpokenFit::measuredFrom(['The new voice is too long for its moment: line 4 takes 2.93 s to say against a 2.09 s slot. Send it again…',
            'This change needs the full editor: line 2 is 3.05 s against a 2.47 s slot.']);
        $this->assertEqualsWithDelta(2.93 / 2.09, $measured[3], 0.001);
        $this->assertEqualsWithDelta(3.05 / 2.47, $measured[1], 0.001, 'the older message wording is read too');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"lines": {"0": "Say aloha."}}']]])]);
        // The line looked as short as the old one, but the worker heard it 1.4x too long: it is shortened anyway.
        $this->assertSame(['Say aloha.'], SpokenFit::fit(['Come and say aloha today.'], ['Come and say aloha today.'], [0 => 1.4]));
    }

    public function test_after_a_failed_swap_only_a_request_to_rebuild_uses_the_builder(): void
    {
        foreach (['rebuild it', 'Please do a full build with the new voice', 'remake the whole video'] as $m) $this->assertTrue(VoiceSwap::asksRebuild($m), $m);
        foreach (['send it again', 'try again', 'make the line shorter'] as $m) $this->assertFalse(VoiceSwap::asksRebuild($m), $m);
    }

    public function test_the_same_version_again_is_a_free_rerender(): void
    {
        $this->assertSame(['values' => [], 'pictures' => [], 'voice' => false, 'rerender' => true], PartSwap::normalize(['rerender' => true], ['current_variables' => []], []));
        $this->assertNull(PartSwap::normalize(['rerender' => true], ['current_variables' => []], ['timing']), 'a re-render that changes something is not a re-render');
    }

    public function test_a_thin_product_is_told_apart_from_a_solid_one_once_cut_out(): void
    {
        $dir = sys_get_temp_dir();
        // A cord across an empty canvas, and a jar filling most of its box.
        $thin = $dir.'/thin-'.uniqid().'.png'; $solid = $dir.'/solid-'.uniqid().'.png';
        // Built with overlay: drawbox writes no alpha (ffmpeg 5.1).
        exec('ffmpeg -v error -y -f lavfi -i color=c=black@0.0:s=400x400,format=rgba -f lavfi -i color=c=white:s=400x6,format=rgba -f lavfi -i color=c=white:s=6x400,format=rgba'
            .' -filter_complex "[0][1]overlay=0:195[a];[a][2]overlay=195:0,format=rgba" -frames:v 1 '.escapeshellarg($thin));
        exec('ffmpeg -v error -y -f lavfi -i color=c=black@0.0:s=400x400,format=rgba -f lavfi -i color=c=white:s=200x280,format=rgba'
            .' -filter_complex "[0][1]overlay=100:60,format=rgba" -frames:v 1 '.escapeshellarg($solid));
        $this->assertTrue(TemplateCutout::thin((string) file_get_contents($thin)), 'a cord bracelet nearly disappears in a product slot');
        $this->assertFalse(TemplateCutout::thin((string) file_get_contents($solid)), 'a candle jar does not');
        @unlink($thin); @unlink($solid);
    }

    public function test_field_room_twice_for_one_word_and_any_currency_for_a_price(): void
    {
        $this->assertSame(24, PartSwap::maxChars('fieldwell.co'));
        $this->assertGreaterThanOrEqual(mb_strlen('beelitecandles.com'), PartSwap::maxChars('fieldwell.co'));
        $this->assertGreaterThanOrEqual(mb_strlen('Rs 2,700'), PartSwap::maxChars('$45'));
        $this->assertSame(14, PartSwap::maxChars('VOLT RUSH'), 'words that wrap: half as long again');
    }

    public function test_the_real_length_goes_with_the_version(): void
    {
        $checks = \App\Services\Create\RunService::deliveryChecks(['ok' => true, 'seconds' => 16.33]);
        $this->assertSame(16.3, $checks['seconds'], 'LENGTH-TARGET: a presenter video that ran past its chosen 15 s shows 16 s');
        $this->assertArrayNotHasKey('seconds', \App\Services\Create\RunService::deliveryChecks(['ok' => true]));
    }
}
