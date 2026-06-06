<?php

namespace App\Services\CruiseControl;

use App\Services\CruiseControl\Tools\AddSceneTool;
use App\Services\CruiseControl\Tools\AddSoundEffectTool;
use App\Services\CruiseControl\Tools\AnimateSceneTool;
use App\Services\CruiseControl\Tools\ApplyBrandKitTool;
use App\Services\CruiseControl\Tools\ChangeMusicTool;
use App\Services\CruiseControl\Tools\CruiseTool;
use App\Services\CruiseControl\Tools\FindStockImageTool;
use App\Services\CruiseControl\Tools\FindStockVideoTool;
use App\Services\CruiseControl\Tools\PickLibraryMusicTool;
use App\Services\CruiseControl\Tools\RegenerateImageTool;
use App\Services\CruiseControl\Tools\RerecordVoiceTool;
use App\Services\CruiseControl\Tools\SetAudiogramVisualTool;
use App\Services\CruiseControl\Tools\SwapVisualFromLibraryTool;
use App\Services\CruiseControl\Tools\UpdateCaptionsTool;
use App\Services\CruiseControl\Tools\UpdateSceneScriptTool;

/**
 * Canonical list of Cruise Control tools. Whitelist — anything not in here
 * is a hard reject from the LLM resolver, regardless of what the model
 * tries to call. Add new tools by appending to TOOL_CLASSES.
 *
 * Phase 1B ships 3 tools (voice / library swap / music regen). The other
 * 3 from the plan (regenerate_image, animate_scene, add_scene) layer in
 * after this iteration lands. See spec/CRUISE_CONTROL_PLAN.md §3.
 */
class CruiseToolRegistry
{
    // Ordered for the LLM's prompt — generation tools first, then mutations,
    // then library / search tools at the end. The "describe new content vs
    // name existing asset" routing is the LLM's most common confusion;
    // putting the search/library tools last nudges it toward generation
    // when the user is describing something new.
    private const TOOL_CLASSES = [
        // Generation (creates new content)
        RegenerateImageTool::class,
        AnimateSceneTool::class,
        AddSceneTool::class,
        ChangeMusicTool::class,
        // Property mutation
        UpdateSceneScriptTool::class,
        RerecordVoiceTool::class,
        SetAudiogramVisualTool::class,
        UpdateCaptionsTool::class,
        ApplyBrandKitTool::class,
        // Library / search (pick from existing)
        FindStockVideoTool::class,
        FindStockImageTool::class,
        PickLibraryMusicTool::class,
        SwapVisualFromLibraryTool::class,
        AddSoundEffectTool::class,
    ];

    /** @var array<string, CruiseTool> */
    private array $byName = [];

    public function __construct()
    {
        foreach (self::TOOL_CLASSES as $cls) {
            $tool = app($cls);
            $this->byName[$tool->name()] = $tool;
        }
    }

    /** @return CruiseTool[] */
    public function all(): array
    {
        return array_values($this->byName);
    }

    public function get(string $name): ?CruiseTool
    {
        return $this->byName[$name] ?? null;
    }

    public function exists(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * Renders the tool catalog for the LLM system prompt. Keep terse —
     * gpt-4o-mini handles short, clear schemas better than verbose ones.
     */
    public function promptCatalog(): string
    {
        $blocks = [];
        foreach ($this->all() as $tool) {
            $params = [];
            foreach ($tool->paramsSchema() as $name => $spec) {
                $req = ($spec['required'] ?? false) ? ' (required)' : '';
                $enum = isset($spec['enum']) ? ' [one of: ' . implode(', ', $spec['enum']) . ']' : '';
                $params[] = "    {$name}: {$spec['type']}{$req}{$enum}";
            }
            $blocks[] = "TOOL: {$tool->name()}\n  desc: {$tool->description()}\n  params:\n" . implode("\n", $params);
        }
        return implode("\n\n", $blocks);
    }
}
