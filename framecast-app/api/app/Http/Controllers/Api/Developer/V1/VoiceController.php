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
