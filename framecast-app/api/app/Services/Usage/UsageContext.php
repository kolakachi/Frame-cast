<?php

namespace App\Services\Usage;

use Illuminate\Support\Facades\Context;

/**
 * Whose spend a paid call is: the workspace (and project, where known) of the request or job that made it. Set once
 * per request at sign-in and per job where it knows its project; Laravel carries it into every job a request starts.
 * The usage recorder and ApiUsageService fall back to it, so a call site that forgets to say still lands on the right
 * workspace (2026-10-11: the UGC chat's AI calls were nobody's cost).
 */
class UsageContext
{
    public const WORKSPACE = 'wyv_usage_workspace';
    public const PROJECT = 'wyv_usage_project';

    public static function set(?int $workspaceId, ?int $projectId = null): void
    {
        if ($workspaceId) Context::addHidden(self::WORKSPACE, $workspaceId);
        if ($projectId) Context::addHidden(self::PROJECT, $projectId);
        elseif ($workspaceId) Context::forgetHidden(self::PROJECT);
    }

    /** @return array{workspace_id?: int, project_id?: int, user_id?: int} */
    public static function current(): array
    {
        return array_filter([
            'workspace_id' => Context::getHidden(self::WORKSPACE),
            'project_id' => Context::getHidden(self::PROJECT),
            'user_id' => Context::getHidden(\App\Services\Agency\Allowance::CONTEXT),
        ], fn ($v) => $v !== null);
    }
}
