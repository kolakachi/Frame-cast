<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Signed media-URL lifetime (minutes)
    |--------------------------------------------------------------------------
    | TTL for the signed `media.assets.content` URLs embedded in editor payloads
    | (scene visuals, thumbnails, the asset library). These sit in <img>/<video>
    | src attributes on a long-lived editor page, so a short TTL means an idle
    | tab starts 403-ing its own media once the signature expires. Sized to a
    | full editing session; env-tunable without a deploy.
    |
    | Public share / approval links set their own (longer) expiry and are not
    | affected by this value.
    */
    'signed_url_ttl_minutes' => (int) env('MEDIA_SIGNED_URL_TTL_MINUTES', 720),
];
