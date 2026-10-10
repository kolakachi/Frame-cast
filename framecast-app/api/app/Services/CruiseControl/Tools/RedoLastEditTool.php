<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Workspace;
use App\Services\Editor\EditHistory;

/**
 * Redo through the project's edit history (EditHistory, 2026-10-10): the same step as the editor's Redo button,
 * whoever made the edit. Free: no credits move and generated files are kept. Not recorded as an edit itself
 * (RecordsEditHistory skips it), so it never clears what can be redone.
 */
class RedoLastEditTool implements CruiseTool
{
    public function name(): string { return 'redo_last_edit'; }

    public function description(): string
    {
        return 'Redo the most recently undone edit when the user asks to redo or bring a change back. Free.';
    }

    public function paramsSchema(): array { return []; }
    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'scene'; }

    public function diffLines(Project $project, array $params): array
    {
        $label = EditHistory::status((int) $project->getKey())['redo_label'] ?? null;
        return [$label ? 'Redo: '.$label : 'Nothing to redo', 'Free · nothing is regenerated'];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        try {
            $label = EditHistory::redo((int) $project->getKey());
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage());
        }
        return ['summary' => 'Redone: '.$label, 'credits_spent' => 0];
    }
}
