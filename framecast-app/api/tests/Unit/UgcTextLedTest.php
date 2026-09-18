<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcPlan;
use Tests\TestCase;

/**
 * A text-led ad runs no video model: stills, a camera move and captions. The
 * talking face is ~96% of a long take's cost and scales with its length, so
 * the same runtime told this way costs a fraction — which is the whole reason
 * the format exists.
 */
class UgcTextLedTest extends TestCase
{
    private function card(string $headline = 'Sleep better tonight', string $text = '', string $source = 'generate'): array
    {
        return ['kind' => 'b_roll', 'source' => $source, 'script_text' => $text, 'seconds' => 6,
                'visual_brief' => 'Phone on a nightstand, lamp light.', 'headline' => $headline];
    }

    public function test_a_card_may_carry_a_headline_and_no_narration(): void
    {
        $out = UgcPlan::normalise([$this->card()], 'text_led');

        $this->assertSame('', $out[0]['script_text']);
        $this->assertSame('Sleep better tonight', $out[0]['headline']);
    }

    public function test_narration_over_a_card_is_allowed_but_not_required(): void
    {
        $out = UgcPlan::normalise([
            $this->card('Myth', 'You do not need eight hours.'),
            $this->card('Fact'),
        ], 'text_led');

        $this->assertCount(2, $out);
    }

    public function test_it_refuses_to_put_anyone_on_camera(): void
    {
        $this->expectExceptionMessageMatches('/no presenter/i');
        UgcPlan::normalise([
            ['kind' => 'on_camera', 'script_text' => 'Hi, let me tell you about sleep.', 'seconds' => 6,
             'visual_brief' => 'Kitchen, morning light.'],
        ], 'text_led');
    }

    public function test_it_needs_at_least_one_headline(): void
    {
        $this->expectExceptionMessageMatches('/needs at least one headline/i');
        UgcPlan::normalise([$this->card('', 'Just some narration.')], 'text_led');
    }

    public function test_more_than_six_cards_is_a_story_not_a_caption(): void
    {
        $this->expectExceptionMessageMatches('/six cards/i');
        UgcPlan::normalise(array_fill(0, 7, $this->card()), 'text_led');
    }

    public function test_it_costs_a_fraction_of_the_same_ad_with_a_presenter(): void
    {
        $words = fn (int $n) => implode(' ', array_fill(0, $n, 'word'));

        $spoken = UgcPlan::normalise([
            ['kind' => 'on_camera', 'script_text' => $words(78), 'seconds' => 30, 'visual_brief' => 'b'],
        ], 'direct_camera');

        $textLed = UgcPlan::normalise([
            $this->card('Myth', $words(16)),
            $this->card('Fact', $words(16)),
            $this->card('Try it tonight', $words(16), 'stock'),
        ], 'text_led');

        $this->assertLessThan(
            UgcPlan::quote($spoken) * 0.3,
            UgcPlan::quote($textLed),
            'a text-led ad should cost well under a third of a talking take',
        );
        $this->assertSame(0.0, UgcPlan::onCameraSeconds($textLed), 'no seconds of synced face to pay for');
    }

    public function test_the_estimate_says_no_video_model_runs(): void
    {
        $out = UgcPlan::normalise([$this->card()], 'text_led');

        $this->assertStringContainsString('No video model runs', implode(' ', UgcPlan::warnings($out, 'text_led')));
    }
}
