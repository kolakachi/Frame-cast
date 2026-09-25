<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\CreditService;
use App\Services\Generation\TTS\GeminiVoices;
use App\Services\Generation\TTS\RoutingTTSAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The voices a quote may name: WyvStudio's catalogue plus this workspace's
 * own (including clones). Each carries what it costs per scene, so an
 * assistant can say "the cloned voice is 2 credits a scene" before quoting.
 */
class VoiceController extends DeveloperController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $voices = self::catalogue((int) $user->workspace_id)->map(fn (VoiceProfile $v) => self::serialize($v))->values();

        return response()->json(['data' => [
            'default_voice_id' => GeminiVoices::DEFAULT_VOICE,
            'voices' => $voices,
        ], 'meta' => ['count' => $voices->count()]]);
    }

    public function cloneVoice(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'name' => ['required', 'string', 'max:80'],
            'source_asset_id' => ['required', 'integer'],
            'consent' => ['required', 'boolean', 'accepted'],
        ]);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $input) {
            // Serialize allowance checks and retried registrations across keys.
            \App\Models\Workspace::query()->whereKey($request->user()->workspace_id)->lockForUpdate()->firstOrFail();
            $sample = \App\Models\Asset::query()->whereKey($input['source_asset_id'])
                ->where('workspace_id', $request->user()->workspace_id)->where('asset_type', 'audio')->first();
            if (! $sample) return $this->fail('invalid_sample', 'Choose an audio sample in this workspace.', 422);
            $existing = VoiceProfile::query()->where('workspace_id', $request->user()->workspace_id)
                ->where('original_sample_asset_id', $sample->id)->where('status', 'active')->first();
            if ($existing) return response()->json(['data' => ['voice' => self::serialize($existing)], 'meta' => ['reused_existing' => true]]);
            $inner = \App\Services\Developer\EditOperations::inner($request, $input);
            $response = app(\App\Http\Controllers\Api\V1\VoiceProfile\VoiceProfileController::class)->clone($inner);
            if ($response->getStatusCode() >= 400) return $response;
            $profile = VoiceProfile::query()->findOrFail($response->getData(true)['data']['voice_profile']['id']);
            $profile->forceFill(['original_sample_asset_id' => $sample->id,
                'consent_acknowledged_at' => now(), 'consent_user_id' => $request->user()->id])->save();
            return response()->json(['data' => ['voice' => self::serialize($profile)], 'meta' => ['credits_charged' => 0]], 201);
        });
    }

    public function save(Request $request): JsonResponse
    {
        $input = $this->validated($request, ['name' => ['required', 'string', 'max:80'], 'voice_id' => ['required', 'string', 'max:150']]);
        $voice = self::resolve((int) $request->user()->workspace_id, $input['voice_id']);
        if (! $voice) return $this->fail('not_found', 'Voice not found in this workspace or catalogue.', 404);
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $input, $voice) {
            \App\Models\Workspace::query()->whereKey($request->user()->workspace_id)->lockForUpdate()->firstOrFail();
            $profile = VoiceProfile::query()->where('workspace_id', $request->user()->workspace_id)->where('provider_voice_key', $voice->provider_voice_key)->first();
            if (! $profile) {
                $profile = $voice->replicate();
                $profile->workspace_id = $request->user()->workspace_id;
                $profile->name = $input['name'];
                $profile->save();
            }
            return response()->json(['data' => ['voice' => self::serialize($profile)], 'meta' => []]);
        });
    }

    public function preview(Request $request): JsonResponse
    {
        $input = $this->validated($request, ['voice_id' => ['required', 'string', 'max:150']]);
        $voice = self::resolve((int) $request->user()->workspace_id, $input['voice_id']);
        if (! $voice) return $this->fail('not_found', 'Voice not found in this workspace or catalogue.', 404);
        // Preview the shared canonical profile instead of paying for a fresh
        // cached sample every time a catalogue voice is saved under a new name.
        if (! $voice->is_cloned) $voice = VoiceProfile::query()->whereNull('workspace_id')->where('status', 'active')->where('provider_voice_key', $voice->provider_voice_key)->first() ?? $voice;
        $response = app(\App\Http\Controllers\Api\V1\VoiceProfile\VoiceProfileController::class)->preview(
            \App\Services\Developer\EditOperations::inner($request, ['voice_profile_id' => $voice->id]));
        if ($response->getStatusCode() >= 400) return $response;
        return response()->json(['data' => $response->getData(true)['data'] + [
            'preview_kind' => $voice->is_cloned ? 'source_sample' : 'synthetic_sample',
            'is_generated_clone_preview' => false,
        ], 'meta' => ['credits_charged' => 0]]);
    }

    /** Active voices this workspace may use: the shared catalogue and its own. */
    public static function catalogue(int $workspaceId)
    {
        return VoiceProfile::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))
            ->orderByRaw('workspace_id asc nulls first')
            ->orderBy('name')
            ->get();
    }

    /** The profile behind a voice id, if this workspace may use it. */
    public static function resolve(int $workspaceId, string $voiceId): ?VoiceProfile
    {
        return VoiceProfile::query()
            ->where('status', 'active')
            ->where('provider_voice_key', $voiceId)
            ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))
            ->orderByRaw('workspace_id asc nulls last')
            ->first();
    }

    /** @return array<string, mixed> */
    public static function serialize(VoiceProfile $v): array
    {
        $engine = RoutingTTSAdapter::engineFor((string) $v->provider_voice_key, ['provider' => (string) $v->provider]);

        return [
            'id' => $v->provider_voice_key,
            'voice_profile_id' => $v->id,
            'status' => $v->status,
            'consent_acknowledged_at' => $v->consent_acknowledged_at,
            'name' => $v->name,
            'language' => $v->language,
            'accent' => $v->accent,
            'gender' => $v->gender_label,
            'is_cloned' => (bool) $v->is_cloned,
            'is_workspace_voice' => $v->workspace_id !== null,
            'engine' => $engine,
            'cost_per_scene' => CreditService::ttsCostForEngine($engine),
        ];
    }
}
