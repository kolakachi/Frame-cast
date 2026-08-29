<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Delivers one PostHog event. Fire-and-forget: one attempt, short timeout,
 * failures swallowed — analytics never gets to break anything else.
 */
class SendAnalyticsEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $distinctId,
        public readonly string $event,
        public readonly array $properties = [],
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $key = (string) config('services.posthog.key');
        if ($key === '') {
            return;
        }

        rescue(fn () => Http::timeout(8)->post(
            rtrim((string) config('services.posthog.host', 'https://us.i.posthog.com'), '/').'/capture/',
            [
                'api_key'     => $key,
                'event'       => $this->event,
                'distinct_id' => $this->distinctId,
                'properties'  => $this->properties,
                'timestamp'   => now()->toIso8601String(),
            ],
        ), report: false);
    }
}
