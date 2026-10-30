<?php

namespace Tests\Unit;

use App\Jobs\DrawStoryboardJob;
use App\Jobs\EditSceneImageJob;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use Tests\TestCase;

/** The editor prints these next to its buttons, so each must be the number the server charges. */
class CreditCostsRouteTest extends TestCase
{
    private function costs(): array
    {
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/credit-costs');
        $this->assertNotNull($route);

        return $route->getAction('uses')()->getData(true)['data']['costs'];
    }

    public function test_prices_match_what_is_charged(): void
    {
        $costs = $this->costs();

        $this->assertSame(CreditService::TTS_GEMINI, $costs['tts']);
        $this->assertSame(CreditService::TTS_CLONE, $costs['tts_clone']);
        $this->assertSame(CreditService::TTS, $costs['tts_standard']);
        $this->assertSame(CreditService::AI_CHARACTER, $costs['ai_character']);
        $this->assertSame(app(ImageAdapterFactory::class)->referenceGenerationCost(EditSceneImageJob::EDIT_MODEL), $costs['image_edit']);
    }

    public function test_every_animation_quality_is_priced_as_charged(): void
    {
        $tiers = $this->costs()['video_tiers'];

        foreach (CreditService::VIDEO_PRICING as $tier => $cfg) {
            foreach (array_keys($cfg['options']) as $quality) {
                $this->assertSame(CreditService::animationCost($tier, $quality, 5), $tiers[$tier]['qualities'][$quality], "{$tier} {$quality}");
            }
        }
        // Doubling starts at ten seconds: Veo's 8 second clip is the short price.
        $this->assertSame(CreditService::animationCost('veo_fast', '720p', 5), CreditService::animationCost('veo_fast', '720p', 8));
    }

    public function test_storyboard_is_priced_as_the_job_charges(): void
    {
        $storyboard = $this->costs()['storyboard'];

        foreach (DrawStoryboardJob::MODELS as $model) {
            $this->assertSame(DrawStoryboardJob::cost($model, true), $storyboard[$model]['reference'], "{$model} with a reference");
            $this->assertSame(DrawStoryboardJob::cost($model, false), $storyboard[$model]['no_reference'], "{$model} from words");
        }
        // Nano Banana, the default: three frames at its picture price.
        $nano = app(ImageAdapterFactory::class)->referenceGenerationCost('nano-banana');
        $this->assertSame(3 * $nano, $storyboard['nano-banana']['reference']);
    }
}
