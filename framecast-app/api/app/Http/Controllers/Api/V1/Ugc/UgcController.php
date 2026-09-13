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
use App\Services\Ugc\UgcPlan;
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
        ]);

        return response()->json(['data' => $planner->plan(
            (string) ($v['script'] ?? ''), (string) ($v['product'] ?? ''), (string) ($v['context'] ?? ''),
            $v['duration_seconds'], $v['language'] ?? 'en', $v['available_footage'] ?? [], $v['format'],
        ), 'meta' => []]);
    }

    /** Re-price edits without generating media or charging credits. */
    public function quote(Request $request): JsonResponse
    {
        $v = $request->validate($this->planRules());
        $segments = UgcPlan::normalise($v['segments'], $v['format']);

        return response()->json(['data' => [
            'format' => $v['format'], 'segments' => $segments, 'script' => UgcPlan::script($segments),
            'credits_per_character' => UgcPlan::quote($segments), 'warnings' => UgcPlan::warnings($segments),
        ], 'meta' => []]);
    }

    public function generate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $v = $request->validate($this->planRules() + [
            'script' => ['present', 'nullable', 'string', 'max:1500'],
            'character_ids' => ['required', 'array', 'min:1', 'max:5'],
            'character_ids.*' => ['required', 'integer', 'distinct'],
            // Voice belongs to the presenter, not the run. Each take is a
            // different person, and one shared voice across several characters
            // makes the lip-sync read as dubbed. Keyed by character id.
            'voices' => ['sometimes', 'array'],
            'voices.*' => ['nullable', Rule::in(array_keys(GeminiVoices::VOICES))],
            'aspect_ratio' => ['required', 'in:9:16,1:1,16:9'],
            'language' => ['sometimes', 'string', 'max:12'],
            'voice_key' => ['nullable', Rule::in(array_keys(GeminiVoices::VOICES))],
            'title' => ['nullable', 'string', 'max:120'],
            'consent' => ['accepted'], 'reviewed' => ['accepted'],
            'credits_per_character' => ['required', 'integer', 'min:0'],
        ]);
        $segments = UgcPlan::normalise($v['segments'], $v['format']);
        if (! UgcPlan::sameScript((string) ($v['script'] ?? ''), UgcPlan::script($segments))) {
            throw ValidationException::withMessages(['script' => 'The script and shot plan differ. Review and re-price the latest plan.']);
        }
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
        $perCharacter = UgcPlan::quote($segments);
        if ($perCharacter !== $v['credits_per_character']) {
            throw ValidationException::withMessages(['credits_per_character' => 'The estimate changed. Re-price and review the plan before generating.']);
        }
        $total = $perCharacter * $characters->count();
        $balance = app(CreditService::class)->balance((int) $user->workspace_id);
        if ($balance < $total) {
            throw ValidationException::withMessages(['credits' => "This run needs an estimated {$total} credits and you have {$balance}."]);
        }
        // All characters/scene records commit together. No job can see a half-built batch.
        $projects = DB::transaction(function () use ($user, $characters, $segments, $v, $assets) {
            return $characters->map(fn ($c) => $this->buildProject($user, $c, $segments, $v, $assets))->all();
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
            'segments.*.motion_prompt' => ['nullable', 'string', 'max:1000'],
            'segments.*.headline' => ['nullable', 'string', 'max:180'],
            'segments.*.source' => ['nullable', 'in:upload,stock,generate'],
            'segments.*.asset_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function buildProject(User $user, Character $character, array $segments, array $v, array $assets): array
    {
        $reaction = $v['format'] === 'reaction';
        $script = UgcPlan::script($segments);
        $title = trim((string) ($v['title'] ?? '')) ?: Str::limit($script ?: $segments[0]['headline'], 48, '');
        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
            'title' => $title.' — '.$character->name, 'aspect_ratio' => $v['aspect_ratio'],
            'duration_target_seconds' => (int) ceil(array_sum(array_column($segments, 'seconds'))),
            'status' => 'generating',
            'source_type' => 'script', 'primary_language' => $v['language'] ?? 'en',
            'source_content_raw' => $script, 'default_character_id' => $character->id,
            'visual_brief' => ['ugc_format' => $v['format'], 'ugc_estimated_credits' => UgcPlan::quote($segments)],
        ]);
        // Per character first, then a run-wide key for the single-character
        // case, then the character's own gender. Picking a female voice for a
        // male presenter is visible on the lip-sync, so the gender default is
        // the last resort rather than the first.
        $voiceId = ($v['voices'][$character->id] ?? null)
            ?: ($v['voice_key'] ?? null)
            ?: GeminiVoices::defaultForGender($character->gender ?? null);
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
                'voice_settings_json' => ['voice_id' => $voiceId, 'provider' => 'google', 'speed' => 1.0,
                    'voice_prompt' => $seg['voice_direction'], 'enabled' => ! $reaction],
                'caption_settings_json' => [
                    'enabled' => ! $reaction, 'style_key' => 'impact', 'highlight_mode' => 'line_by_line',
                    'position' => 'bottom_third', 'font' => 'Arial', 'highlight_color' => '#ffffff',
                    'ugc_headline' => UgcHeadline::layout($seg['headline']),
                ],
                'visual_type' => $talking ? 'spokesperson' : ($asset ? $asset->asset_type : 'ai_image'),
                'visual_asset_id' => $asset?->id, 'character_id' => $actor ? $character->id : null,
                'visual_prompt' => ($actor ? UgcPlan::CAMERA.' ' : '').$seg['visual_brief'],
                'status' => 'draft',
                'image_generation_settings_json' => array_filter([
                    'in_progress' => $generate, 'needs_visual' => false, 'generation_token' => $token,
                    'generation_started_at' => $generate ? now()->toIso8601String() : null,
                    'reference_asset_ids' => $actor ? [(int) $character->reference_asset_id] : [],
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
                )->afterCommit();
            }
        }
        if (! $reaction) {
            GenerateTTSJob::dispatch($project->id)->afterCommit();
        }

        return ['id' => $project->id, 'character' => $character->name,
            'scenes' => count($segments), 'credits' => UgcPlan::quote($segments), 'status' => 'generating'];
    }
}
