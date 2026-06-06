<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Workspace;

/**
 * Contract every Cruise Control tool implements. The LLM picks a tool by
 * name; the registry validates params against the schema and dispatches
 * to execute(). Tools own their own credit cost estimate so the action
 * card can show the user the bill before they click Apply.
 *
 * Confirmation classes drive UX:
 *   auto          — safe to run without prompt when the workspace
 *                   auto-apply toggle is on (e.g. swap voice in library)
 *   prompt        — always shows Apply button when toggle is off (default
 *                   for paid + irreversible: regen_image, animate)
 *   always_prompt — Apply required regardless of toggle (structural
 *                   changes: add_scene)
 */
interface CruiseTool
{
    /** Stable identifier the LLM emits. snake_case. */
    public function name(): string;

    /** Human-readable description for the system prompt. */
    public function description(): string;

    /**
     * JSON-schema-style parameter spec. Used both to seed the LLM system
     * prompt and to validate inbound params before execute() runs.
     * Shape: ['param' => ['type' => 'integer', 'required' => true, ...]]
     *
     * @return array<string, array{type:string, required?:bool, enum?:array, description?:string}>
     */
    public function paramsSchema(): array;

    /** auto | prompt | always_prompt */
    public function confirmationClass(): string;

    /**
     * What's about to change, rendered as bullet lines in the action card.
     * Pure presentation; never call APIs from here.
     *
     * @return string[]
     */
    public function diffLines(Project $project, array $params): array;

    /**
     * Estimated credit cost. Used by the controller for the pre-flight
     * balance check + by the action card UI.
     */
    public function estimateCost(Project $project, array $params): int;

    /**
     * Hint for which Config-view accordion section should pulse green
     * after a successful apply. Matches the section key the editor uses:
     *   'voice' | 'visual' | 'music' | 'motion' | 'project' | 'scene'
     */
    public function affectedSection(): string;

    /**
     * Run the change. Already inside a DB transaction. Should throw on
     * any failure — the controller catches + records the error in audit.
     *
     * @return array{summary:string, credits_spent:int}
     */
    public function execute(Workspace $workspace, Project $project, array $params): array;
}
