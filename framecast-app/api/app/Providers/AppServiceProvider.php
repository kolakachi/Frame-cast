<?php

namespace App\Providers;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\AI\OpenAIGenerationAdapter;
use App\Services\Generation\TTS\OpenAITTSAdapter;
use App\Services\Generation\TTS\TTSAdapter;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
