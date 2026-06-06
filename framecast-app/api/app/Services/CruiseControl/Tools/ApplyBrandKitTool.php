<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\BrandKit;
use App\Models\Project;
use App\Models\Workspace;
use RuntimeException;

/**
 * Attach a saved brand kit to the project. Drives caption colors, font,
 * and any other brand-aware rendering. Free, instant.
 *
 * The system prompt lists the workspace's kits so the LLM can match
 * user phrasing ("use my Acme kit", "apply the agency brand") to a
 * brand_kit_id without asking.
 */
class ApplyBrandKitTool implements CruiseTool
{
    public function name(): string { return 'apply_brand_kit'; }

    public function description(): string
    {
        return 'Apply a saved brand kit to the current project. The brand kit drives caption colors, fonts, and overlay treatment. Pick brand_kit_id from the BRAND KITS list in the system context.';
    }

    public function paramsSchema(): array
    {
        return [
            'brand_kit_id' => ['type' => 'integer', 'required' => true],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'brand'; }

    public function diffLines(Project $project, array $params): array
    {
        $kit = BrandKit::query()->find($params['brand_kit_id'] ?? null);
        return [
            "Brand kit: " . ($kit?->name ?? '?'),
            "Project: {$project->title}",
        ];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $kit = BrandKit::query()
            ->whereKey($params['brand_kit_id'] ?? null)
            ->where('workspace_id', $workspace->getKey())
            ->first();
        if (! $kit) {
            throw new RuntimeException('Brand kit not found in your workspace.');
        }

        $project->forceFill(['brand_kit_id' => $kit->getKey()])->save();

        return [
            'summary'       => "Applied brand kit \"{$kit->name}\"",
            'credits_spent' => 0,
        ];
    }
}
