<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('workspace.{workspaceId}', function ($user, $workspaceId) {
    return (int) ($user->workspace_id ?? 0) === (int) $workspaceId;
});

Broadcast::channel('project.{projectId}', function ($user) {
    return ! is_null($user);
});

Broadcast::channel('export_job.{exportJobId}', function ($user) {
    return ! is_null($user);
});
