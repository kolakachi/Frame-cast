<?php

namespace App\Services\Publishing;

/**
 * Implemented by adapters whose OAuth grant can cover several publishable
 * targets, so the user has to say which one they meant.
 *
 * A Facebook Login grant returns every Page the user administers. Picking the
 * first one silently is wrong for anyone managing more than one — an agency
 * connecting a client Page, or anyone with an old personal Page sorted ahead
 * of the one they actually want. Reels would publish to whichever Page Meta
 * happened to list first.
 *
 * The flow: exchangeCode() returns a selection payload instead of an account
 * when there is more than one candidate; the controller parks the user token
 * briefly and asks; the choice comes back to accountDataForPageId().
 *
 * OAuth codes are single-use, so the token has to be exchanged BEFORE the
 * question is asked — which is why the pending state exists at all.
 */
interface SupportsPageSelection
{
    /**
     * Build SocialAccount data for a specific target, using the user token
     * captured during the code exchange.
     *
     * @param  array<string, mixed>  $pending  payload cached from exchangeCode()
     * @return array<string, mixed>  same shape as exchangeCode()'s success return
     */
    public function accountDataForPageId(array $pending, string $pageId): array;
}
