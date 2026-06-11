<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateAIImageJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Lock the look" — give a project a consistent recurring subject WITHOUT the
 * user creating a character, by reusing an image the project already
 * generated as the reference face. See spec/AUTO_SUBJECT.md.
 *
 * Hard constraint: the reference always comes from a generation in THIS
 * project — never an uploaded photo, never another project's asset.
 *
 * Mechanism: pick an anchor scene whose visual is an AI-generated image, make
 * it the reference on an auto-character (is_auto), bind the other AI-image
 * scenes to it, and regenerate them through the existing character path
 * (gpt-image-2 /edits via GenerateAIImageJob's useCharacterRef route).
 */
class LockSubjectTool implements CruiseTool
{
    public function name(): string { return 'lock_subject'; }

    public function description(): string
    {
        return 'Make the SAME person/subject appear consistently across the video when the user has NOT set a character. Use ONLY when the user asks for consistency of a recurring person ("keep the character consistent", "make them the same person", "the people look different — make them match"). It picks an image THIS PROJECT already generated as the reference face and regenerates the other AI-image scenes to match it (gpt-image-2, 50 credits per scene). Do NOT use this for b-roll / product / abstract videos with no recurring person, and never volunteer it unprompted. Optional reference_scene_id chooses which generated scene image is the anchor; omit it to use the first generated image in the project.';
    }

    public function paramsSchema(): array
    {
        return [
            'reference_scene_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Scene whose already-generated image becomes the subject anchor (the face everything else matches). Must be a scene in this project that has an AI-generated image. Omit to use the first generated image in the project.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'always_prompt'; }
    public function affectedSection(): string { return 'visual'; }

    public function diffLines(Project $project, array $params): array
    {
        [$anchor, $targetIds] = $this->resolve($project, $params);
        $count = count($targetIds);
        $lines = ['Consistency: lock a recurring subject'];
        if ($anchor) {
            $lines[] = "Reference: Scene {$anchor->scene_order} (a generated image from this project)";
        }
        $lines[] = $count > 0
            ? "Match {$count} other scene" . ($count === 1 ? '' : 's') . ' to it'
            : 'No other generated scenes to match yet';
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int
    {
        [, $targetIds] = $this->resolve($project, $params);
        return count($targetIds) * CreditService::AI_CHARACTER;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        [$anchor, $targetIds] = $this->resolve($project, $params);

        if (! $anchor) {
            throw new RuntimeException('No AI-generated image found in this project yet. Generate a scene image first, then I can lock the subject.');
        }
        if (empty($targetIds)) {
            throw new RuntimeException('There\'s only one generated image so far — nothing else to match yet. Generate a couple more scenes, then lock the subject.');
        }

        $anchorAssetId = (int) $anchor->visual_asset_id;

        // Character board: vision-describe the anchor's appearance (outfit,
        // hair, accessories) and persist it BEFORE dispatching the regens —
        // GenerateAIImageJob appends the sheet to every prompt, so the
        // matched scenes keep the costume, not just the face. Best-effort.
        rescue(function () use ($project, $anchorAssetId): void {
            $asset = Asset::query()->find($anchorAssetId);
            if (! $asset || ! $asset->storage_url) {
                return;
            }
            $storage = app(\App\Services\Media\StorageService::class);
            $url = $storage->extractPath((string) $asset->storage_url) !== null
                ? $storage->url((string) $asset->storage_url)
                : (string) $asset->storage_url;
            $sheet = app(\App\Services\Generation\CharacterBoardService::class)->describeFromImage($url);
            if ($sheet) {
                app(\App\Services\Generation\CharacterBoardService::class)->set($project, $sheet, 'vision');
                $project->refresh();
            }
        });

        // Reuse this project's auto-subject if it already has one; otherwise
        // create a fresh one. Never touch a user's named (non-auto) character.
        $autoChar = null;
        if ($project->default_character_id) {
            $existing = Character::query()
                ->whereKey($project->default_character_id)
                ->where('workspace_id', $workspace->getKey())
                ->first();
            if ($existing && $existing->is_auto) {
                $autoChar = $existing;
            }
        }

        if ($autoChar) {
            $autoChar->forceFill([
                'reference_asset_id' => $anchorAssetId,
                'status'             => 'active',
            ])->save();
        } else {
            $autoChar = Character::query()->create([
                'workspace_id'       => $workspace->getKey(),
                'name'               => 'Subject — ' . mb_substr((string) ($project->title ?: 'Untitled'), 0, 40),
                'description'         => 'Auto-locked recurring subject, generated from this project.',
                'reference_asset_id' => $anchorAssetId,
                'consistency_method' => 'reference_image',
                'identity_strength'  => 'balanced',
                'status'             => 'active',
                'is_auto'            => true,
                'created_by_user_id' => $project->created_by_user_id,
            ]);
            $project->forceFill(['default_character_id' => $autoChar->getKey()])->save();
        }

        // Bind each target scene to the subject and regenerate it through the
        // character path. afterCommit so jobs fire once this tx lands.
        foreach ($targetIds as $sceneId) {
            $scene = Scene::query()
                ->where('project_id', $project->getKey())
                ->whereKey($sceneId)
                ->first();
            if (! $scene) {
                continue;
            }
            $style = $scene->visual_style ?? $project->ai_broll_style ?? 'photorealistic';
            $token = (string) Str::uuid();
            $scene->forceFill([
                'character_id'                   => $autoChar->getKey(),
                'visual_style'                   => $style,
                'image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], [
                    'in_progress'           => true,
                    'last_error'            => null,
                    'needs_visual'          => false,
                    'generation_token'      => $token,
                    'generation_started_at' => now()->toIso8601String(),
                ]),
            ])->save();

            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $project->getKey(),
                $style,
                null,           // promptOverride — reuse the scene's existing prompt
                $style,
                $token,
                null, null, null, // no chained animation
                null,             // modelKey — character path forces gpt-image-2 regardless
                [],               // referenceAssetIds — scene.character_id auto-routes
            )->afterCommit();
        }

        $count = count($targetIds);
        return [
            'summary'           => "Locking the subject from Scene {$anchor->scene_order} and matching {$count} scene" . ($count === 1 ? '' : 's'),
            'credits_spent'     => $count * CreditService::AI_CHARACTER,
            'affected_scene_id' => (int) $anchor->getKey(),
        ];
    }

    /**
     * Resolve the anchor scene + the target scene ids to regenerate. Pure
     * reads — safe for diffLines/estimateCost. Everything is scoped to THIS
     * project, so the reference can never be another project's asset.
     *
     * @return array{0: ?Scene, 1: int[]}
     */
    private function resolve(Project $project, array $params): array
    {
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        // Map scene -> whether its visual is a project-generated image.
        $assetIds = $scenes->pluck('visual_asset_id')->filter()->unique()->all();
        $genImageIds = empty($assetIds) ? [] : Asset::query()
            ->whereIn('id', $assetIds)
            ->where('asset_type', 'image')
            ->whereJsonContains('tags', 'ai_generated')
            ->pluck('id')
            ->all();
        $genImageIds = array_flip($genImageIds);

        $generatedScenes = $scenes->filter(
            fn (Scene $s) => $s->visual_asset_id && isset($genImageIds[$s->visual_asset_id])
        )->values();

        // Anchor: explicit reference_scene_id (validated to be a generated
        // image in this project), else the first generated image.
        $anchor = null;
        $refId = $params['reference_scene_id'] ?? null;
        if ($refId) {
            $anchor = $generatedScenes->firstWhere('id', (int) $refId);
        }
        if (! $anchor) {
            $anchor = $generatedScenes->first();
        }
        if (! $anchor) {
            return [null, []];
        }

        // Targets: every other generated-image scene that doesn't already
        // carry a character binding (don't override the user's explicit ones).
        $targetIds = $generatedScenes
            ->filter(fn (Scene $s) => $s->getKey() !== $anchor->getKey() && empty($s->character_id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [$anchor, $targetIds];
    }
}
