<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use RuntimeException;

/**
 * Update captions on a scene (or every scene) — toggle on/off, pick an
 * animated caption effect, change base style / position / colors.
 * Free, instant.
 *
 * Storage matches the editor: scene.caption_settings_json with keys
 * enabled, style_key, position, font, color, highlight_color,
 * animation, highlight_style, panel_color, backdrop.
 */
class UpdateCaptionsTool implements CruiseTool
{
    private const POSITIONS = ['top_third', 'center', 'bottom_third'];
    private const BASE_STYLES = ['impact', 'editorial', 'hacker'];
    private const HIGHLIGHT_STYLES = ['color', 'underline', 'plain'];

    /**
     * Animated caption effects — mirror of web/src/composables/captionPresets.js.
     * Value = the effect's default font, written into settings on selection so
     * the export uses the intended typeface (user can change it afterwards).
     */
    private const ANIMATIONS = [
        'plain' => null,
        'beast' => 'Montserrat',
        'comic' => 'Luckiest Guy',
        'sticker' => 'Passion One',
        'karaoke' => 'Montserrat',
        'box' => 'Nunito',
        'stream' => 'Roboto Mono',
        'blur' => 'Days One',
        'glitch' => 'Bebas Neue',
        'slide' => 'Montserrat',
        'wave' => 'Fredoka One',
        'punch' => 'Montserrat',
        'tracking' => 'Bebas Neue',
        'neon' => 'Orbitron',
        'news' => 'Nunito',
        'marker' => 'Permanent Marker',
    ];

    private const PANEL_ANIMATIONS = ['stream', 'news'];

    public function name(): string { return 'update_captions'; }

    public function description(): string
    {
        return 'Change captions on one scene or all scenes: toggle on/off, pick an animated caption effect '
            .'(plain = no animation; beast = one giant popping word, MrBeast style; karaoke = line with words '
            .'lighting up as spoken; punch = heavy italic ad style; box = pill behind the spoken word; '
            .'stream = typewriter console; news = lower-third news bar; also comic, sticker, blur, glitch, '
            .'slide, wave, tracking, neon, marker), change position, text/highlight colors, highlight style, '
            .'or the backdrop panel. Words animate on the real voiceover timing. Pass only the params you want to change.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Scene to update. Omit and set all_scenes=true to update every scene.',
            ],
            'all_scenes' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Apply the change to every scene in the project.',
            ],
            'enabled' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Turn captions on or off.',
            ],
            'animation' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(self::ANIMATIONS),
                'description' => 'Animated caption effect. "plain" removes the animation.',
            ],
            'style_key' => [
                'type' => 'string',
                'required' => false,
                'enum' => self::BASE_STYLES,
                'description' => 'Base typography style (only affects the plain effect).',
            ],
            'position' => [
                'type' => 'string',
                'required' => false,
                'enum' => self::POSITIONS,
            ],
            'color' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Hex text color, e.g. #ffffff.',
            ],
            'highlight_color' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Hex color for the spoken-word highlight, e.g. #ff6b35.',
            ],
            'highlight_style' => [
                'type' => 'string',
                'required' => false,
                'enum' => self::HIGHLIGHT_STYLES,
                'description' => 'How the spoken word is marked: color, color + underline, or motion only.',
            ],
            'backdrop' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Panel behind the text. false makes Stream/News Bar transparent; true adds a panel to other effects.',
            ],
            'panel_color' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Panel/backdrop color (hex or rgba), used by Stream, News Bar and the backdrop.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'captions'; }

    public function diffLines(Project $project, array $params): array
    {
        $lines = [];
        if (! empty($params['all_scenes'])) {
            $lines[] = 'Scope: all scenes';
        } else {
            $scene = Scene::query()->find($params['scene_id'] ?? null);
            $lines[] = "Scene: {$scene?->scene_order}";
        }
        if (array_key_exists('enabled', $params)) {
            $lines[] = 'Captions: '.($params['enabled'] ? 'ON' : 'OFF');
        }
        if (! empty($params['animation']))       $lines[] = "Effect: {$params['animation']}";
        if (! empty($params['style_key']))       $lines[] = "Base style: {$params['style_key']}";
        if (! empty($params['position']))        $lines[] = "Position: {$params['position']}";
        if (! empty($params['color']))           $lines[] = "Text color: {$params['color']}";
        if (! empty($params['highlight_color'])) $lines[] = "Highlight: {$params['highlight_color']}";
        if (! empty($params['highlight_style'])) $lines[] = "Highlight style: {$params['highlight_style']}";
        if (array_key_exists('backdrop', $params)) $lines[] = 'Backdrop: '.($params['backdrop'] ? 'on' : 'off');
        if (! empty($params['panel_color']))     $lines[] = "Panel color: {$params['panel_color']}";
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scenes = collect();
        if (! empty($params['all_scenes'])) {
            $scenes = Scene::query()->where('project_id', $project->getKey())->orderBy('scene_order')->get();
        } else {
            $scene = Scene::query()
                ->where('project_id', $project->getKey())
                ->whereKey($params['scene_id'] ?? null)
                ->first();
            if ($scene) $scenes = collect([$scene]);
        }
        if ($scenes->isEmpty()) {
            throw new RuntimeException('Scene not found in this project (pass scene_id, or all_scenes=true).');
        }

        if (! empty($params['animation']) && ! array_key_exists($params['animation'], self::ANIMATIONS)) {
            throw new RuntimeException('Unknown caption effect "'.$params['animation'].'". Valid: '.implode(', ', array_keys(self::ANIMATIONS)).'.');
        }
        if (! empty($params['style_key']) && ! in_array($params['style_key'], self::BASE_STYLES, true)) {
            throw new RuntimeException('Base style must be one of: '.implode(', ', self::BASE_STYLES).'.');
        }
        if (! empty($params['position']) && ! in_array($params['position'], self::POSITIONS, true)) {
            throw new RuntimeException('Unknown caption position.');
        }
        if (! empty($params['highlight_style']) && ! in_array($params['highlight_style'], self::HIGHLIGHT_STYLES, true)) {
            throw new RuntimeException('Unknown highlight style.');
        }
        foreach (['color', 'highlight_color'] as $colorKey) {
            if (! empty($params[$colorKey]) && ! preg_match('/^#[0-9a-fA-F]{6}$/', $params[$colorKey])) {
                throw new RuntimeException(ucfirst(str_replace('_', ' ', $colorKey)).' must be a 6-digit hex.');
            }
        }

        foreach ($scenes as $scene) {
            $next = is_array($scene->caption_settings_json) ? $scene->caption_settings_json : [];
            if (array_key_exists('enabled', $params))   $next['enabled']         = (bool) $params['enabled'];
            if (! empty($params['style_key']))          $next['style_key']       = $params['style_key'];
            if (! empty($params['position']))           $next['position']        = $params['position'];
            if (! empty($params['color']))              $next['color']           = $params['color'];
            if (! empty($params['highlight_color']))    $next['highlight_color'] = $params['highlight_color'];
            if (! empty($params['highlight_style']))    $next['highlight_style'] = $params['highlight_style'];
            if (array_key_exists('backdrop', $params))  $next['backdrop']        = (bool) $params['backdrop'];
            if (! empty($params['panel_color']))        $next['panel_color']     = $params['panel_color'];
            if (! empty($params['animation'])) {
                $next['animation'] = $params['animation'];
                // Same behavior as the editor: the effect's default font lands
                // in settings; panel effects switch their panel on.
                $defaultFont = self::ANIMATIONS[$params['animation']];
                if ($defaultFont !== null) $next['font'] = $defaultFont;
                if (in_array($params['animation'], self::PANEL_ANIMATIONS, true)) $next['backdrop'] = $params['backdrop'] ?? true;
            }

            $scene->forceFill(['caption_settings_json' => $next, 'status' => 'edited'])->save();
        }

        $scope = $scenes->count() > 1
            ? "all {$scenes->count()} scenes"
            : "Scene {$scenes->first()->scene_order}";

        return [
            'summary'       => "Updated captions on {$scope}",
            'credits_spent' => 0,
        ];
    }
}
