<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\Export\ProjectExportService;

/**
 * Render the final video. Wraps the same export pipeline as the Export button
 * (ProjectExportService), so the assistant's export runs identical readiness
 * checks, export-limit enforcement and watermark policy. Always prompts —
 * exporting consumes one of the plan's monthly exports.
 */
class ExportVideoTool implements CruiseTool
{
    public function name(): string { return 'export_video'; }

    public function description(): string
    {
        return 'Render/export the final video when the user asks to export, render, download or "make the final video". Optionally takes an aspect ratio (9:16, 1:1, 16:9); defaults to the project\'s. Needs every scene to have script, visual and voice first. Counts against the plan\'s monthly export limit.';
    }

    public function paramsSchema(): array
    {
        return [
            'aspect_ratio' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['9:16', '1:1', '16:9'],
                'description' => 'Output aspect ratio. Omit to use the project default.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'always_prompt'; }
    public function affectedSection(): string { return 'project'; }

    public function diffLines(Project $project, array $params): array
    {
        $ratio = $params['aspect_ratio'] ?? $project->aspect_ratio ?? '9:16';

        return [
            'Render the final video',
            "Aspect ratio: {$ratio}",
            'Uses one of your monthly exports',
        ];
    }

    public function estimateCost(Project $project, array $params): int
    {
        return 0; // export draws on the plan's monthly export quota, not credits
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $opts = [];
        if (! empty($params['aspect_ratio'])) {
            $opts['aspect_ratio'] = (string) $params['aspect_ratio'];
        }

        // Throws (caught by the controller) with a user-facing reason if the
        // project isn't export-ready or the workspace is over its limit.
        $exportJob = app(ProjectExportService::class)->queue($project, $opts);

        return [
            'summary'       => "Exporting your video ({$exportJob->aspect_ratio}) — I'll let you know when it's ready.",
            'credits_spent' => 0,
            // The editor opens/scrolls to export progress on this directive.
            'navigate'      => ['type' => 'export', 'export_job_id' => (int) $exportJob->getKey()],
        ];
    }
}
