<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\Editor\EditHistory;

/**
 * Undo through the project's edit history (EditHistory, 2026-10-10): the same step as the editor's Undo button,
 * whoever made the edit. Free: no credits move and generated files are kept. Not recorded as an edit itself
 * (RecordsEditHistory skips it), so it never clears what can be redone.
 */
class UndoLastEditTool implements CruiseTool
{
    public function name(): string { return 'undo_last_edit'; }

    public function description(): string
    {
        return 'Undo the latest edit to the video (made by the user or by you) when the user asks to undo, go back or reverse a change. Free; it can be redone.';
    }

    public function paramsSchema(): array { return []; }
    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'scene'; }

    public function diffLines(Project $project, array $params): array
    {
        $label = EditHistory::status((int) $project->getKey())['undo_label'] ?? null;
        return [$label ? 'Undo: '.$label : 'Nothing to undo', 'Free · nothing is regenerated'];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        try {
            $label = EditHistory::undo((int) $project->getKey());
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage());
        }
        return ['summary' => 'Undone: '.$label, 'credits_spent' => 0];
    }
}
