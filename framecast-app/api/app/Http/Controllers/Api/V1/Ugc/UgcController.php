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
use Illuminate\Support\Facades\URL;
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
        $v = $request->validate([
            'run' => ['sometimes', 'nullable', 'string', 'max:64'],
            'detail' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'integer'],
        ]);
        $query = Project::query()->where('workspace_id', $request->user()->workspace_id)
            ->whereNotNull('visual_brief->ugc_format')->with('scenes')->latest('id')->limit(30);
        if (! empty($v['run'])) {
            $query->where('visual_brief->ugc_run_id', $v['run']);
        }
        if (! empty($v['project_id'])) $query->whereKey($v['project_id']);
        $detail = (bool) ($v['detail'] ?? false);
        $takes = $query->get()->map(function (Project $project) use ($detail) {
            $pending = false;
            $working = false;
            $failed = $project->status === 'failed';
            $sceneRows = [];
            $thumbAssetId = null;
            foreach ($project->scenes->sortBy('scene_order') as $scene) {
                $settings = $scene->image_generation_settings_json ?? [];
                $voice = $scene->voice_settings_json ?? [];
                $sceneError = trim((string) (($settings['last_error'] ?? '') ?: ($settings['animation_last_error'] ?? '') ?: ($voice['last_error'] ?? '')));
                $failed = $failed || $sceneError !== '';
                // A whole-video take (one-shot, restyle) delivers its scene
                // visual AS the finished video — no lip-sync leg exists to
                // wait for, and demanding one reported these takes as
                // generating forever.
                $wholeVideo = in_array(data_get($project->visual_brief, 'ugc_format'), ['one_shot', 'restyle'], true);
                $actor = ! $wholeVideo && in_array($settings['ugc_kind'] ?? '', ['on_camera', 'reaction'], true);
                $spoken = trim((string) $scene->script_text) !== '' && ($voice['enabled'] ?? true);
                $visualBusy = ! empty($settings['in_progress']) || ! empty($settings['animation_in_progress']);
                $visualDone = $scene->visual_asset_id && ! $visualBusy && (! $actor || ! empty($settings['animation_video_asset_id']));
                $voiceDone = ! $spoken || ! empty($voice['audio_asset_id']);
                $scenePending = ! $visualDone || ! $voiceDone;
                $pending = $pending || $scenePending;
                $working = $working || ($scenePending && $sceneError === '');
                if ($detail) {
                    $sceneRows[] = [
                        'id' => $scene->id,
                        'label' => $scene->label,
                        'kind' => $settings['ugc_kind'] ?? 'b_roll',
                        'script_text' => \Illuminate\Support\Str::limit((string) $scene->script_text, 90),
                        'voice' => $spoken ? ($voiceDone ? 'done' : 'working') : 'none',
                        'visual' => $sceneError !== '' ? 'failed' : ($visualDone ? 'done' : 'working'),
                        // The provider's raw failure is logged, not shown — it
                        // names hosts and internals the user can't act on.
                        'error' => $sceneError !== '' ? 'This scene failed to generate. Retry the failed step; completed scenes are kept.' : null,
                        'preview_asset_id' => $scene->visual_asset_id,
                    ];
                }
                // First finished visual carries the take's card thumbnail.
                if ($thumbAssetId === null && $scene->visual_asset_id && $sceneError === '') {
                    $thumbAssetId = (int) $scene->visual_asset_id;
                }
            }

            $thumbUrl = $thumbAssetId ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'media.assets.content', now()->addMinutes((int) config('media.signed_url_ttl_minutes', 720)),
                ['assetId' => $thumbAssetId],
            ) : null;
            // A one-shot take's visual is a video; the card must know which
            // element to render — an <img> pointed at an mp4 shows a broken
            // glyph.
            $thumbType = $thumbAssetId ? Asset::query()->whereKey($thumbAssetId)->value('asset_type') : null;
            $row = ['id' => $project->id, 'character' => $project->title, 'scenes' => $project->scenes->count(),
                'thumbnail_url' => $thumbUrl, 'thumbnail_type' => $thumbType, 'created_at' => $project->created_at?->toIso8601String(),
                'format' => data_get($project->visual_brief, 'ugc_format'),
                'credits' => data_get($project->visual_brief, 'ugc_estimated_credits', 0),
                'variant' => data_get($project->visual_brief, 'ugc_variant'),
                'run_id' => data_get($project->visual_brief, 'ugc_run_id'),
                'revision_at' => data_get($project->visual_brief, 'ugc_revision_at'),
                'revision_export_id' => data_get($project->visual_brief, 'ugc_revision_export_id'),
                'pending' => $pending,
                'working' => $working,
                'status' => $failed ? 'needs_attention' : ($pending ? 'generating' : 'ready_for_review')];
            if ($detail) {
                $row['scene_rows'] = $sceneRows;
            }

            return $row;
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
        // The client's saved context follows both UGC and owned-footage planning.
        $workspaceId = (int) $request->user()->workspace_id;
        $v['context'] = trim(($v['context'] ?? '')."\n".app(\App\Services\Agency\ClientContext::class)->prompt($workspaceId));
        $clientBrief = json_decode(DB::table('client_profiles')->where('workspace_id', $workspaceId)->value('brief') ?? '{}', true);
        $v['footage_asset_ids'] = array_slice(array_unique(array_merge($v['footage_asset_ids'] ?? [], $clientBrief['asset_ids'] ?? [])), 0, 40);
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

        // The saved characters, descriptions only — the director casts the
        // best audience fit from text; no image leaves the workspace. Same
        // scope the picker shows: workspace library plus stock actors.
        $roster = [];
        foreach (\App\Models\Character::query()
            ->where('status', 'active')->where('is_auto', false)
            // Only faces the pipeline can anchor: a pick without a reference
            // image would generate a stranger under a saved character's name.
            ->whereNotNull('reference_asset_id')
            ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->orWhere('is_stock', true))
            ->orderByDesc('workspace_id')->limit(40)
            ->get(['id', 'name', 'description', 'style', 'gender', 'age_group', 'situations']) as $c) {
            $roster[] = [
                'id' => $c->getKey(),
                'name' => mb_substr((string) $c->name, 0, 80),
                'description' => mb_substr((string) ($c->description ?? ''), 0, 300),
                'style' => mb_substr((string) ($c->style ?? ''), 0, 60),
                'gender' => mb_substr((string) ($c->gender ?? ''), 0, 32),
                'age_group' => mb_substr((string) ($c->age_group ?? ''), 0, 32),
                'situations' => array_slice((array) ($c->situations ?? []), 0, 6),
            ];
        }

        return response()->json(['data' => $planner->plan(
            (string) ($v['script'] ?? ''), (string) ($v['product'] ?? ''), (string) ($v['context'] ?? ''),
            $v['duration_seconds'], $v['language'] ?? 'en', $v['available_footage'] ?? [], $v['format'],
            $v['reference'] ?? [], $library, $roster,
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
            'request_id' => ['sometimes', 'uuid'],
            'script' => ['present', 'nullable', 'string', 'max:1500'],
            // A still-only format has no presenter, so casting is optional
            // there — and anything sent anyway is ignored below rather than
            // fanned out into identical presenter-less takes.
            // Casting is required when someone is actually on camera — not
            // by format label. A demo assembled purely from clips (reused
            // footage) has nobody to cast, same as a text-led run.
            'character_ids' => [
                Rule::requiredIf(fn () => (bool) array_filter(
                    (array) $request->input('segments', []),
                    fn ($s) => (($s['kind'] ?? '') !== 'b_roll'),
                )),
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

        // Serialize quota checks and creation per workspace. The same request key
        // returns the committed run after a timeout, even if its credits are now spent.
        return DB::transaction(function () use ($request, $user, $v) {
            \App\Models\Workspace::whereKey($user->workspace_id)->lockForUpdate()->firstOrFail();
            $requestId = $v['request_id'] ?? (string) Str::uuid(); // Legacy/internal callers.
            $fingerprint = hash('sha256', json_encode($v));
            $existing = DB::table('ugc_run_requests')->where('workspace_id', $user->workspace_id)->where('request_id', $requestId)->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    return $this->error('request_changed', 'This submission has already started with a different plan. Start a new run.', 409);
                }
                return response()->json(json_decode($existing->response, true), 200);
            }

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
            // Casting follows the requiredIf above: present when someone is
            // on camera, absent for still-only runs AND clip-only demos.
            if (! $stillOnly && ! empty($v['character_ids'])) {
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
            // The reviewed plan, then any extra openings. Each row is a full take
            // for every character, which is how a careless run becomes thirty.
            $plans = [['label' => '', 'segments' => $segments]];
            foreach ($v['variants'] ?? [] as $i => $variant) {
                $plans[] = [
                    'label' => trim((string) ($variant['label'] ?? '')) ?: 'Variant '.($i + 1),
                    'segments' => UgcPlan::normalise($variant['segments'], $v['format']),
                ];
            }

            // Validate and resolve assets across EVERY plan, including extra openings.
            $assets = [];
            foreach ($plans as $planIndex => $plan) {
                foreach ($plan['segments'] as $i => $seg) {
                    if ($seg['kind'] !== 'b_roll' || $seg['source'] === 'generate') {
                        continue;
                    }
                    $asset = $seg['asset_id'] ? Asset::where('workspace_id', $user->workspace_id)
                        ->whereKey($seg['asset_id'])->whereIn('asset_type', ['image', 'video'])->first() : null;
                    if (! $asset || ! $asset->storage_url) {
                        throw ValidationException::withMessages([($planIndex === 0 ? "segments.{$i}.asset_id" : "variants.".($planIndex - 1).".segments.{$i}.asset_id") => 'Select accessible footage for every shot and opening. Missing footage is never replaced by an AI image.']);
                    }
                    $assets[$asset->id] = $asset;
                }
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
            // One id across every take in the run, so the progress screen can
            // follow the whole batch with a single query.
            $runId = (string) Str::uuid();
            // All characters/scene records commit together. No job can see a half-built batch.
            $projects = DB::transaction(function () use ($user, $characters, $plans, $v, $assets, $runId) {
                $built = [];
                foreach ($plans as $plan) {
                    foreach ($characters->isEmpty() ? [null] : $characters->all() as $character) {
                        $built[] = $this->buildProject($user, $character, $plan['segments'], $v, $assets, $plan['label'], $runId);
                    }
                }

                return $built;
            });

            $payload = ['data' => ['takes' => $projects, 'credits_quoted' => $total, 'run_id' => $runId], 'meta' => []];
            DB::table('ugc_run_requests')->insert(['workspace_id'=>$user->workspace_id,'request_id'=>$requestId,'fingerprint'=>$fingerprint,'response'=>json_encode($payload),'created_at'=>now(),'updated_at'=>now()]);
            return response()->json($payload, 201);
        });
    }

    /**
     * Read a product page so the brief can start from a link. Returns what we
     * found for the user to confirm — never silently assumed correct.
     */
    public function readLink(Request $request, AIGenerationAdapter $ai): JsonResponse
    {
        $v = $request->validate(['url' => ['required', 'url', 'max:2000']]);

        try {
            $content = app(\App\Services\Generation\UrlContentExtractor::class)->extract($v['url']);
        } catch (\Throwable $e) {
            return $this->error('link_unreadable', $e->getMessage() ?: 'That page could not be read.', 422);
        }

        try {
            $result = $ai->generate('ugc_read_link', [
                'page_content' => mb_substr($content, 0, 12000),
                'url' => $v['url'],
            ], 900, 0.4, ['operation' => 'ugc_read_link']);
            $parsed = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''));
            $product = trim((string) ($parsed['product'] ?? ''));
            $context = trim((string) ($parsed['context'] ?? ''));
            if ($product === '' && $context === '') {
                throw new \UnexpectedValueException('empty read');
            }

            return response()->json(['data' => [
                'product' => mb_substr($product, 0, 200),
                'context' => mb_substr($context, 0, 1500),
                'must_include' => mb_substr(trim((string) ($parsed['must_include'] ?? '')), 0, 200),
                'source_url' => $v['url'],
            ], 'meta' => []]);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('link_unreadable', 'We opened the page but could not make sense of it. Paste the product details instead.', 422);
        }
    }

    /**
     * Bring a remote video into the workspace by URL — the footage flow's
     * "paste a link" intake. Downloads server-side into managed storage.
     */
    public function fetchVideo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $v = $request->validate(['url' => ['required', 'url', 'max:2000']]);
        $url = $v['url'];
        if (! preg_match('#^https?://#i', $url)) {
            return $this->error('invalid_url', 'Only http(s) links can be fetched.', 422);
        }
        // A fetch the server performs on the user's behalf must not be able
        // to reach the server's own network.
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $this->error('invalid_url', 'That address points somewhere we cannot fetch from.', 422);
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ugc-fetch-');
        try {
            $response = \Illuminate\Support\Facades\Http::connectTimeout(10)->timeout(180)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->sink($tmp)
                ->get($url);
            if (! $response->successful()) {
                return $this->error('fetch_failed', 'The link answered with an error ('.$response->status().'). Check it opens in a browser, or upload the file instead.', 422);
            }
            $size = (int) filesize($tmp);
            if ($size < 1024) {
                return $this->error('fetch_failed', 'The link did not return a video file. Some platforms block direct downloads — upload the file instead.', 422);
            }
            if ($size > 500 * 1024 * 1024) {
                return $this->error('fetch_failed', 'That file is larger than 500 MB. Trim it or upload a smaller cut.', 422);
            }
            $mime = mime_content_type($tmp) ?: (string) $response->header('Content-Type');
            if (! str_starts_with($mime, 'video/')) {
                return $this->error('fetch_failed', 'The link returned "'.($mime ?: 'unknown').'", not a video. Page links (YouTube, TikTok) cannot be fetched directly — use the file itself.', 422);
            }

            $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp4';
            $path = sprintf('workspace-assets/%d/%s.%s', $user->workspace_id, Str::uuid()->toString(), $extension);
            $storageUrl = app(\App\Services\Media\StorageService::class)->put($path, file_get_contents($tmp), ['ContentType' => $mime]);

            $probe = $this->probeVideo($tmp);
            $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Fetched video';
            $asset = Asset::query()->create([
                'workspace_id' => $user->workspace_id,
                'asset_type' => 'video',
                'title' => mb_substr($name, 0, 255),
                'description' => 'Fetched from '.$url,
                'storage_url' => $storageUrl,
                'mime_type' => $mime,
                'file_size_bytes' => $size,
                'duration_seconds' => $probe['duration'],
                'dimensions_json' => $probe['dimensions'],
                'tags' => ['fetched_url'],
                'status' => 'active',
                'created_by_user_id' => $user->id,
            ]);

            return response()->json(['data' => ['asset' => $asset], 'meta' => []], 201);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{duration: ?float, dimensions: ?array} */
    private function probeVideo(string $path): array
    {
        try {
            $process = new \Symfony\Component\Process\Process([
                'ffprobe', '-v', 'error', '-select_streams', 'v:0',
                '-show_entries', 'stream=width,height:format=duration',
                '-of', 'json', $path,
            ]);
            $process->setTimeout(30)->run();
            $probe = json_decode($process->getOutput(), true) ?: [];
            $stream = $probe['streams'][0] ?? [];

            return [
                'duration' => isset($probe['format']['duration']) ? (float) $probe['format']['duration'] : null,
                'dimensions' => isset($stream['width']) ? ['width' => (int) $stream['width'], 'height' => (int) $stream['height']] : null,
            ];
        } catch (\Throwable) {
            return ['duration' => null, 'dimensions' => null];
        }
    }

    /**
     * Retry one failed leg of a UGC scene. Charge-on-success billing means a
     * failed leg never charged, so the retry is free until it works.
     */
    public function sceneRetry(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $scene = Scene::query()->whereKey($sceneId)
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id)->whereNotNull('visual_brief->ugc_format'))
            ->first();
        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $settings = $scene->image_generation_settings_json ?? [];
        $voice = $scene->voice_settings_json ?? [];
        $retried = [];

        if (! empty($voice['last_error'])) {
            $scene->forceFill(['voice_settings_json' => array_merge($voice, ['last_error' => null])])->save();
            GenerateTTSJob::dispatch($scene->project_id)->afterCommit();
            $retried[] = 'voice';
        }

        if (! empty($settings['last_error'])) {
            $token = (string) Str::uuid();
            $reaction = ($settings['ugc_kind'] ?? '') === 'reaction';
            $scene->forceFill(['image_generation_settings_json' => array_merge($settings, [
                'last_error' => null, 'in_progress' => true,
                'generation_token' => $token, 'generation_started_at' => now()->toIso8601String(),
            ])])->save();
            GenerateAIImageJob::dispatch(
                $scene->id, $scene->project_id, 'photorealistic', null, 'photorealistic', $token,
                $reaction ? (int) $scene->duration_seconds : null,
                $reaction ? (string) ($settings['ugc_motion_prompt'] ?? '') : null,
                $reaction ? UgcPlan::REACTION_TIER : null,
                null,
                array_map('intval', $settings['reference_asset_ids'] ?? []),
            )->afterCommit();
            $retried[] = 'visual';
        } elseif (! empty($settings['animation_last_error'])) {
            $scene->forceFill(['image_generation_settings_json' => array_merge($settings, [
                'animation_last_error' => null,
            ])])->save();
            if (! empty($settings['planned_spokesperson'])) {
                // Release the one-time dispatch guard so the retry can win it.
                \Illuminate\Support\Facades\Cache::forget('spokesperson-dispatch:'.$scene->getKey());
                \App\Jobs\GenerateTalkingVideoJob::maybeDispatchForScene($scene);
            } else {
                \App\Jobs\AnimateSceneJob::dispatch(
                    $scene->id, $scene->project_id, UgcPlan::REACTION_TIER,
                    max(3, min(10, (int) $scene->duration_seconds)),
                    (string) ($settings['ugc_motion_prompt'] ?? '') ?: null,
                )->afterCommit();
            }
            $retried[] = 'animation';
        }

        if ($retried === []) {
            return $this->error('nothing_to_retry', 'This scene has no failed step to retry.', 422);
        }

        return response()->json(['data' => ['retried' => $retried], 'meta' => []]);
    }

    /**
     * One-full-video generation: the reviewed plan compiles into screenplay
     * chunks and generates as a single fluid take on Veo — the presenter
     * speaks natively, cuts land at beat boundaries, no scenes assembled.
     * Same caps, balance check and charge-on-success as every take.
     */
    /**
     * A still of roughly who Seedance will cast for this character's
     * variant lane: the cached casting sheet rendered by gpt-image-2 (the
     * round-trip-proven reconstructor). Costs one image generation —
     * a fraction of discovering a bad sheet after a full take.
     */
    public function variantPreview(Request $request, int $characterId): JsonResponse
    {
        $user = $request->user();
        $character = Character::query()->whereKey($characterId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('workspace_id', $user->workspace_id)
                ->orWhere(fn ($sq) => $sq->whereNull('workspace_id')->where('is_stock', true)))->first();
        if (! $character) {
            return $this->error('not_found', 'Character not found.', 404);
        }

        $credits = app(\App\Services\Generation\Image\ImageAdapterFactory::class)->generationCost('gpt-image-2');
        $creditService = app(CreditService::class);
        if ($creditService->balance((int) $user->workspace_id) < $credits) {
            return $this->error('insufficient_credits', sprintf('This preview costs %d credits.', $credits), 402);
        }

        $sheetText = app(\App\Services\Ugc\CharacterAppearanceService::class)->text($character);
        $prompt = sprintf(
            'Photorealistic vertical selfie-style portrait of %s. Looking into the lens, soft natural light, authentic phone-camera feel',
            $sheetText,
        );
        try {
            $result = app(\App\Services\Generation\Image\ImageAdapterFactory::class)
                ->resolve('gpt-image-2')->generate($prompt, 'realistic', '9:16');
        } catch (\Throwable $e) {
            return $this->error('generation_failed', 'The preview could not be generated. Nothing was charged.', 502);
        }
        $bytes = ! empty($result['image_b64']) ? base64_decode($result['image_b64'])
            : (! empty($result['image_url']) ? @file_get_contents($result['image_url']) : null);
        if (! $bytes) {
            return $this->error('generation_failed', 'The preview could not be generated. Nothing was charged.', 502);
        }

        $storage = app(\App\Services\Media\StorageService::class);
        $path = sprintf('workspaces/%d/assets/variant-previews/%s.png', $user->workspace_id, \Illuminate\Support\Str::uuid());
        $storageUrl = $storage->put($path, $bytes, ['ContentType' => 'image/png']);
        $asset = Asset::query()->create([
            'workspace_id' => $user->workspace_id,
            'asset_type' => 'image',
            'title' => mb_substr('Variant preview — '.$character->name, 0, 255),
            'storage_url' => $storageUrl,
            'mime_type' => 'image/png',
            'file_size_bytes' => strlen($bytes),
            'tags' => ['ugc_variant_preview'],
            'status' => 'active',
            'created_by_user_id' => $user->getKey(),
        ]);
        $creditService->deduct((int) $user->workspace_id, $credits, 'ugc_variant_preview', [
            'character_id' => $character->getKey(), 'asset_id' => $asset->getKey(),
        ]);

        return response()->json(['data' => [
            'asset_id' => $asset->getKey(),
            'preview_url' => URL::temporarySignedRoute('media.assets.content',
                now()->addMinutes((int) config('media.signed_url_ttl_minutes', 720)), ['assetId' => $asset->getKey()]),
            'sheet' => $sheetText,
            'credits_charged' => $credits,
        ], 'meta' => []]);
    }

    public function generateOneShot(Request $request, \App\Services\CreditService $creditService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $v = $request->validate($this->planRules() + [
            'script' => ['present', 'nullable', 'string', 'max:1500'],
            'character_id' => ['nullable', 'integer', 'min:1'],
            // exact = Veo anchored to the character's real face; variant =
            // Seedance's renderer from a written casting sheet — a close
            // look-alike, disclosed as such in the UI.
            'cast_style' => ['sometimes', 'string', 'in:exact,variant'],
            'presenter_description' => ['nullable', 'string', 'max:400'],
            'product_asset_id' => ['nullable', 'integer', 'min:1'],
            // Several angles teach the model the product's geometry — one
            // front shot means it invents the back when the presenter turns it.
            'product_asset_ids' => ['nullable', 'array', 'max:5'],
            'product_asset_ids.*' => ['integer', 'min:1'],
            'setting' => ['nullable', 'string', 'max:300'],
            'product' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'max:200'],
            'language' => ['sometimes', 'string', 'max:12'],
            'consent' => ['accepted'],
            'reviewed' => ['accepted'],
            'credits' => ['required', 'integer'],
        ]);

        $segments = UgcPlan::normalise($v['segments'], $v['format']);
        $spoken = array_values(array_filter($segments, fn ($s) => trim((string) $s['script_text']) !== ''));
        if ($spoken === []) {
            throw ValidationException::withMessages(['segments' => 'A one-take ad needs spoken beats. Silent card plans generate through the standard path.']);
        }

        // The pose sheet: the presenter's reference image and the real
        // product photo travel into the generation as reference_images, so
        // the person and the packaging match what the user approved.
        $referenceImages = [];
        $dataUri = function (?int $assetId) use ($user): ?string {
            if (! $assetId) {
                return null;
            }
            $asset = Asset::query()->whereKey($assetId)
                ->where(fn ($q) => $q->where('workspace_id', $user->workspace_id)->orWhereNull('workspace_id'))
                ->first();
            if (! $asset || ! str_starts_with((string) $asset->mime_type, 'image/')) {
                return null;
            }
            $stream = app(\App\Services\Media\StorageService::class)->readStream((string) $asset->storage_url);
            if (! is_resource($stream)) {
                return null;
            }
            $bytes = stream_get_contents($stream, 8 * 1024 * 1024);

            return $bytes ? 'data:'.$asset->mime_type.';base64,'.base64_encode($bytes) : null;
        };
        $productIds = array_values(array_unique(array_filter(array_merge(
            [(int) ($v['product_asset_id'] ?? 0)],
            array_map('intval', $v['product_asset_ids'] ?? []),
        ))));
        foreach (array_slice($productIds, 0, 5) as $pid) {
            if ($uri = $dataUri($pid)) {
                $referenceImages[] = $uri;
            }
        }

        // The character's reference image ALWAYS travels when a character is
        // cast — it was gated behind 'no description supplied', and since the
        // app always supplies one, no generation ever saw the chosen face.
        $presenter = trim((string) ($v['presenter_description'] ?? ''));
        $presenterImageAttached = false;
        $variantSeed = null;
        if (! empty($v['character_id'])) {
            $c = Character::query()->whereKey($v['character_id'])
                ->where(fn ($q) => $q->where('workspace_id', $user->workspace_id)
                    ->orWhere(fn ($sq) => $sq->whereNull('workspace_id')->where('is_stock', true)))->first();
            if ($c && ($v['cast_style'] ?? 'exact') === 'variant') {
                // No face image goes anywhere: the cached casting sheet
                // describes the character in text, and Seedance renders a
                // close look-alike. Disclosed in the UI as a variant.
                $presenter = app(\App\Services\Ugc\CharacterAppearanceService::class)->text($c);
                $variantSeed = crc32('wyv-char-'.$c->getKey()) & 0x7FFFFFFF;
            } elseif ($c) {
                if ($presenter === '') {
                    $presenter = trim($c->name.($c->description ? ' — '.$c->description : ''));
                }
                if ($uri = $dataUri((int) $c->reference_asset_id)) {
                    array_unshift($referenceImages, $uri); // presenter first — identity outranks props
                    $presenterImageAttached = true;
                }
            }
        }

        $engine = in_array($request->input('engine'), ['seedance25', 'veo'], true) ? $request->input('engine') : 'seedance25';
        // Proven A/B: Seedance's moderation declines photoreal faces in its
        // reference set (deepfake protection) — the exact same prompt passes
        // without the face. A cast character therefore anchors identity as a
        // Veo START FRAME instead, which accepts person images happily.
        $characterFrame = null;
        if ($presenterImageAttached) {
            $characterFrame = array_shift($referenceImages); // presenter was unshifted first
            $engine = 'veo';
        }
        $style = [
            'presenter' => $presenter,
            'setting' => trim((string) ($v['setting'] ?? '')),
            'product' => trim((string) ($v['product'] ?? '')),
            'tone' => trim((string) ($v['tone'] ?? '')),
        ];
        // Seedance 2.5 makes the whole ad in one generation (≤30s) — the
        // purest one-take. Longer plans, or explicit choice, chain on Veo.
        $single = $engine === 'seedance25'
            ? \App\Services\Ugc\UgcOneShotCompiler::compileSingle($segments, $style)
            : null;
        if ($engine === 'seedance25' && $single === null) {
            $engine = 'veo';
        }
        $chunks = $single !== null
            ? [$single]
            : \App\Services\Ugc\UgcOneShotCompiler::compile($segments, $style);
        // Quote from the PLAN's seconds, not the snapped chunk sum — chunk
        // snapping (4/6/8 on Veo) can exceed the plan by a few seconds, and
        // that overage is ours to absorb, not the user's to re-approve.
        $planSeconds = max(4, (int) ceil(array_sum(array_map(fn ($s) => max(1, (float) $s['seconds']), $segments))));
        $totalSeconds = array_sum(array_column($chunks, 'seconds'));
        $quote = (int) ($planSeconds * CreditService::VIDEO_ONESHOT_PER_SECOND[$engine]);
        if ((int) $v['credits'] !== $quote) {
            throw ValidationException::withMessages(['credits' => "The estimate changed — this take is {$quote} credits. Review and approve again."]);
        }
        if ($creditService->balance((int) $user->workspace_id) < $quote) {
            throw ValidationException::withMessages(['credits' => "This take needs {$quote} credits."]);
        }
        $monthlyCap = $creditService->limitFor((int) $user->workspace_id, 'ugc_takes_month');
        if ($monthlyCap !== null) {
            $used = Project::query()->where('workspace_id', $user->workspace_id)
                ->whereNotNull('visual_brief->ugc_format')->where('created_at', '>=', now()->startOfMonth())->count();
            if ($used + 1 > (int) $monthlyCap) {
                throw ValidationException::withMessages(['takes' => sprintf(
                    'Your plan includes %d UGC takes a month; you have used %d. The count resets on the 1st.', $monthlyCap, $used)]);
            }
        }

        $runId = (string) Str::uuid();
        $script = UgcPlan::script($segments);
        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => Str::limit($script, 48, '…', preserveWords: true) ?: 'One-take UGC ad',
            'aspect_ratio' => '9:16',
            'duration_target_seconds' => (int) $totalSeconds,
            'status' => 'generating',
            'source_type' => 'script',
            'primary_language' => $v['language'] ?? 'en',
            'source_content_raw' => $script,
            'visual_brief' => [
                'ugc_format' => 'one_shot',
                'ugc_estimated_credits' => $quote,
                'ugc_run_id' => $runId,
            ],
        ]);
        $scene = Scene::query()->create([
            'project_id' => $project->id, 'scene_order' => 1, 'scene_type' => 'narration',
            'label' => 'One-take ad', 'script_text' => $script, 'duration_seconds' => $totalSeconds,
            'voice_settings_json' => ['enabled' => false],
            'caption_settings_json' => ['enabled' => false],
            'visual_type' => 'video', 'status' => 'draft',
            'image_generation_settings_json' => [
                'in_progress' => true, 'ugc_kind' => 'on_camera',
                'generation_started_at' => now()->toIso8601String(),
            ],
        ]);
        \App\Jobs\GenerateOneShotUgcJob::dispatch(
            $project->id, $scene->id,
            array_map(fn ($c) => ['prompt' => $c['prompt'], 'seconds' => $c['seconds']], $chunks),
            $quote,
            $engine,
            $referenceImages,
            $characterFrame,
            $variantSeed,
        )->afterCommit();

        return response()->json(['data' => [
            'takes' => [[ 'id' => $project->id, 'character' => $project->title,
                'scenes' => 1, 'credits' => $quote, 'status' => 'generating',
                'run_id' => $runId, 'variant' => null ]],
            'credits_quoted' => $quote, 'run_id' => $runId,
        ], 'meta' => []], 201);
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

    private function buildProject(User $user, ?Character $character, array $segments, array $v, array $assets, string $variantLabel = '', ?string $runId = null): array
    {
        $reaction = $v['format'] === 'reaction';
        $script = UgcPlan::script($segments);
        // Cut at a word boundary with a visible ellipsis — a title chopped
        // mid-word ("one content idea i") reads as a bug on every take card.
        $title = trim((string) ($v['title'] ?? '')) ?: Str::limit($script ?: $segments[0]['headline'], 48, '…', preserveWords: true);
        // A silent clip-only take has neither script nor headlines — a blank
        // card title reads as a rendering bug.
        if ($title === '') {
            $title = 'Assembled from your footage';
        }
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
                'ugc_run_id' => $runId,
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
                    'voice_prompt' => $seg['voice_direction'],
                    // A silent card has no line to read — leaving voice
                    // enabled sent '' to the TTS engine.
                    'enabled' => ! $reaction && trim((string) $seg['script_text']) !== ''],
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
        // Dispatched even for a fully silent take: the job skips wordless
        // scenes and is also the finalizer that flips ready_for_review.
        if (! $reaction) {
            GenerateTTSJob::dispatch($project->id)->afterCommit();
        }

        return ['id' => $project->id, 'character' => $character?->name ?? 'No presenter',
            'scenes' => count($segments), 'credits' => UgcPlan::quote($segments), 'status' => 'generating',
            'run_id' => $runId, 'variant' => $variantLabel ?: null];
    }
}
