<?php

namespace App\Traits;

use App\Models\JobFailureTrace;

trait TracksJobFailure
{
    /**
     * Record a structured failure trace to the job_failure_traces table.
     *
     * Call this at the top of every job's failed() handler so that all
     * failures are queryable from the god-mode admin in one place.
     *
     * @param  string|null  $entityType  e.g. 'export', 'project', 'scene', 'variant', 'asset'
     * @param  int|null     $entityId    Primary key of the entity being processed
     * @param  int|null     $workspaceId Workspace context if known at failure time
     * @param  int|null     $projectId   Project context if known at failure time
     */
    protected function recordFailureTrace(
        \Throwable $exception,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $workspaceId = null,
        ?int $projectId = null,
    ): void {
        rescue(static function () use ($exception, $entityType, $entityId, $workspaceId, $projectId): void {
            JobFailureTrace::create([
                'job_class'         => static::class,
                'entity_type'       => $entityType,
                'entity_id'         => $entityId,
                'workspace_id'      => $workspaceId,
                'project_id'        => $projectId,
                'exception_class'   => get_class($exception),
                'exception_message' => mb_substr($exception->getMessage(), 0, 2000),
                'exception_trace'   => mb_substr($exception->getTraceAsString(), 0, 10000),
            ]);
        }, false);
    }
}
