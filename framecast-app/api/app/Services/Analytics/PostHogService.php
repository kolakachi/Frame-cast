<?php

namespace App\Services\Analytics;

use App\Jobs\SendAnalyticsEventJob;

/**
 * Server-side product analytics (PostHog).
 *
 * The funnel's decisive moments — generation finished, export completed,
 * post published, credits refused — happen in queued jobs and controllers,
 * not the browser, and client capture also loses whatever ad-blockers eat.
 * So the funnel is recorded from the backend, keyed to the SAME distinct id
 * the SPA uses (the user id passed to posthog.identify), which joins server
 * events onto the person profiles the frontend already creates.
 *
 * capture() only queues; the HTTP call happens on a worker. Analytics must
 * never add latency to a request or fail a job — a lost event is noise, a
 * failed export because PostHog was down would be malpractice.
 */
class PostHogService
{
    public static function capture(?int $userId, string $event, array $props = [], ?int $workspaceId = null): void
    {
        if ((string) config('services.posthog.key') === '' || ! $userId) {
            return;
        }

        rescue(fn () => SendAnalyticsEventJob::dispatch(
            (string) $userId,
            $event,
            $props + array_filter(['workspace_id' => $workspaceId]),
        ), report: false);
    }
}
