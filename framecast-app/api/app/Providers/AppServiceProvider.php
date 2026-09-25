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
use App\Listeners\RecordSentMail;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Router sends creative templates to the premium brain (Claude via
        // Replicate) and everything else — including all vision calls — to
        // OpenAI. Premium failures fall back to cheap automatically.
        $this->app->bind(AIGenerationAdapter::class, \App\Services\Generation\AI\RoutingTextAdapter::class);
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

        // Every outgoing email is recorded. Bound to the framework event
        // rather than to each call site, so a mailable added later is covered
        // without anyone remembering to cover it.
        Event::listen(MessageSent::class, RecordSentMail::class);
        \Illuminate\Support\Facades\Bus::pipeThrough([\App\Services\Developer\AccountedJob::class]);
        Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) {
            \App\Services\Developer\AccountedJob::before($event->job);
            \Illuminate\Support\Facades\Context::forgetHidden('wyv_api_job');
            if ($id = ($event->job->payload()['wyv_api_operation'] ?? null)) {
                \Illuminate\Support\Facades\Context::addHidden('wyv_api_job', $event->job->uuid());
            }
        });


        // Context is restored by Laravel for workers; the explicit payload ID also
        // lets terminal queue events settle the hold after all descendants finish.
        \Illuminate\Queue\Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            $id = \App\Services\Developer\OperationAccounting::current();
            if (! $id) {
                return [];
            }
            \App\Services\Developer\OperationAccounting::queued($id, $payload['uuid']);

            return ['wyv_api_operation' => $id];
        });
        Event::listen(\Illuminate\Queue\Events\JobProcessed::class, function ($event) {
            if (\App\Services\Developer\AccountedJob::discarded($event->job)) return;
            $id = $event->job->payload()['wyv_api_operation'] ?? null;
            if ($id && \App\Services\Developer\OperationAccounting::enabled() && $event->job->isReleased()) {
                \Illuminate\Support\Facades\DB::table('api_operation_jobs')->where('id', $event->job->uuid())->where('status', 'running')
                    ->update(['status' => 'released', 'updated_at' => now()]);
            }
            if ($id && \App\Services\Developer\OperationAccounting::enabled() && ! $event->job->isReleased()) {
                \App\Services\Developer\OperationAccounting::close($id, $event->job->uuid());
            }
        });
        Event::listen(\Illuminate\Queue\Events\JobFailed::class, function ($event) {
            $id = $event->job->payload()['wyv_api_operation'] ?? null;
            if ($id && \App\Services\Developer\OperationAccounting::enabled()) {
                \App\Services\Developer\OperationAccounting::close($id, $event->job->uuid(), true);
            }
        });
    }
}
