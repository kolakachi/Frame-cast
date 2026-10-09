<?php

namespace App\Services\Generation;

/**
 * The provider answered, and nothing was made: it rejected the request before starting (a 4xx), or reported the job
 * failed or cancelled. The outcome is known, unlike a timeout or a lost connection, so the user is not charged and no
 * credits are held for review (2026-10-10). A RuntimeException, so callers that catch those are unchanged.
 */
class ProviderFailed extends \RuntimeException
{
    /** A non-2xx answer to a create request: a 4xx means the provider refused it before anything ran. */
    public static function rejectedAtStart(int $status): bool
    {
        return $status >= 400 && $status < 500;
    }
}
