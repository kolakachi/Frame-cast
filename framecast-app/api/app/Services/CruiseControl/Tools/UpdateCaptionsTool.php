<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\CaptionPreset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use RuntimeException;

/**
 * Update captions on a scene — toggle on/off, switch preset, change
 * position / highlight color. Free, instant.
 *
 * Storage matches the editor: scene.caption_settings_json with keys
 * enabled, style_key, position, highlight_color, font.
 */
class UpdateCaptionsTool implements CruiseTool
{
    private const POSITIONS = ['top', 'middle', 'bottom_third', 'bottom'];

    public function name(): string { return 'update_captions'; }

    public function description(): string
    {
        return 'Change scene captions: toggle on/off, switch to a style preset (e.g. "impact", "bold", "minimal"), change position (top / middle / bottom_third / bottom), or change the highlight color. Pass only the params you want to change.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'enabled' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Turn captions on or off.',
            ],
            'style_key' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Caption preset key (must match an existing preset in workspace). Common: impact, bold, minimal, classic.',
            ],
            'position' => [
                'type' => 'string',
                'required' => false,
                'enum' => self::POSITIONS,
            ],
            'highlight_color' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Hex color for the highlight, e.g. #ff6b35.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'captions'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $lines = ["Scene: {$scene?->scene_order}"];
        if (array_key_exists('enabled', $params)) {
            $lines[] = "Captions: " . ($params['enabled'] ? 'ON' : 'OFF');
        }
        if (! empty($params['style_key']))      $lines[] = "Style: {$params['style_key']}";
        if (! empty($params['position']))       $lines[] = "Position: {$params['position']}";
        if (! empty($params['highlight_color'])) $lines[] = "Highlight: {$params['highlight_color']}";
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }

        // Validate style_key against the workspace's preset library when set.
        if (! empty($params['style_key'])) {
            $exists = CaptionPreset::query()
                ->where(function ($q) use ($workspace) {
                    $q->whereNull('workspace_id')
                      ->orWhere('workspace_id', $workspace->getKey());
                })
                ->where('style_key', $params['style_key'])
                ->exists();
            if (! $exists) {
                throw new RuntimeException("Caption preset \"{$params['style_key']}\" not found. Pick from the editor's preset list.");
            }
        }
        if (! empty($params['position']) && ! in_array($params['position'], self::POSITIONS, true)) {
            throw new RuntimeException('Unknown caption position.');
        }
        if (! empty($params['highlight_color']) && ! preg_match('/^#[0-9a-fA-F]{6}$/', $params['highlight_color'])) {
            throw new RuntimeException('Highlight color must be a 6-digit hex.');
        }

        $current = $scene->caption_settings_json ?? [];
        $next = $current;
        if (array_key_exists('enabled', $params))   $next['enabled']         = (bool) $params['enabled'];
        if (! empty($params['style_key']))          $next['style_key']       = $params['style_key'];
        if (! empty($params['position']))           $next['position']        = $params['position'];
        if (! empty($params['highlight_color']))    $next['highlight_color'] = $params['highlight_color'];

        $scene->forceFill(['caption_settings_json' => $next, 'status' => 'edited'])->save();

        return [
            'summary'       => "Updated Scene {$scene->scene_order} captions",
            'credits_spent' => 0,
        ];
    }
}
