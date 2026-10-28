<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\CreditService;
use App\Services\CruiseControl\Tools\AddSceneTool;
use App\Services\CruiseControl\Tools\RerecordVoiceTool;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Generation\TTS\GeminiVoices;
use Tests\TestCase;

/** The assistant quotes what the jobs charge, and a paid change waits for Apply (fix A2, 2026-10-28). */
class CruisePricingTest extends TestCase
{
    public function test_a_line_is_priced_by_the_engine_its_voice_routes_to(): void
    {
        $this->assertSame(CreditService::TTS_GEMINI, CreditService::ttsCostForVoice(GeminiVoices::DEFAULT_VOICE));
        $this->assertSame(CreditService::TTS_GEMINI, CreditService::ttsCostForVoice(null), 'no voice set: the Gemini default');
        $this->assertSame(CreditService::TTS_CLONE, CreditService::ttsCostForVoice('clone-abc123'));
        $this->assertSame(CreditService::TTS, CreditService::ttsCostForVoice('alloy', ['provider' => 'openai']));
    }

    public function test_a_new_scene_is_quoted_with_its_real_voice_and_animation_price(): void
    {
        $project = new Project(['default_voice_settings_json' => ['voice_id' => GeminiVoices::DEFAULT_VOICE]]);
        $tool = app(AddSceneTool::class);
        $image = app(ImageAdapterFactory::class)->costFor(null);
        $this->assertSame($image + CreditService::TTS_GEMINI, $tool->estimateCost($project, ['script_text' => 'x', 'visual_prompt' => 'y']));
        $this->assertSame($image + CreditService::TTS_GEMINI + CreditService::animationCost('quick', CreditService::videoQuality('quick', null), 5),
            $tool->estimateCost($project, ['script_text' => 'x', 'visual_prompt' => 'y', 'animate_tier' => 'quick']));
    }

    public function test_a_voice_re_record_waits_for_apply(): void
    {
        $this->assertSame('prompt', app(RerecordVoiceTool::class)->confirmationClass());
    }
}
