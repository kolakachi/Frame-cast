<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\Export\ProjectExportService;

/**
 * Open the scheduling composer for this project's video. The assistant only
 * PREPARES the post — it never publishes on its own. The user still picks the
 * social account, the date, and "post now / schedule later / save as draft"
 * inside the modal; that selection is the consent. (See SchedulePostModal +
 * ScheduledPostController, which also enforce the plan's publishing gate.)
 *
 * Pre-selects the latest completed export when one exists; otherwise the
 * composer shows the export picker / "export first" state.
 */
class SchedulePostTool implements CruiseTool
{
    public function name(): string { return 'schedule_post'; }

    public function description(): string
    {
        return 'Open the scheduling composer to post or schedule this video to social media (TikTok, Instagram, YouTube, Facebook) when the user asks to schedule, post, publish or "add it to the calendar". The user picks the account, the date and whether to post now, schedule later, or save a draft — you only open the composer with the latest export pre-loaded. Suggest exporting first if nothing is rendered yet.';
    }

    public function paramsSchema(): array
    {
        return [];
    }

    public function confirmationClass(): string { return 'always_prompt'; }
    public function affectedSection(): string { return 'project'; }

    public function diffLines(Project $project, array $params): array
    {
        $export = app(ProjectExportService::class)->latestCompletedExport($project);

        return [
            'Open the scheduler for this video',
            $export ? "Latest export: {$export->file_name}" : 'No completed export yet — you can render one in the composer',
            'You choose the account, the date, and post now / schedule / draft',
        ];
    }

    public function estimateCost(Project $project, array $params): int
    {
        return 0;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $export = app(ProjectExportService::class)->latestCompletedExport($project);

        return [
            'summary'       => 'Opening the scheduler — pick the account and when to post.',
            'credits_spent' => 0,
            // The editor opens SchedulePostModal on this directive, pre-loading
            // the latest export when there is one.
            'navigate'      => [
                'type'          => 'schedule',
                'export_job_id' => $export ? (int) $export->getKey() : null,
            ],
        ];
    }
}
