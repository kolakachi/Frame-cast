<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Developer API limits
    |--------------------------------------------------------------------------
    | Request limits are per minute. "reads" are GET/HEAD (capabilities,
    | status, result) so an integration can poll freely; "writes" are POST
    | (quotes, create). Both are counted twice: once against the key or user
    | making the call and once against the whole workspace, so several keys
    | cannot multiply a workspace's allowance.
    |
    | max_active_videos caps how many API-created projects may be generating
    | at once in a workspace. Rate limits bound request volume; this bounds
    | how much can be in flight, which is what actually bounds spend rate.
    */
    'limits' => [
        'reads_per_minute' => (int) env('DEVELOPER_API_READS_PER_MINUTE', 60),
        'writes_per_minute' => (int) env('DEVELOPER_API_WRITES_PER_MINUTE', 10),
        'workspace_reads_per_minute' => (int) env('DEVELOPER_API_WORKSPACE_READS_PER_MINUTE', 120),
        'workspace_writes_per_minute' => (int) env('DEVELOPER_API_WORKSPACE_WRITES_PER_MINUTE', 20),
        'max_active_videos' => (int) env('DEVELOPER_API_MAX_ACTIVE_VIDEOS', 3),
    ],
];
