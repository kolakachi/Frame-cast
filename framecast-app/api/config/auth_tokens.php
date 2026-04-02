<?php

return [
    'jwt_secret' => env('JWT_SECRET', ''),
    'access_ttl_minutes' => (int) env('JWT_TTL_MINUTES', 15),
    'refresh_ttl_days' => (int) env('JWT_REFRESH_TTL_DAYS', 7),
    'refresh_cookie_name' => env('JWT_REFRESH_COOKIE_NAME', 'framecast_refresh_token'),
];
