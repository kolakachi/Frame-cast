<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use RuntimeException;

/**
 * Switch a scene to the audiogram (waveform) visual — bars / mirror /
 * circle / minimal, with color and background presets. Used for podcast
 * snippets where the visual is the voice itself. Zero cost.
 *
 * Storage matches the editor (EditorView line ~2703): visual_type =
 * 'waveform', visual_asset_id = null, and the audiogram_* keys live
 * inside image_generation_settings_json.
 */
class SetAudiogramVisualTool implements CruiseTool
{
    private const STYLES = ['bars', 'mirror', 'circle', 'minimal'];
    private const BGS    = ['dark', 'black', 'purple', 'ocean'];

    public function name(): string { return 'set_audiogram_visual'; }

    public function description(): string
    {
        return 'Switch a scene\'s visual to an audiogram (animated waveform on a colored background). Use for podcast clips or voice-only scenes. Styles: bars (default), mirror, circle, minimal. Backgrounds: dark, black, purple, ocean. Bar color is any hex (default #ff6b35 brand orange).';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'style' => [
                'type' => 'string',
                'required' => false,
                'enum' => self::STYLES,
                'description' => 'Defaults to bars.',
            ],
            'color' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Hex color for the bars, e.g. #ff6b35. Defaults to brand orange.',
            ],
            'background' => [
                'type' => 'string',
                'required' => false,
                'enum' => self::BGS,
                'description' => 'Defaults to dark.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'visual'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        return [
            "Visual: audiogram",
            "Style: " . ($params['style'] ?? 'bars'),
            "Color: " . ($params['color'] ?? '#ff6b35'),
            "Background: " . ($params['background'] ?? 'dark'),
            "Scene: {$scene?->scene_order}",
        ];
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
        $style = $params['style'] ?? 'bars';
        if (! in_array($style, self::STYLES, true)) {
            throw new RuntimeException('Unknown audiogram style.');
        }
        $bg = $params['background'] ?? 'dark';
        if (! in_array($bg, self::BGS, true)) {
            throw new RuntimeException('Unknown background.');
        }
        $color = $params['color'] ?? '#ff6b35';
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new RuntimeException('Color must be a 6-digit hex (e.g. #ff6b35).');
        }

        $scene->forceFill([
            'visual_type'     => 'waveform',
            'visual_asset_id' => null,
            'image_generation_settings_json' => array_merge(
                $scene->image_generation_settings_json ?? [],
                [
                    'audiogram_style' => $style,
                    'audiogram_color' => $color,
                    'audiogram_bg'    => $bg,
                ],
            ),
            'status' => 'edited',
        ])->save();

        return [
            'summary'       => "Scene {$scene->scene_order} set to audiogram ({$style})",
            'credits_spent' => 0,
        ];
    }
}
