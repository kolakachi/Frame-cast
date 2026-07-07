<?php

namespace App\Providers;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\AI\OpenAIGenerationAdapter;
use App\Services\Generation\Image\DalleImageAdapter;
use App\Services\Generation\Image\ImageGenerationAdapter;
use App\Services\Generation\TTS\OpenAITTSAdapter;
use App\Services\Generation\TTS\RoutingTTSAdapter;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Translation\OpenAITranslationAdapter;
use App\Services\Generation\Translation\TranslationAdapter;
use App\Services\Generation\Visual\PexelsVisualProviderAdapter;
use App\Services\Generation\Visual\PixabayVisualProviderAdapter;
use App\Services\Generation\Visual\RoundRobinVisualProviderAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AIGenerationAdapter::class, OpenAIGenerationAdapter::class);
        // Round-robin stock across every provider that has a key configured
        // (Pexels primary; Pixabay joins when PIXABAY_API_KEY is set) for more
        // B-roll variety. Degrades to Pexels-only when no other key is present.
        $this->app->bind(VisualProviderAdapter::class, function ($app): VisualProviderAdapter {
            $providers = [$app->make(PexelsVisualProviderAdapter::class)];
            if ((string) config('services.pixabay.api_key') !== '') {
                $providers[] = $app->make(PixabayVisualProviderAdapter::class);
            }

            return new RoundRobinVisualProviderAdapter(...$providers);
        });
        // Routes per request: Gemini 3.1 Flash (default, expressive) vs OpenAI tts-1.
        $this->app->bind(TTSAdapter::class, RoutingTTSAdapter::class);
        $this->app->bind(TranslationAdapter::class, OpenAITranslationAdapter::class);
        // Default image generation provider — swap to ReplicateImageAdapter for cost tier
        $this->app->bind(ImageGenerationAdapter::class, DalleImageAdapter::class);

        // Image-to-video (animation): single adapter routes between tier models internally.
        $this->app->bind(
            \App\Services\Generation\Video\I2VAdapter::class,
            \App\Services\Generation\Video\ReplicateI2VAdapter::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
