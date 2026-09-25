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
            'source_type' => ['required', Rule::in(CapabilitiesController::SOURCE_TYPES)],
            'content' => ['required', 'string', 'max:10000'],
            'visual_mode' => ['required', Rule::in(CapabilitiesController::VISUAL_MODES)],
            'duration_seconds' => ['nullable', 'integer', 'min:5', 'max:600'],
            'animate_tier' => ['required_if:visual_mode,ai_video', 'prohibited_unless:visual_mode,ai_video', Rule::in(CapabilitiesController::ANIMATE_TIERS)],
            'animation_pacing' => ['nullable', 'prohibited_unless:visual_mode,ai_video', Rule::in(['short', 'long'])],
            'aspect_ratio' => ['nullable', Rule::in(CapabilitiesController::ASPECT_RATIOS)],
            'tone' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'content_goal' => ['nullable', 'string', 'max:255'],
            'voice_id' => ['nullable', 'string', 'max:255'],
        ]);

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
        $payload = array_filter([
            'source_type' => $input['source_type'],
            'source_content_raw' => $input['content'],
            'visual_generation_mode' => $input['visual_mode'],
            'duration_target_seconds' => $duration,
            'animate_tier' => $input['animate_tier'] ?? null,
            'animation_pacing' => $input['animation_pacing'] ?? null,
            'aspect_ratio' => $input['aspect_ratio'] ?? '9:16',
            'tone' => $input['tone'] ?? null,
            'title' => $input['title'] ?? null,
            'content_goal' => $input['content_goal'] ?? null,
            'voice_settings_json' => $voice ? ['voice_id' => $voice->provider_voice_key] : null,
        ], static fn (mixed $v): bool => $v !== null);

        $estimate = $this->credits->estimateProject(
            sourceType: $payload['source_type'],
            sourceContent: $payload['source_content_raw'],
            visualMode: $payload['visual_generation_mode'],
            durationSeconds: $duration,
            animateTier: $payload['animate_tier'] ?? null,
            animationPacing: $payload['animation_pacing'] ?? null,
            voiceId: $voice?->provider_voice_key,
        );

        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(),
            'workspace_id' => $workspaceId,
            'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(),
            'payload_json' => $payload,
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
            'request' => $input,
        ], 'meta' => []], 201);
    }
}
