<?php

namespace App\Providers;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\AI\OpenAIGenerationAdapter;
use App\Services\Generation\Image\DalleImageAdapter;
use App\Services\Generation\Image\ImageGenerationAdapter;
use App\Services\Generation\TTS\OpenAITTSAdapter;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Translation\OpenAITranslationAdapter;
use App\Services\Generation\Translation\TranslationAdapter;
use App\Services\Generation\Visual\PexelsVisualProviderAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AIGenerationAdapter::class, OpenAIGenerationAdapter::class);
        $this->app->bind(VisualProviderAdapter::class, PexelsVisualProviderAdapter::class);
        $this->app->bind(TTSAdapter::class, OpenAITTSAdapter::class);
        $this->app->bind(TranslationAdapter::class, OpenAITranslationAdapter::class);
        // Default image generation provider — swap to ReplicateImageAdapter for cost tier
        $this->app->bind(ImageGenerationAdapter::class, DalleImageAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
