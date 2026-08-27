<?php

namespace App\Services\Publishing;

use App\Models\SocialAccount;

/**
 * Implemented by adapters whose public post URL can't be assembled from the
 * ID the publish call returns.
 *
 * Instagram is the reason this exists. /media_publish returns a numeric media
 * ID, but an Instagram permalink is keyed on a shortcode
 * (instagram.com/reel/DchgvRtDXBJ/). The shortcode cannot be derived from the
 * media ID, so pasting the ID into the URL — which is what we used to do —
 * produces a link that always 404s. The permalink has to be read back from
 * the Graph API.
 */
interface ProvidesPostUrl
{
    /**
     * Public URL for a post we just published, or null if it can't be resolved.
     * Must never throw — a missing link is cosmetic, a failed publish is not.
     */
    public function postUrl(SocialAccount $account, string $postId): ?string;
}
