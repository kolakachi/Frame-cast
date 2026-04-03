<?php

namespace App\Providers;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\AI\OpenAIGenerationAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AIGenerationAdapter::class, OpenAIGenerationAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
