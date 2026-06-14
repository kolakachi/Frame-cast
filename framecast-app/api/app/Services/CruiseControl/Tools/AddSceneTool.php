<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateAIImageJob;
use App\Jobs\GenerateTTSJob;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageStyleDescriptors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Insert a new scene into the project. Push subsequent scenes' order
 * down by one. Dispatch image + tts so the new scene is rendered out of
 * the gate (animate is opt-in via the chained_animate_tier param).
 *
 * Structural change → confirmation_class = 'always_prompt'. Cost is the
 * sum of image + tts + (optional) animate at the chosen tier.
 */
class AddSceneTool implements CruiseTool
{
    public function name(): string { return 'add_scene'; }

    public function description(): string
    {
        return 'Add a new scene to the project. Provide the spoken script (1–2 sentences, first/second person — what the voice ACTUALLY says, not a description), the visual prompt (what the image shows), and optionally style + voice_id. Position defaults to the end of the project; pass an explicit position to insert in the middle. Optionally chain an animation by setting animate_tier.';
    }

    public function paramsSchema(): array
    {
        return [
            'script_text' => [
                'type' => 'string',
                'required' => true,
                'description' => 'What the voice says. 1–2 sentences max, ~50–180 chars.',
            ],
            'visual_prompt' => [
                'type' => 'string',
                'required' => true,
                'description' => 'A DETAILED image-generation prompt (target 500–1000 chars, hard cap 1500). Expand the user\'s intent into a vivid scene: subject + pose, environment + setting, time of day + lighting, camera angle + framing, mood + emotion, style references, colour palette, and concrete textural details. Do NOT just echo the user\'s words — paint the picture the model will draw.',
            ],
            'position' => [
                'type' => 'integer',
                'required' => false,
                'description' => '1-based insertion position. Omit to append at the end.',
            ],
            'style' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(ImageStyleDescriptors::META),
                'description' => 'Defaults to the project\'s ai_broll_style.',
            ],
            'voice_id' => [
                'type' => 'string',
                'required' => false,
                'description' => VoiceCatalog::describe().' Defaults to the project\'s default voice.',
            ],
            'voice_prompt' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Delivery direction for an expressive (Gemini) voice — e.g. "like an excited teenager", "calm and warm". Ignored for classic/cloned voices.',
            ],
            'animate_tier' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['quick', 'seedance_lite', 'balanced', 'seedance_pro', 'premium'],
                'description' => 'If set, the image is animated after generation. Adds the tier\'s VIDEO_* cost.',
            ],
            'character_names' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Names of the project CAST members who appear in this scene (e.g. ["Sarah"] or ["Sarah","Tom"]). Use the exact names from the project cast listed in your context. Each becomes that character\'s reference + appearance so they stay consistent. Omit for scenes with no named character (b-roll, product).',
            ],
        ];
    }

    public function confirmationClass(): string { return 'always_prompt'; }
    public function affectedSection(): string { return 'scene'; }

    public function diffLines(Project $project, array $params): array
    {
        $position = $params['position'] ?? null;
        $posText = $position === null ? 'end of project' : "at position {$position}";
        $lines = [
            "Add new scene: {$posText}",
            'Script: ' . mb_substr((string) ($params['script_text'] ?? ''), 0, 60) . '…',
            'Visual: ' . mb_substr((string) ($params['visual_prompt'] ?? ''), 0, 60) . '…',
        ];
        if (! empty($params['animate_tier'])) {
            $lines[] = 'Animate: ' . $params['animate_tier'];
        }
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int
    {
        // Image (AI_MEDIUM = gpt-image-1 default) + TTS + optional animate.
        $cost = CreditService::AI_MEDIUM + CreditService::TTS;
        if (! empty($params['animate_tier'])) {
            $cost += match ($params['animate_tier']) {
                'premium'       => CreditService::VIDEO_PREMIUM,
                'balanced'      => CreditService::VIDEO_BALANCED,
                'seedance_pro'  => CreditService::VIDEO_SEEDANCE_PRO,
                'seedance_lite' => CreditService::VIDEO_SEEDANCE_LITE,
                default         => CreditService::VIDEO_QUICK,
            };
        }
        return $cost;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scriptText = trim((string) ($params['script_text'] ?? ''));
        $visualPrompt = trim((string) ($params['visual_prompt'] ?? ''));
        if ($scriptText === '' || $visualPrompt === '') {
            throw new RuntimeException('Script and visual prompt are both required.');
        }

        $style = $params['style'] ?? ($project->ai_broll_style ?? 'photorealistic');
        // Resolve the voice name (Gemini / OpenAI / cloned) → voice_id + provider.
        // Falls back to the project default, then the Gemini default voice.
        $voiceName = $params['voice_id'] ?? data_get($project->default_voice_settings_json, 'voice_id', \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE);
        $resolvedVoice = VoiceCatalog::resolve((int) $workspace->getKey(), (string) $voiceName)
            ?? ['voice_id' => \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE, 'provider' => 'google'];
        $voicePrompt = isset($params['voice_prompt'])
            ? trim((string) $params['voice_prompt'])
            : (string) data_get($project->default_voice_settings_json, 'voice_prompt', '');
        $animateTier = $params['animate_tier'] ?? null;
        // Balanced (Hailuo) needs 6 or 10; the others use 5 or 10.
        $animateDuration = $animateTier === 'balanced' ? 6 : 5;

        // Cast assignment: the assistant names which roster characters appear
        // in this scene (character_names) — resolved against the project's cast.
        $sceneCastIds = $this->resolveSceneCast($workspace, $project, (array) ($params['character_names'] ?? []));

        return DB::transaction(function () use ($project, $scriptText, $visualPrompt, $style, $resolvedVoice, $voicePrompt, $animateTier, $animateDuration, $params, $sceneCastIds): array {
            $maxOrder = (int) Scene::query()->where('project_id', $project->getKey())->max('scene_order');
            $position = (int) ($params['position'] ?? ($maxOrder + 1));
            $position = max(1, min($maxOrder + 1, $position));

            // Face continuity when the project has NO locked character: if the
            // new scene features a person, anchor it to the NEAREST already-
            // generated scene image (preferring the scene just before the
            // insert) so the person carries over instead of being reinvented.
            // Computed BEFORE the shift, while scene_order still reflects the
            // user's mental layout. Skipped for person-less b-roll.
            // Auto-anchor only when NO explicit cast was named — a named cast
            // brings its own characters (generation pulls their references +
            // appearance), and anchoring to a prior scene could drag in the
            // wrong face.
            $autoRefIds = [];
            if (empty($sceneCastIds) && ! $project->default_character_id && $this->mentionsPerson($scriptText.' '.$visualPrompt)) {
                $anchorAssetId = $this->nearestGeneratedImageAsset($project, $position);
                if ($anchorAssetId) {
                    $autoRefIds = [$anchorAssetId];
                }
            }

            // Shift existing scenes at or after the position down by one.
            // The (project_id, scene_order) unique constraint is DEFERRABLE
            // INITIALLY DEFERRED, so this bulk +1 is checked at COMMIT — the
            // transient duplicate mid-shift is fine. (Postgres ignores ORDER BY
            // on UPDATE, so ordering the bump can't help; deferral is the fix.)
            if ($position <= $maxOrder) {
                Scene::query()
                    ->where('project_id', $project->getKey())
                    ->where('scene_order', '>=', $position)
                    ->update(['scene_order' => DB::raw('scene_order + 1')]);

                // Update labels that still reference the OLD scene_order
                // as "Scene N". Without this, inserting at position 5
                // when a "Scene 5" labelled row already existed left two
                // rows both labelled "Scene 5" — confusing the editor.
                // Only touch labels that match the default "Scene N"
                // pattern; custom labels stay as the user set them.
                $bumped = Scene::query()
                    ->where('project_id', $project->getKey())
                    ->where('scene_order', '>', $position)   // already shifted to N+1
                    ->orderBy('scene_order')
                    ->get(['id', 'scene_order', 'label']);
                foreach ($bumped as $b) {
                    $expectedOld = 'Scene ' . ($b->scene_order - 1);
                    if (trim((string) $b->label) === $expectedOld) {
                        Scene::query()->whereKey($b->id)->update(['label' => 'Scene ' . $b->scene_order]);
                    }
                }
            }

            // Cast the scene: explicit named cast wins; else inherit the
            // project's locked subject so the new scene's PERSON matches the
            // rest (face via the character reference path, costume via the
            // board suffix). character_ids carries the full cast for
            // multi-character scenes (generation multi-paths when >1).
            $characterId = $sceneCastIds[0] ?? ($project->default_character_id ?: null);

            $imageToken = (string) Str::uuid();
            $scene = Scene::query()->create([
                'project_id'        => $project->getKey(),
                'scene_order'       => $position,
                'scene_type'        => 'narration',
                'label'             => "Scene {$position}",
                'script_text'       => $scriptText,
                'duration_seconds'  => 8,
                'character_id'      => $characterId,
                'character_ids'     => ! empty($sceneCastIds) ? $sceneCastIds : null,
                'voice_settings_json' => [
                    'voice_id' => $resolvedVoice['voice_id'],
                    'provider' => $resolvedVoice['provider'],
                    'voice_prompt' => $voicePrompt,
                    'speed'    => 1.0,
                    'is_outdated' => false,   // newly created — not outdated
                    'last_error' => null,
                ],
                'caption_settings_json' => $project->default_caption_settings_json ?? null,
                'visual_type'   => 'ai_image',
                'visual_prompt' => $visualPrompt,
                'visual_style'  => $style,
                'status'        => 'draft',
                'image_generation_settings_json' => [
                    'in_progress'           => true,
                    'last_error'            => null,
                    'needs_visual'          => false,
                    'generation_token'      => $imageToken,
                    'generation_started_at' => now()->toIso8601String(),
                ],
            ]);

            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $project->getKey(),
                $style,
                null,
                $style,
                $imageToken,
                $animateTier ? $animateDuration : null,
                null,                  // motion prompt — let the user re-animate later if they want
                $animateTier,
                null,
                $autoRefIds,           // anchor to a prior generated image when no character is locked
            )->afterCommit();

            GenerateTTSJob::dispatch($project->getKey(), [$scene->getKey()], false)->afterCommit();

            return [
                'summary'       => "Added Scene {$position} (\"" . mb_substr($scriptText, 0, 30) . "\")",
                'credits_spent' => $this->estimateCost($project, $params),
                // Frontend needs this to (a) start polling against the new
                // scene and (b) drop it into scenes.value so the editor
                // shows the pending placeholder while jobs run.
                'affected_scene_id' => (int) $scene->getKey(),
            ];
        });
    }

    /**
     * Resolve the assistant's character_names to character ids, scoped to the
     * project's cast (characters its scenes already use) plus any workspace
     * character that matches by name. Unknown names are ignored — the scene
     * still generates from its prompt. Returns deduped character ids.
     *
     * @param  string[]  $names
     * @return int[]
     */
    private function resolveSceneCast(Workspace $workspace, Project $project, array $names): array
    {
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $names)));
        if (empty($names)) {
            return [];
        }

        // Project cast: characters referenced by this project's scenes + default.
        $castIds = Scene::query()
            ->where('project_id', $project->getKey())
            ->get(['character_id', 'character_ids'])
            ->flatMap(fn (Scene $s) => array_merge(
                $s->character_id ? [(int) $s->character_id] : [],
                is_array($s->character_ids) ? array_map('intval', $s->character_ids) : [],
            ))
            ->push($project->default_character_id ? (int) $project->default_character_id : null)
            ->filter()->unique()->values()->all();

        // Match by name within the workspace (project cast OR any saved char).
        $lowered = array_map('mb_strtolower', $names);
        $chars = \App\Models\Character::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(function ($q) use ($castIds, $lowered): void {
                if (! empty($castIds)) {
                    $q->whereIn('id', $castIds);
                }
                foreach ($lowered as $n) {
                    $q->orWhereRaw('LOWER(name) = ?', [$n]);
                }
            })
            ->get(['id', 'name']);

        $byName = [];
        foreach ($chars as $c) {
            if ($c->name) {
                $byName[mb_strtolower((string) $c->name)] = (int) $c->id;
            }
        }

        $resolved = [];
        foreach ($lowered as $n) {
            if (isset($byName[$n])) {
                $resolved[] = $byName[$n];
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Does this text describe a person? Used to decide whether a newly added
     * scene should inherit face continuity — we never force a person reference
     * into person-less b-roll ("a clean product shot on a white background").
     */
    private function mentionsPerson(string $text): bool
    {
        return (bool) preg_match(
            '/\b(she|he|her|him|his|hers|woman|women|man|men|girl|guy|lady|person|people|narrator|founder|character|host|presenter|they|them|their|the same)\b/i',
            $text,
        );
    }

    /**
     * The already-generated AI image in this project closest to $position —
     * preferring the scene just BEFORE the insert (continuity flows forward),
     * falling back to the nearest after. Only AI-generated images qualify
     * (an uploaded/stock asset is not a face to match). Returns the asset id,
     * or null if the project has no generated image yet.
     */
    private function nearestGeneratedImageAsset(Project $project, int $position): ?int
    {
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereNotNull('visual_asset_id')
            ->get(['id', 'scene_order', 'visual_asset_id']);
        if ($scenes->isEmpty()) {
            return null;
        }

        $generated = Asset::query()
            ->whereIn('id', $scenes->pluck('visual_asset_id')->unique()->all())
            ->where('asset_type', 'image')
            ->whereJsonContains('tags', 'ai_generated')
            ->pluck('id')
            ->flip();

        $candidates = $scenes->filter(fn (Scene $s) => isset($generated[$s->visual_asset_id]));
        if ($candidates->isEmpty()) {
            return null;
        }

        // Prefer the nearest PRECEDING scene ("from the previous scene"), then
        // fall back to the nearest following one. Primary key puts all
        // preceding scenes first; secondary key picks the closest within each
        // group.
        $best = $candidates->sortBy(fn (Scene $s) => [
            $s->scene_order >= $position ? 1 : 0,
            abs($s->scene_order - $position),
        ])->first();

        return (int) $best->visual_asset_id;
    }
}
