<?php

namespace App\Http\Controllers\Api\V1\Ugc;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAIImageJob;
use App\Jobs\GenerateTTSJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\TTS\GeminiVoices;
use App\Services\Ugc\UgcHeadline;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Ugc\UgcPlan;
use App\Services\Ugc\UgcReference;
use App\Services\Ugc\UgcShotPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Internal UGC director. Planning/quoting are reversible; generation executes the reviewed plan. */
class UgcController extends Controller
{
    /**
     * Openings times characters. Five of each would be twenty-five full takes
     * off one click, and the estimate is only an estimate until speech is
     * synthesised — so the ceiling is low enough to watch one first.
     */
    private const MAX_TAKES_PER_RUN = 10;


    public function takes(Request $request): JsonResponse
    {
        $projects = Project::query()->where('workspace_id', $request->user()->workspace_id)
            ->whereNotNull('visual_brief->ugc_format')->with('scenes')->latest('id')->limit(30)->get();
        $takes = $projects->map(function (Project $project) {
            $pending = false;
            $failed = $project->status === 'failed';
            foreach ($project->scenes as $scene) {
                $settings = $scene->image_generation_settings_json ?? [];
                $voice = $scene->voice_settings_json ?? [];
                $failed = $failed || ! empty($settings['last_error']) || ! empty($settings['animation_last_error']) || ! empty($voice['last_error']);
                $actor = in_array($settings['ugc_kind'] ?? '', ['on_camera', 'reaction'], true);
                $pending = $pending || ! $scene->visual_asset_id || ! empty($settings['in_progress']) || ! empty($settings['animation_in_progress'])
                    || ($actor && empty($settings['animation_video_asset_id']))
                    || (trim((string) $scene->script_text) !== '' && empty($voice['audio_asset_id']));
            }

            return ['id' => $project->id, 'character' => $project->title, 'scenes' => $project->scenes->count(),
                'credits' => data_get($project->visual_brief, 'ugc_estimated_credits', 0),
                'status' => $failed ? 'needs_attention' : ($pending ? 'generating' : 'ready_for_review')];
        });

        return response()->json(['data' => ['takes' => $takes], 'meta' => []]);
    }

    public function plan(Request $request, UgcShotPlanner $planner): JsonResponse
    {
        $v = $request->validate([
            'script' => ['nullable', 'string', 'max:1500', 'required_without:context'],
            'product' => ['nullable', 'string', 'max:200'],
            'context' => ['nullable', 'string', 'max:1500', 'required_without:script'],
            'format' => ['required', Rule::in(['auto', ...UgcPlan::FORMATS])],
            'duration_seconds' => ['required', 'integer', 'min:5', 'max:180'],
            'language' => ['sometimes', 'string', 'max:12'],
            'available_footage' => ['sometimes', 'array', 'max:20'],
            'available_footage.*' => ['string', 'max:120'],
            // The workspace's own clips, so the director can assign them to
            // beats instead of the user hand-picking a file per shot. Labels
            // above stay for footage they have but have not uploaded yet.
            'footage_asset_ids' => ['sometimes', 'array', 'max:40'],
            'footage_asset_ids.*' => ['integer', 'min:1'],
            // The shape read off a reference, if the user started from one.
            'reference' => ['sometimes', 'array'],
            'reference.shape' => ['sometimes', 'nullable', 'string', 'max:300'],
            'reference.beats' => ['sometimes', 'array', 'max:8'],
            'reference.beats.*.role' => ['required_with:reference.beats', 'string', 'max:24'],
            'reference.beats.*.does' => ['sometimes', 'nullable', 'string', 'max:300'],
            'reference.beats.*.on_screen' => ['sometimes', 'nullable', 'string', 'max:300'],
            'reference.beats.*.start' => ['sometimes', 'numeric'],
            'reference.beats.*.end' => ['sometimes', 'numeric'],
        ]);

        // Resolved here, not trusted from the client: the planner only ever
        // sees clips this workspace owns.
        $library = [];
        foreach (Asset::query()
            ->where('workspace_id', $request->user()->workspace_id)
            ->whereIn('id', $v['footage_asset_ids'] ?? [])
            ->whereIn('asset_type', ['image', 'video'])
            ->whereNotNull('storage_url')
            ->limit(40)->get() as $asset) {
            $library[] = [
                'id' => $asset->getKey(),
                'kind' => $asset->asset_type,
                'title' => mb_substr((string) ($asset->title ?: 'Untitled'), 0, 120),
                'description' => mb_substr((string) ($asset->description ?? ''), 0, 200),
                'seconds' => $asset->duration_seconds ? round((float) $asset->duration_seconds, 1) : null,
            ];
        }

        return response()->json(['data' => $planner->plan(
            (string) ($v['script'] ?? ''), (string) ($v['product'] ?? ''), (string) ($v['context'] ?? ''),
            $v['duration_seconds'], $v['language'] ?? 'en', $v['available_footage'] ?? [], $v['format'],
            $v['reference'] ?? [], $library,
        ), 'meta' => []]);
    }

    /**
     * Read an uploaded ad into the shape it argues in, so a different product
     * can be advertised the same way. Reversible and free: nothing is
     * generated and no credits move.
     *
     * Upload only for now. A URL would need a fetcher for platforms whose
     * terms are their own question, and the analysis should prove itself
     * before the fetching becomes the risky part.
     */
    public function reference(Request $request, UgcReference $reader): JsonResponse
    {
        $v = $request->validate(['asset_id' => ['required', 'integer', 'min:1']]);

        $asset = Asset::query()
            ->where('workspace_id', $request->user()->workspace_id)
            ->whereKey($v['asset_id'])
            ->whereIn('asset_type', ['video', 'audio'])
            ->first();

        if (! $asset || ! $asset->storage_url) {
            throw ValidationException::withMessages([
                'asset_id' => 'Upload the reference to this workspace first.',
            ]);
        }

        return response()->json(['data' => ['reference' => $reader->read($asset)], 'meta' => []]);
    }

    /**
     * Alternative openings for a reviewed plan. Reversible and free: nothing is
     * generated and no credits move until one is chosen and run.
     */
    public function variants(Request $request, UgcShotPlanner $planner): JsonResponse
    {
        $v = $request->validate($this->planRules() + [
            'count' => ['sometimes', 'integer', 'min:2', 'max:6'],
            'product' => ['sometimes', 'nullable', 'string', 'max:200'],
            'context' => ['sometimes', 'nullable', 'string', 'max:1500'],
        ]);

        $segments = UgcPlan::normalise($v['segments'], $v['format']);
        $variants = $planner->hookVariants(
            $segments, $v['format'], (int) ($v['count'] ?? 3),
            (string) ($v['product'] ?? ''), (string) ($v['context'] ?? ''),
        );

        return response()->json(['data' => [
            'variants' => array_map(fn ($x) => [
                'label' => $x['label'],
                'segments' => $x['segments'],
                'script' => UgcPlan::script($x['segments']),
                'credits_per_character' => UgcPlan::quote($x['segments']),
                'warnings' => UgcPlan::warnings($x['segments'], $v['format']),
            ], $variants),
        ], 'meta' => []]);
    }

    /**
     * Re-direct the shots a script edit stranded. Reversible and free: no media
     * is made and no credits move, so a user can re-run it until the direction
     * reads right.
     */
    public function reanchor(Request $request, UgcShotPlanner $planner): JsonResponse
    {
        $v = $request->validate($this->planRules());
        $segments = UgcPlan::normalise($v['segments'], $v['format']);
        $segments = $planner->reanchor($segments, $v['format']);

        return response()->json(['data' => [
            'format' => $v['format'], 'segments' => $segments, 'script' => UgcPlan::script($segments),
            'credits_per_character' => UgcPlan::quote($segments), 'warnings' => UgcPlan::warnings($segments, $v['format']),
            'stale_shots' => UgcPlan::staleCount($segments),
        ], 'meta' => []]);
    }

    /** Re-price edits without generating media or charging credits. */
    public function quote(Request $request): JsonResponse
    {
        $v = $request->validate($this->planRules());
        $segments = UgcPlan::normalise($v['segments'], $v['format']);
        // Re-pricing is the moment to notice that an edit left a shot pointed
        // at a sentence the script no longer contains. The script is the shots'
        // own words joined, so editing any shot's line can strand a cutaway
        // anchored to it — including the edited shot's own visual direction.
        $segments = UgcPlan::reanchor($segments, UgcPlan::script($segments));

        return response()->json(['data' => [
            'format' => $v['format'], 'segments' => $segments, 'script' => UgcPlan::script($segments),
            'credits_per_character' => UgcPlan::quote($segments), 'warnings' => UgcPlan::warnings($segments, $v['format']),
            'stale_shots' => UgcPlan::staleCount($segments),
        ], 'meta' => []]);
    }


    /**
     * Write one of the two brief fields for someone staring at an empty form.
     *
     * Everything downstream is planned for them — script, visual brief, motion,
     * headline — so the only blank page left is the brief itself, which is
     * exactly where people stall. Spends no credits: it is a sentence, not a
     * generation, and charging for it would stop people using it.
     */
    public function suggest(Request $request, AIGenerationAdapter $ai): JsonResponse
    {
        $v = $request->validate([
            'field' => ['required', 'in:product,context,available_footage,script,visual_brief,motion_prompt,voice_direction,headline'],
            'product' => ['sometimes', 'nullable', 'string', 'max:200'],
            'context' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'available_footage' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Per-shot fields need to know which shot they belong to, or the
            // suggestion describes the ad in general and fits nothing.
            'shot' => ['sometimes', 'nullable', 'string', 'max:600'],
            'format' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $ai->generate('ugc_brief_suggestion', [
                'field' => $v['field'],
                'product' => trim((string) ($v['product'] ?? '')) ?: 'not said yet',
                'context' => trim((string) ($v['context'] ?? '')) ?: 'not said yet',
                'available_footage' => trim((string) ($v['available_footage'] ?? '')) ?: 'none mentioned',
                'shot' => trim((string) ($v['shot'] ?? '')) ?: 'not a specific shot',
                'format' => trim((string) ($v['format'] ?? '')) ?: 'not chosen yet',
            ], 600, 0.7, ['operation' => 'ugc_brief_suggestion']);

            $content = trim((string) ($result['content'] ?? $result['text'] ?? ''));
            $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
            $suggestion = trim((string) (json_decode($content, true)['suggestion'] ?? ''));

            if ($suggestion === '') {
                return $this->error('suggestion_unavailable', 'Could not think of one just now — try again.', 422);
            }

            return response()->json(['data' => ['suggestion' => $suggestion], 'meta' => []]);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('suggestion_unavailable', 'Could not think of one just now — try again.', 422);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $v = $request->validate($this->planRules() + [
            'script' => ['present', 'nullable', 'string', 'max:1500'],
            // A still-only format has no presenter, so casting is optional
            // there — and anything sent anyway is ignored below rather than
            // fanned out into identical presenter-less takes.
            'character_ids' => [
                Rule::requiredIf(fn () => ! in_array($request->input('format'), UgcPlan::STILL_ONLY_FORMATS, true)),
                'array', 'min:1', 'max:5',
            ],
            // Extra openings to run alongside the reviewed plan. Each is a
            // whole take per character, so they multiply — see the cap below.
            'variants' => ['sometimes', 'array', 'max:5'],
            'variants.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'variants.*.segments' => ['required_with:variants', 'array', 'min:1', 'max:12'],
            'character_ids.*' => ['required', 'integer', 'distinct'],
            // Voice belongs to the presenter, not the run. Each take is a
            // different person, and one shared voice across several characters
            // makes the lip-sync read as dubbed. Keyed by character id.
            'voices' => ['sometimes', 'array'],
            'voices.*' => ['nullable', Rule::in(array_keys(GeminiVoices::VOICES))],
            'aspect_ratio' => ['required', 'in:9:16,1:1,16:9'],
            // A photo of the thing being advertised. Composited onto the actor
            // so they hold or wear the real product rather than one the model
            // imagined — the difference between a demo of your product and a
            // demo of a product like yours.
            'product_asset_id' => ['sometimes', 'nullable', 'integer'],
            'language' => ['sometimes', 'string', 'max:12'],
            'voice_key' => ['nullable', Rule::in(array_keys(GeminiVoices::VOICES))],
            'title' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'], 'reviewed' => ['accepted'],
            'credits_per_character' => ['required', 'integer', 'min:0'],
        ]);

        $productAssetId = null;
        if (! empty($v['product_asset_id'])) {
            $product = Asset::query()
                ->whereKey($v['product_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('asset_type', 'image')
                ->first();
            if (! $product) {
                return $this->error('product_not_found', 'That product image is not in this workspace.', 422);
            }
            $productAssetId = (int) $product->getKey();
        }
        // Carry the validated id, never the raw one: buildProject reads $v, and
        // anything unchecked reaching it would escape the workspace scoping.
        $v['product_asset_id'] = $productAssetId;

        $segments = UgcPlan::normalise($v['segments'], $v['format']);
        if (! UgcPlan::sameScript((string) ($v['script'] ?? ''), UgcPlan::script($segments))) {
            throw ValidationException::withMessages(['script' => 'The script and shot plan differ. Review and re-price the latest plan.']);
        }
        $stillOnly = in_array($v['format'], UgcPlan::STILL_ONLY_FORMATS, true);
        $characters = collect();
        if (! $stillOnly) {
            $characters = Character::query()->whereIn('id', $v['character_ids'])->where('status', 'active')
                ->where(fn ($q) => $q->where('workspace_id', $user->workspace_id)
                    ->orWhere(fn ($sq) => $sq->whereNull('workspace_id')->where('is_stock', true)))->get();
            if ($characters->count() !== count($v['character_ids']) || $characters->contains(fn ($c) => ! $c->reference_asset_id)) {
                throw ValidationException::withMessages(['character_ids' => 'Choose accessible, active characters with reference images.']);
            }
            $referenceIds = $characters->pluck('reference_asset_id')->unique();
            $references = Asset::query()->whereIn('id', $referenceIds)->where('asset_type', 'image')
                ->whereNotNull('storage_url')->where('storage_url', '!=', '')->count();
            if ($references !== $referenceIds->count()) {
                throw ValidationException::withMessages(['character_ids' => 'A selected character reference is missing. Repair it before generating a take.']);
            }
        }
        // Resolve media before spending. Stock must be selected/imported into this workspace too.
        $assets = [];
        foreach ($segments as $i => $seg) {
            if ($seg['kind'] !== 'b_roll' || $seg['source'] === 'generate') {
                continue;
            }
            $asset = $seg['asset_id'] ? Asset::query()->where('workspace_id', $user->workspace_id)
                ->whereKey($seg['asset_id'])->whereIn('asset_type', ['image', 'video'])->first() : null;
            if (! $asset || ! $asset->storage_url) {
                throw ValidationException::withMessages(["segments.{$i}.asset_id" => 'Select accessible footage for this shot. Missing footage is never replaced by an AI image.']);
            }
            $assets[$asset->id] = $asset;
        }
        // The reviewed plan, then any extra openings. Each row is a full take
        // for every character, which is how a careless run becomes thirty.
        $plans = [['label' => '', 'segments' => $segments]];
        foreach ($v['variants'] ?? [] as $i => $variant) {
            $plans[] = [
                'label' => trim((string) ($variant['label'] ?? '')) ?: 'Variant '.($i + 1),
                'segments' => UgcPlan::normalise($variant['segments'], $v['format']),
            ];
        }

        $castCount = max(1, $characters->count());
        $takes = count($plans) * $castCount;

        // The monthly cap is what bounds the near-free shapes: a text-led take
        // on stock footage quotes zero credits and export is included, so
        // credits alone bound nothing on that path.
        $capService = app(CreditService::class);
        $monthlyCap = $capService->limitFor((int) $user->workspace_id, 'ugc_takes_month');
        if ($monthlyCap !== null) {
            $usedThisMonth = Project::query()
                ->where('workspace_id', $user->workspace_id)
                ->whereNotNull('visual_brief->ugc_format')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();
            if ($usedThisMonth + $takes > (int) $monthlyCap) {
                throw ValidationException::withMessages(['takes' => sprintf(
                    'Your plan includes %d UGC takes a month; you have used %d and this run adds %d. The count resets on the 1st.',
                    (int) $monthlyCap, $usedThisMonth, $takes,
                )]);
            }
        }
        if ($takes > self::MAX_TAKES_PER_RUN) {
            throw ValidationException::withMessages([
                'variants' => sprintf(
                    'That is %d takes — %d opening%s across %d character%s. Run at most %d at once so you can watch one before paying for the rest.',
                    $takes, count($plans), count($plans) === 1 ? '' : 's',
                    $castCount, $castCount === 1 ? '' : 's',
                    self::MAX_TAKES_PER_RUN,
                ),
            ]);
        }

        $perCharacter = UgcPlan::quote($segments);
        if ($perCharacter !== $v['credits_per_character']) {
            throw ValidationException::withMessages(['credits_per_character' => 'The estimate changed. Re-price and review the plan before generating.']);
        }
        // Openings differ in length, so each plan is priced on its own rather
        // than multiplying the first one's estimate.
        $total = 0;
        foreach ($plans as $plan) {
            $total += UgcPlan::quote($plan['segments']) * $castCount;
        }
        $balance = app(CreditService::class)->balance((int) $user->workspace_id);
        if ($balance < $total) {
            throw ValidationException::withMessages(['credits' => "This run needs an estimated {$total} credits and you have {$balance}."]);
        }
        // All characters/scene records commit together. No job can see a half-built batch.
        $projects = DB::transaction(function () use ($user, $characters, $plans, $v, $assets) {
            $built = [];
            foreach ($plans as $plan) {
                foreach ($characters->isEmpty() ? [null] : $characters->all() as $character) {
                    $built[] = $this->buildProject($user, $character, $plan['segments'], $v, $assets, $plan['label']);
                }
            }

            return $built;
        });

        return response()->json(['data' => ['takes' => $projects, 'credits_quoted' => $total], 'meta' => []], 201);
    }

    private function planRules(): array
    {
        return [
            'format' => ['required', Rule::in(UgcPlan::FORMATS)],
            'segments' => ['required', 'array', 'min:1', 'max:12'],
            'segments.*.kind' => ['required', 'in:on_camera,b_roll,reaction'],
            'segments.*.script_text' => ['present', 'nullable', 'string', 'max:1500'],
            'segments.*.seconds' => ['required', 'numeric', 'min:1', 'max:60'],
            'segments.*.visual_brief' => ['required', 'string', 'max:1000'],
            'segments.*.voice_direction' => ['nullable', 'string', 'max:500'],
            'segments.*.speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
            'segments.*.motion_prompt' => ['nullable', 'string', 'max:1000'],
            'segments.*.anchor' => ['nullable', 'string', 'max:1500'],
            'segments.*.anchor_role' => ['nullable', Rule::in(UgcPlan::ANCHOR_ROLES)],
            'segments.*.headline' => ['nullable', 'string', 'max:180'],
            'segments.*.source' => ['nullable', 'in:upload,stock,generate'],
            'segments.*.asset_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function buildProject(User $user, ?Character $character, array $segments, array $v, array $assets, string $variantLabel = ''): array
    {
        $reaction = $v['format'] === 'reaction';
        $script = UgcPlan::script($segments);
        $title = trim((string) ($v['title'] ?? '')) ?: Str::limit($script ?: $segments[0]['headline'], 48, '');
        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
            // The variant's angle in the title, or six takes on one screen are
            // distinguishable only by opening them.
            'title' => $title.($character ? ' — '.$character->name : '').($variantLabel !== '' ? ' · '.$variantLabel : ''),
            'aspect_ratio' => $v['aspect_ratio'],
            'duration_target_seconds' => (int) ceil(array_sum(array_column($segments, 'seconds'))),
            'status' => 'generating',
            'source_type' => 'script', 'primary_language' => $v['language'] ?? 'en',
            'source_content_raw' => $script, 'default_character_id' => $character?->id,
            'visual_brief' => [
                'ugc_format' => $v['format'],
                'ugc_estimated_credits' => UgcPlan::quote($segments),
                'ugc_variant' => $variantLabel ?: null,
            ],
        ]);
        // Per character first, then a run-wide key for the single-character
        // case, then the character's own gender. Picking a female voice for a
        // male presenter is visible on the lip-sync, so the gender default is
        // the last resort rather than the first.
        $voiceId = ($character ? ($v['voices'][$character->id] ?? null) : null)
            ?: ($v['voice_key'] ?? null)
            ?: GeminiVoices::defaultForGender($character?->gender);
        foreach ($segments as $i => $seg) {
            $talking = $seg['kind'] === 'on_camera';
            $actor = $seg['kind'] !== 'b_roll';
            $asset = $assets[$seg['asset_id'] ?? 0] ?? null;
            $generate = $actor || $seg['source'] === 'generate';
            $token = $generate ? (string) Str::uuid() : null;
            $scene = Scene::query()->create([
                'project_id' => $project->id, 'scene_order' => $i + 1, 'scene_type' => 'narration',
                'label' => ucfirst(str_replace('_', ' ', $seg['kind'])).' '.($i + 1),
                'script_text' => $seg['script_text'], 'duration_seconds' => $seg['seconds'],
                'voice_settings_json' => ['voice_id' => $voiceId, 'provider' => 'google',
                    'speed' => $seg['speed'] ?? 1.0,
                    'voice_prompt' => $seg['voice_direction'], 'enabled' => ! $reaction],
                'caption_settings_json' => [
                    'enabled' => ! $reaction, 'style_key' => 'impact', 'highlight_mode' => 'line_by_line',
                    'position' => 'bottom_third', 'font' => 'Arial', 'highlight_color' => '#ffffff',
                    'ugc_headline' => UgcHeadline::layout($seg['headline']),
                ],
                'visual_type' => $talking ? 'spokesperson' : ($asset ? $asset->asset_type : 'ai_image'),
                'visual_asset_id' => $asset?->id, 'character_id' => $actor && $character ? $character->id : null,
                'visual_prompt' => ($actor ? UgcPlan::CAMERA.' ' : '').$seg['visual_brief'],
                'status' => 'draft',
                'image_generation_settings_json' => array_filter([
                    'in_progress' => $generate, 'needs_visual' => false, 'generation_token' => $token,
                    'generation_started_at' => $generate ? now()->toIso8601String() : null,
                    // Actor first, product second: the adapter preserves any
                    // person's likeness and reproduces any object exactly, so
                    // the order carries the roles.
                    'reference_asset_ids' => $actor && $character
                        ? array_values(array_filter([(int) $character->reference_asset_id, ($v['product_asset_id'] ?? null)]))
                        : [],
                    // Carried onto the scene so a later rewrite in the editor
                    // can tell what this visual was chosen to answer. Without
                    // it the anchor dies at the planning step and the editor is
                    // back to guessing.
                    'ugc_anchor' => $seg['anchor'],
                    'ugc_anchor_role' => $seg['anchor_role'],
                    'planned_spokesperson' => $talking,
                    'spokesperson_consent' => $talking ? ['at' => now()->toIso8601String(), 'user_id' => $user->id] : null,
                    'ugc_format' => $v['format'], 'ugc_kind' => $seg['kind'], 'ugc_broll_source' => $seg['source'],
                    'ugc_motion_prompt' => $seg['motion_prompt'],
                ], fn ($x) => $x !== null),
            ]);
            if ($generate) {
                GenerateAIImageJob::dispatch(
                    $scene->id, $project->id, 'photorealistic', null, 'photorealistic', $token,
                    $reaction ? (int) $seg['seconds'] : null,
                    $reaction ? $seg['motion_prompt'].' Natural restrained movement, preserve identity and outfit. No speaking, no text or watermark.' : null,
                    $reaction ? UgcPlan::REACTION_TIER : null,
                    null,
                    // Only where a person appears — a generated cutaway of the
                    // product alone does not need the actor's face in it.
                    $actor && $character && ($v['product_asset_id'] ?? null)
                        ? array_values(array_filter([(int) $character->reference_asset_id, ($v['product_asset_id'] ?? null)]))
                        : [],
                )->afterCommit();
            }
        }
        if (! $reaction) {
            GenerateTTSJob::dispatch($project->id)->afterCommit();
        }

        return ['id' => $project->id, 'character' => $character?->name ?? 'No presenter',
            'scenes' => count($segments), 'credits' => UgcPlan::quote($segments), 'status' => 'generating'];
    }
}
