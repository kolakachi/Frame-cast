<?php

namespace App\Http\Controllers\Api\V1\Ugc;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAIImageJob;
use App\Jobs\GenerateTTSJob;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\TTS\GeminiVoices;
use App\Services\Ugc\UgcShotPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UGC ads — internal only for now (see the 'internal' middleware on the route
 * group).
 *
 * Two endpoints, deliberately separate. `plan` is free and reversible: it
 * returns a shot plan for the UI to render and let the user adjust. `generate`
 * is the one that spends credits, and it takes the plan back as input rather
 * than re-planning, so what the user approved is exactly what gets built.
 *
 * Generation reuses the existing pipeline wholesale. A UGC ad is a normal
 * multi-scene project whose talking scenes carry `planned_spokesperson`;
 * GenerateTalkingVideoJob::maybeDispatchForScene fires the lip-sync itself once
 * that scene's image and voice both exist. Nothing here orchestrates jobs by
 * hand, and the result opens in the ordinary editor.
 */
class UgcController extends Controller
{
    private const MAX_SCRIPT = 1500;
    private const MAX_CHARACTERS = 5;

    /**
     * Plan the shots. Spends nothing.
     */
    public function plan(Request $request, UgcShotPlanner $planner): JsonResponse
    {
        $validated = $request->validate([
            'script'           => ['required', 'string', 'max:'.self::MAX_SCRIPT],
            'product'          => ['sometimes', 'nullable', 'string', 'max:200'],
            'context'          => ['sometimes', 'nullable', 'string', 'max:300'],
            'duration_seconds' => ['sometimes', 'integer', 'min:5', 'max:180'],
            'language'         => ['sometimes', 'string', 'max:12'],
            'available_footage' => ['sometimes', 'array', 'max:20'],
            'available_footage.*' => ['string', 'max:120'],
        ]);

        $plan = $planner->plan(
            $validated['script'],
            (string) ($validated['product'] ?? ''),
            (string) ($validated['context'] ?? ''),
            (int) ($validated['duration_seconds'] ?? 30),
            (string) ($validated['language'] ?? 'en'),
            array_values($validated['available_footage'] ?? []),
        );

        return response()->json([
            'data' => [
                'segments'  => $plan['segments'],
                'reasoning' => $plan['reasoning'],
                // Per character, because every selected character generates the
                // whole plan again.
                'credits_per_character' => $plan['estimated_credits'],
            ],
            'meta' => [],
        ]);
    }

    /**
     * Build one project per character from an approved plan and start it.
     */
    public function generate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'script'        => ['required', 'string', 'max:'.self::MAX_SCRIPT],
            'character_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHARACTERS],
            'character_ids.*' => ['integer'],
            'segments'      => ['required', 'array', 'min:1', 'max:24'],
            'segments.*.kind'         => ['required', 'string', 'in:on_camera,b_roll'],
            'segments.*.script_text'  => ['required', 'string', 'max:'.self::MAX_SCRIPT],
            'segments.*.seconds'      => ['sometimes', 'numeric', 'min:0.5', 'max:60'],
            'segments.*.visual_brief' => ['sometimes', 'nullable', 'string', 'max:400'],
            'segments.*.source'       => ['sometimes', 'nullable', 'string', 'in:upload,stock,generate'],
            'aspect_ratio'  => ['sometimes', 'string', 'in:9:16,1:1,16:9'],
            'language'      => ['sometimes', 'string', 'max:12'],
            'title'         => ['sometimes', 'nullable', 'string', 'max:120'],
            // The spokesperson attestation, same as the wizard collects.
            'consent'       => ['accepted'],
        ]);

        // Only this workspace's characters, and only real ones.
        $characters = Character::query()
            ->whereIn('id', $validated['character_ids'])
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'active')
            ->get();

        if ($characters->count() !== count(array_unique($validated['character_ids']))) {
            return response()->json([
                'error' => [
                    'code'    => 'character_not_found',
                    'message' => 'One or more of those characters no longer exist in this workspace.',
                ],
            ], 422);
        }

        $missingImage = $characters->filter(fn (Character $c) => ! $c->reference_asset_id);
        if ($missingImage->isNotEmpty()) {
            return response()->json([
                'error' => [
                    'code'    => 'character_missing_reference',
                    'message' => 'These characters need a reference image before they can talk: '
                        .$missingImage->pluck('name')->implode(', ').'.',
                ],
            ], 422);
        }

        $segments = $this->normaliseSegments($validated['segments']);
        if ($segments === []) {
            return response()->json([
                'error' => ['code' => 'empty_plan', 'message' => 'That plan has no lines to say.'],
            ], 422);
        }

        // Quote the whole run before spending any of it — a half-funded batch
        // that dies on character three is worse than one that never starts.
        $perCharacter = $this->quoteSegments($segments);
        $total = $perCharacter * $characters->count();
        $balance = app(CreditService::class)->balance((int) $user->workspace_id);

        if ($balance < $total) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "This run needs {$total} credits ({$perCharacter} per character) and you have {$balance}.",
                    'details' => ['required' => $total, 'balance' => $balance, 'per_character' => $perCharacter],
                ],
            ], 422);
        }

        $language = (string) ($validated['language'] ?? 'en');
        $aspect   = (string) ($validated['aspect_ratio'] ?? '9:16');
        $title    = trim((string) ($validated['title'] ?? '')) ?: $this->titleFrom($validated['script']);

        $projects = [];
        foreach ($characters as $character) {
            $projects[] = DB::transaction(fn () => $this->buildProject(
                $user, $character, $segments, $title, $aspect, $language, $validated['script'],
            ));
        }

        // Voice runs per project and fans out across its scenes; dispatched
        // after the transactions so no job can see a half-written project.
        foreach ($projects as $p) {
            GenerateTTSJob::dispatch($p['id']);
        }

        return response()->json([
            'data' => [
                'takes' => $projects,
                'credits_quoted' => $total,
            ],
            'meta' => [],
        ], 201);
    }

    /**
     * One character's take: a project whose scenes alternate between talking
     * and cut-away exactly as the approved plan says.
     *
     * @param  list<array<string,mixed>>  $segments
     * @return array{id:int,character:string,scenes:int,credits:int}
     */
    private function buildProject(
        User $user,
        Character $character,
        array $segments,
        string $title,
        string $aspect,
        string $language,
        string $script,
    ): array {
        $totalSeconds = (int) round(array_sum(array_column($segments, 'seconds')));

        $project = Project::query()->create([
            'workspace_id'       => $user->workspace_id,
            'created_by_user_id' => $user->getKey(),
            'title'              => $title.' — '.$character->name,
            'aspect_ratio'       => $aspect,
            'duration_target_seconds' => max(1, $totalSeconds),
            'status'             => 'generating',
            'source_type'        => 'script',
            'primary_language'   => $language,
            'source_content_raw' => $script,
            'default_character_id' => $character->getKey(),
        ]);

        $voiceId = GeminiVoices::defaultForGender($character->gender ?? null);
        $order = 0;

        foreach ($segments as $seg) {
            $order++;
            $isTalking = $seg['kind'] === 'on_camera';

            $scene = Scene::query()->create([
                'project_id'       => $project->getKey(),
                'scene_order'      => $order,
                'scene_type'       => 'narration',
                'label'            => $isTalking ? "On camera {$order}" : "Cut-away {$order}",
                'script_text'      => $seg['script_text'],
                'duration_seconds' => max(1, (int) round($seg['seconds'])),
                // One voice across every scene, talking or not: the narration
                // has to sound like the same person while the picture cuts.
                'voice_settings_json' => [
                    'voice_id'  => $voiceId,
                    'provider'  => 'google',
                    'speed'     => 1.0,
                    'stability' => 'medium',
                ],
                'caption_settings_json' => [
                    'enabled'         => true,
                    'style_key'       => 'impact',
                    'highlight_mode'  => 'keywords',
                    'position'        => 'bottom_third',
                    'font'            => 'Bebas Neue',
                    'highlight_color' => '#ff6b35',
                ],
                'visual_type'   => $isTalking ? 'spokesperson' : 'ai_image',
                'visual_prompt' => $isTalking ? null : ($seg['visual_brief'] ?: null),
                'status'        => 'draft',
                'character_id'  => $isTalking ? $character->getKey() : null,
            ]);

            $imageToken = (string) Str::uuid();

            $scene->forceFill([
                'image_generation_settings_json' => array_filter([
                    'in_progress'           => true,
                    'last_error'            => null,
                    'needs_visual'          => false,
                    'generation_token'      => $imageToken,
                    'generation_started_at' => now()->toIso8601String(),
                    'reference_asset_ids'   => $isTalking ? [(int) $character->reference_asset_id] : [],
                    // Lip-sync needs the image AND the voice, so it cannot chain
                    // off the image the way i2v does. The flag lets whichever
                    // finishes last fire the talking job.
                    'planned_spokesperson'  => $isTalking ? true : null,
                    'spokesperson_consent'  => $isTalking ? [
                        'at'      => now()->toIso8601String(),
                        'user_id' => (int) $user->getKey(),
                    ] : null,
                    // Where the cut-away picture should come from. 'upload'
                    // means the customer still has to supply it — the scene is
                    // built so the editor can show the gap.
                    'ugc_broll_source'      => $isTalking ? null : ($seg['source'] ?? 'stock'),
                ], fn ($v) => $v !== null),
            ])->save();

            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $project->getKey(),
                'photorealistic',
                null,
                'photorealistic',
                $imageToken,
            );
        }

        return [
            'id'        => (int) $project->getKey(),
            'character' => (string) $character->name,
            'scenes'    => $order,
            'credits'   => $this->quoteSegments($segments),
        ];
    }

    /**
     * Re-derive the plan server-side. The client sends back what it showed the
     * user, but the cost and the shape are decided here — a hand-edited request
     * must not be able to buy a 30-segment run at a 3-segment price.
     *
     * @param  list<array<string,mixed>>  $raw
     * @return list<array<string,mixed>>
     */
    private function normaliseSegments(array $raw): array
    {
        $out = [];
        foreach ($raw as $seg) {
            $text = trim((string) ($seg['script_text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $seconds = (float) ($seg['seconds'] ?? 0);
            if ($seconds <= 0) {
                $seconds = max(1.5, round(str_word_count($text) / 2.6, 1));
            }

            $kind = ($seg['kind'] ?? '') === 'b_roll' ? 'b_roll' : 'on_camera';

            $out[] = [
                'kind'         => $kind,
                'script_text'  => $text,
                'seconds'      => $seconds,
                'visual_brief' => $kind === 'b_roll' ? trim((string) ($seg['visual_brief'] ?? '')) : '',
                'source'       => $kind === 'b_roll'
                    ? (in_array($seg['source'] ?? '', ['upload', 'stock', 'generate'], true) ? $seg['source'] : 'stock')
                    : null,
            ];
        }

        if ($out !== []) {
            // The opener is the whole ad. Never let an edited payload start on
            // a cut-away.
            $out[0]['kind'] = 'on_camera';
            $out[0]['visual_brief'] = '';
            $out[0]['source'] = null;
        }

        return $out;
    }

    /** Talking scenes carry the cost; cut-aways are stock or the user's own footage. */
    private function quoteSegments(array $segments): int
    {
        $total = 0;
        foreach ($segments as $seg) {
            if ($seg['kind'] === 'on_camera') {
                $total += CreditService::spokespersonCost((float) $seg['seconds']);
            }
        }

        return $total;
    }

    private function titleFrom(string $script): string
    {
        $first = Str::of($script)->trim()->limit(48, '')->value();

        return $first !== '' ? $first : 'UGC ad';
    }
}
