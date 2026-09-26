<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\ApiQuote;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Projects\ProjectCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Price a video before anything is spent.
 *
 * The validated request is frozen into the quote, so VideoController builds
 * exactly what was priced — a client cannot quote a stock video and create an
 * AI one under the same approval. Quoting is free.
 */
class QuoteController extends DeveloperController
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly ProjectCreationService $creation,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;

        $input = $this->validated($request, [
            'source_type' => ['required', Rule::in(LookupController::SOURCE_TYPES)],
            'content' => ['required', 'string', 'max:10000'],
            'image_asset_ids' => ['required_if:source_type,images', 'prohibited_unless:source_type,images', 'array', 'min:1', 'max:15'],
            'image_asset_ids.*' => ['integer'],
            'visual_mode' => ['required', Rule::in(LookupController::VISUAL_MODES)],
            'visual_style' => ['nullable', 'prohibited_if:visual_mode,stock', 'prohibited_if:visual_mode,waveform', Rule::in(LookupController::visualStyles())],
            'custom_visual_style' => ['nullable', 'string', 'max:500', 'prohibited_if:visual_mode,stock', 'prohibited_if:visual_mode,waveform'],
            'duration_seconds' => ['nullable', 'integer', 'min:5', 'max:600'],
            'animate_tier' => ['required_if:visual_mode,ai_video', 'prohibited_unless:visual_mode,ai_video', Rule::in(CapabilitiesController::ANIMATE_TIERS)],
            'animation_pacing' => ['nullable', 'prohibited_unless:visual_mode,ai_video', Rule::in(['short', 'long'])],
            'audiogram' => ['nullable', 'array', 'prohibited_unless:visual_mode,waveform'],
            'audiogram.style' => ['nullable', 'string', 'max:64'],
            'audiogram.color' => ['nullable', 'string', 'max:16'],
            'audiogram.bg' => ['nullable', 'string', 'max:32'],
            'aspect_ratio' => ['nullable', Rule::in(CapabilitiesController::ASPECT_RATIOS)],
            'tone' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'content_goal' => ['nullable', 'string', 'max:255'],
            'voice_id' => ['nullable', 'string', 'max:255'],
            'brand_kit_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
            'niche_id' => ['nullable', 'integer'],
            'character_id' => ['nullable', 'integer'],
            'music_asset_id' => ['nullable', 'integer'],
            'languages' => ['nullable', 'array', 'min:1', 'max:5'],
            'languages.*' => [Rule::in(LookupController::LANGUAGES)],
            'platform_target' => ['nullable', Rule::in(LookupController::PLATFORM_TARGETS)],
            'allow_script_edit' => ['nullable', 'boolean'],
        ]);

        // Everything the quote points at must be this workspace's. The
        // creation service checks again; refusing here gives a clear code.
        $chosen = [];
        $own = [
            'brand_kit_id' => [\App\Models\BrandKit::class, 'brand_kit', null],
            'channel_id' => [\App\Models\Channel::class, 'channel', 'active'],
            'character_id' => [\App\Models\Character::class, 'character', 'active'],
        ];
        foreach ($own as $field => [$model, $label, $status]) {
            if (empty($input[$field])) continue;
            $row = $model::query()->whereKey($input[$field])->where('workspace_id', $workspaceId)->when($status, fn ($q) => $q->where('status', $status))->first();
            if (! $row) {
                return $this->fail('invalid_'.$label, "No such {$label} in this workspace.", 422, [$field => $input[$field]]);
            }
            $chosen[$label] = ['id' => $row->getKey(), 'name' => $row->name];
        }
        if (! empty($input['niche_id'])) {
            $niche = \App\Models\Niche::query()->find($input['niche_id']);
            if (! $niche) {
                return $this->fail('invalid_niche', 'No such niche. List niches with GET /niches.', 422, ['niche_id' => $input['niche_id']]);
            }
            $chosen['niche'] = ['id' => $niche->getKey(), 'name' => $niche->name];
        }
        if (! empty($input['music_asset_id'])) {
            $music = \App\Models\Asset::query()->whereKey($input['music_asset_id'])->where('workspace_id', $workspaceId)->where('asset_type', 'music')->first();
            if (! $music) {
                return $this->fail('invalid_music', 'No such music track in this workspace. List with GET /library?type=music.', 422, ['music_asset_id' => $input['music_asset_id']]);
            }
            $chosen['music'] = ['id' => $music->getKey(), 'name' => $music->title];
        }
        if (! empty($input['image_asset_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $input['image_asset_ids'])));
            $found = \App\Models\Asset::query()->whereIn('id', $ids)->where('workspace_id', $workspaceId)->where('asset_type', 'image')->count();
            if ($found !== count($ids)) {
                return $this->fail('invalid_images', 'Every image must be an image in this workspace\'s library (GET /library?type=image).', 422, ['image_asset_ids' => $ids]);
            }
            $chosen['images'] = ['count' => count($ids)];
        }

        $voice = null;
        if (! empty($input['voice_id'])) {
            $voice = VoiceController::resolve($workspaceId, (string) $input['voice_id']);
            if (! $voice) {
                return $this->fail('invalid_voice', 'No such voice in this workspace. List voices with GET /voices.', 422, ['voice_id' => $input['voice_id']]);
            }
        }

        if ($error = $this->creation->validateSourceContent($input['source_type'], $input['content'])) {
            return $this->fail('invalid_source_content', $error, 422);
        }

        $duration = (int) ($input['duration_seconds'] ?? 60);
        $maxDuration = $this->credits->maxDurationSeconds($workspaceId);
        if ($maxDuration !== null && $duration > $maxDuration) {
            $plan = $this->credits->planTier($workspaceId);

            return $this->fail('plan_duration_exceeded',
                "Your {$plan} plan caps video length at {$maxDuration}s; {$duration}s was requested.",
                422, ['plan' => $plan, 'max_duration_seconds' => $maxDuration, 'requested' => $duration]);
        }

        // The store() payload this quote will be created with. Field names
        // follow the dashboard's create request; the API's are the friendlier
        // aliases above.
        $audiogram = $input['audiogram'] ?? null;
        $payload = array_filter([
            'source_type' => $input['source_type'],
            'source_content_raw' => $input['content'],
            'source_image_asset_ids' => ! empty($input['image_asset_ids']) ? array_values(array_unique(array_map('intval', $input['image_asset_ids']))) : null,
            'visual_generation_mode' => $input['visual_mode'],
            'visual_style' => $input['visual_style'] ?? null,
            'custom_visual_style' => $input['custom_visual_style'] ?? null,
            'waveform_settings_json' => $audiogram ? array_filter([
                'audiogram_style' => $audiogram['style'] ?? null, 'audiogram_color' => $audiogram['color'] ?? null, 'audiogram_bg' => $audiogram['bg'] ?? null,
            ]) : null,
            'duration_target_seconds' => $duration,
            'animate_tier' => $input['animate_tier'] ?? null,
            'animation_pacing' => $input['animation_pacing'] ?? null,
            'aspect_ratio' => $input['aspect_ratio'] ?? '9:16',
            'tone' => $input['tone'] ?? null,
            'title' => $input['title'] ?? null,
            'content_goal' => $input['content_goal'] ?? null,
            'voice_settings_json' => $voice ? ['voice_id' => $voice->provider_voice_key] : null,
            'brand_kit_id' => $input['brand_kit_id'] ?? null,
            'channel_id' => $input['channel_id'] ?? null,
            'niche_id' => $input['niche_id'] ?? null,
            'character_id' => $input['character_id'] ?? null,
            'languages' => $input['languages'] ?? null,
            'platform_target' => $input['platform_target'] ?? null,
            'allow_script_edit' => $input['allow_script_edit'] ?? null,
            // Not a store() field: applied to the project right after create.
            'music_asset_id' => $input['music_asset_id'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        $estimate = $this->credits->estimateProject(
            sourceType: $payload['source_type'],
            sourceContent: $payload['source_content_raw'],
            visualMode: $payload['visual_generation_mode'],
            durationSeconds: $duration,
            animateTier: $payload['animate_tier'] ?? null,
            animationPacing: $payload['animation_pacing'] ?? null,
            voiceId: $voice?->provider_voice_key,
            usesCharacter: ! empty($input['character_id']),
        );

        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(),
            'workspace_id' => $workspaceId,
            'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(),
            'payload_json' => $payload + ['__kind' => 'video'],
            'credits_min' => $estimate['credits_min'],
            'credits_max' => $estimate['credits_max'],
            'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);

        $balance = $this->credits->balance($workspaceId);

        return response()->json(['data' => [
            'quote_id' => $quote->getKey(),
            'credits' => [
                'min' => $estimate['credits_min'],
                'max' => $estimate['credits_max'],
                'mid' => $estimate['credits_mid'],
                'breakdown' => $estimate['breakdown'],
            ],
            'scenes' => ['min' => $estimate['scenes_min'], 'max' => $estimate['scenes_max']],
            'balance' => $balance,
            // Creation requires the balance to cover the maximum, not the
            // minimum: an integration cannot top up mid-render.
            'can_afford' => $balance >= $estimate['credits_max'],
            'shortage' => max(0, $estimate['credits_max'] - $balance),
            'expires_at' => $quote->expires_at->toIso8601String(),
            'voice' => $voice ? VoiceController::serialize($voice) : null,
            // What the quote resolved to, by name, so the user can be shown it.
            'chosen' => $chosen ?: null,
            'request' => $input,
        ], 'meta' => []], 201);
    }
}
