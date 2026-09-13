<?php

namespace Tests\Unit;

use App\Services\CreditService;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Media\FontMetrics;
use App\Services\Ugc\UgcHeadline;
use App\Services\Ugc\UgcPlan;
use App\Services\Ugc\UgcShotPlanner;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UgcPlanTest extends TestCase
{
    private function shot(array $changes = []): array
    {
        return array_replace(['kind' => 'on_camera', 'script_text' => 'A useful idea.', 'seconds' => 5,
            'visual_brief' => 'Casual kitchen selfie, soft light, medium close-up.', 'headline' => '',
            'motion_prompt' => '', 'voice_direction' => 'Warm, conversational.', 'source' => null], $changes);
    }

    public function test_director_keeps_camera_direction_and_screen_first_demo(): void
    {
        $shots = UgcPlan::normalise([
            $this->shot(['kind' => 'b_roll', 'source' => 'upload']), $this->shot(),
        ], 'demo');
        $this->assertSame('b_roll', $shots[0]['kind']);
        $this->assertSame($this->shot()['visual_brief'], $shots[1]['visual_brief']);
        $this->assertNull($shots[0]['asset_id']);
        $this->assertStringContainsString('needs selected upload footage', implode(' ', UgcPlan::warnings($shots)));
    }

    public function test_silent_reaction_prices_image_and_motion_but_not_voice_or_lipsync(): void
    {
        $shots = UgcPlan::normalise([$this->shot(['kind' => 'reaction', 'script_text' => '',
            'headline' => 'POV: you almost gave up', 'motion_prompt' => 'Look concerned, then smile.'])], 'reaction');
        $expected = app(ImageAdapterFactory::class)->referenceGenerationCost(null)
            + CreditService::animationCost('quick', CreditService::videoQuality('quick', null), 5);
        $this->assertSame($expected, UgcPlan::quote($shots));
        $this->assertSame('', UgcPlan::script($shots));
    }

    public function test_stock_and_upload_do_not_quote_image_generation(): void
    {
        foreach (['stock', 'upload'] as $source) {
            $shots = UgcPlan::normalise([$this->shot(['kind' => 'b_roll', 'source' => $source])], 'demo');
            $this->assertSame(CreditService::TTS_GEMINI, UgcPlan::quote($shots));
        }
    }

    public function test_direct_talking_take_is_not_split_or_merged_silently(): void
    {
        $this->expectException(ValidationException::class);
        UgcPlan::normalise([$this->shot(), $this->shot()], 'direct_camera');
    }

    public function test_spoken_reaction_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        UgcPlan::normalise([$this->shot(['kind' => 'reaction', 'headline' => 'Hello', 'motion_prompt' => 'Smile'])], 'reaction');
    }

    public function test_unsupported_motion_duration_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        UgcPlan::normalise([$this->shot(['kind' => 'reaction', 'seconds' => 6, 'script_text' => '',
            'headline' => 'Hello', 'motion_prompt' => 'Smile'])], 'reaction');
    }

    public function test_long_read_cannot_be_underquoted_as_one_second(): void
    {
        $this->expectException(ValidationException::class);
        UgcPlan::normalise([$this->shot(['script_text' => str_repeat('word ', 160), 'seconds' => 1])], 'direct_camera');
    }

    public function test_script_comparison_detects_omissions_duplicates_and_reordering(): void
    {
        $this->assertTrue(UgcPlan::sameScript("One\n two.", 'One two.'));
        $this->assertFalse(UgcPlan::sameScript('One two.', 'One.'));
        $this->assertFalse(UgcPlan::sameScript('One two.', 'One two. two.'));
        $this->assertFalse(UgcPlan::sameScript('One two.', 'two. One'));
    }

    public function test_ai_failure_does_not_silently_fallback_to_a_different_product(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->method('generate')->willReturn(['content' => '{broken']);
        $this->expectException(ValidationException::class);
        (new UgcShotPlanner($ai))->plan('', context: 'A silent reaction', format: 'reaction');
    }

    public function test_ai_cannot_change_supplied_speech(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->method('generate')->willReturn(['content' => json_encode(['format' => 'direct_camera', 'segments' => [$this->shot()]])]);
        $this->expectException(ValidationException::class);
        (new UgcShotPlanner($ai))->plan('Different words.', format: 'direct_camera');
    }

    public function test_ai_receives_brief_and_returns_a_reviewable_reaction(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->once())->method('generate')->with('ugc_shot_plan', $this->callback(fn ($v) => $v['format'] === 'reaction' && $v['context'] === 'Concern then relief' && $v['product'] === 'My app'), 3500, 0.3)
            ->willReturn(['content' => json_encode(['format' => 'reaction', 'reasoning' => 'One expressive beat.',
                'segments' => [$this->shot(['kind' => 'reaction', 'script_text' => '', 'headline' => 'Almost gave up', 'motion_prompt' => 'Smile.'])]])]);
        $plan = (new UgcShotPlanner($ai))->plan('', 'My app', 'Concern then relief', format: 'reaction');
        $this->assertSame('reaction', $plan['format']);
        $this->assertCount(1, $plan['segments']);
        $this->assertSame('', $plan['script']);
    }

    public function test_headline_has_fixed_safe_lines_and_cannot_inject_ass(): void
    {
        $text = 'POV: '.str_repeat('great ', 20).' {\\pos(0,0)}';
        $layout = UgcHeadline::layout($text);
        foreach ($layout['lines'] as $line) {
            $this->assertLessThanOrEqual(30, mb_strlen($line));
        }
        $ass = UgcHeadline::ass($text, 1080, 1920, 5);
        $this->assertStringContainsString('Style: Headline,', $ass);
        $this->assertStringContainsString('\\an2\\pos(540,', $ass);
        $this->assertStringNotContainsString('\\pos(0,0)', $ass);
        $this->assertSame(count($layout['lines']), substr_count($ass, 'Dialogue:'));
        $this->assertStringContainsString('0:00:05.00', $ass);
    }

    public function test_headline_converts_css_font_size_and_preserves_the_preview_baseline(): void
    {
        $metrics = $this->createMock(FontMetrics::class);
        $metrics->method('assFontSize')->willReturnCallback(fn ($font, $size) => $size * 1.25);
        $metrics->method('verticalMetrics')->willReturnCallback(fn ($font, $size) => ['descent' => $size * 0.2]);
        $this->app->instance(FontMetrics::class, $metrics);
        $ass = UgcHeadline::ass('Hello', 1080, 1920, 5);
        $this->assertStringContainsString('Style: Headline,Arial,55.35,', $ass);
        $this->assertStringContainsString('\\pos(540,245.136)', $ass);
    }
}
