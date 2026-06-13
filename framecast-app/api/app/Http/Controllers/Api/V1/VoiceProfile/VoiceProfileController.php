<?php

namespace App\Http\Controllers\Api\V1\VoiceProfile;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Media\StorageService;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class VoiceProfileController extends Controller
{
    /**
     * @return list<array{id:int,workspace_id:null,provider:string,name:string,language:string,accent:?string,gender_label:string,voice_type:string,is_cloned:bool,provider_voice_key:string,status:string}>
     */
    private function fallbackVoices(): array
    {
        return [
            [
                'id' => 0,
                'workspace_id' => null,
                'provider' => 'openai',
                'name' => 'Alloy',
                'language' => 'en',
                'accent' => null,
                'gender_label' => 'Neutral',
                'voice_type' => 'synthetic',
                'is_cloned' => false,
                'provider_voice_key' => 'alloy',
                'status' => 'active',
            ],
            [
                'id' => 0,
                'workspace_id' => null,
                'provider' => 'openai',
                'name' => 'Nova',
                'language' => 'en',
                'accent' => null,
                'gender_label' => 'Female',
                'voice_type' => 'synthetic',
                'is_cloned' => false,
                'provider_voice_key' => 'nova',
                'status' => 'active',
            ],
            [
                'id' => 0,
                'workspace_id' => null,
                'provider' => 'openai',
                'name' => 'Onyx',
                'language' => 'en',
                'accent' => null,
                'gender_label' => 'Male',
                'voice_type' => 'synthetic',
                'is_cloned' => false,
                'provider_voice_key' => 'onyx',
                'status' => 'active',
            ],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:80'],
            'provider_voice_key' => ['required', 'string', 'max:120'],
            'provider'           => ['sometimes', 'string', 'in:openai,elevenlabs,google'],
            'language'           => ['sometimes', 'string', 'max:10'],
            'gender_label'       => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $profile = VoiceProfile::query()->create([
            'workspace_id'       => $user->workspace_id,
            'name'               => $validated['name'],
            'provider'           => $validated['provider'] ?? 'openai',
            'provider_voice_key' => $validated['provider_voice_key'],
            'language'           => $validated['language'] ?? 'en',
            'gender_label'       => $validated['gender_label'] ?? 'Neutral',
            'voice_type'         => 'synthetic',
            'is_cloned'          => false,
            'status'             => 'active',
        ]);

        return response()->json([
            'data' => [
                'voice_profile' => [
                    'id'                 => $profile->getKey(),
                    'workspace_id'       => $profile->workspace_id,
                    'provider'           => $profile->provider,
                    'name'               => $profile->name,
                    'language'           => $profile->language,
                    'accent'             => $profile->accent,
                    'gender_label'       => $profile->gender_label,
                    'voice_type'         => $profile->voice_type,
                    'is_cloned'          => $profile->is_cloned,
                    'provider_voice_key' => $profile->provider_voice_key,
                    'status'             => $profile->status,
                ],
            ],
            'meta' => [],
        ], 201);
    }

    /**
     * Create a cloned voice from an uploaded sample. Chatterbox is zero-shot —
     * there's no training step; we just register the sample as the voice's
     * reference (source_asset_id) and synthesis passes it as audio_prompt.
     * The frontend uploads the sample to POST /assets first, then sends its id.
     */
    public function clone(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:80'],
            'source_asset_id' => ['required', 'integer'],
        ]);

        // Plan gate — the workspace's voice-cloning allowance.
        $usage = app(WorkspaceUsageService::class)->summaryForWorkspace($user->workspace);
        if ((int) ($usage['voice_cloning_used'] ?? 0) >= (int) ($usage['voice_cloning_limit'] ?? 0)) {
            return response()->json([
                'error' => [
                    'code'    => 'voice_cloning_limit',
                    'message' => "Your plan includes {$usage['voice_cloning_limit']} cloned voice(s). Upgrade or remove one to add another.",
                    'context' => ['used' => $usage['voice_cloning_used'], 'limit' => $usage['voice_cloning_limit']],
                ],
            ], 402);
        }

        // The sample must be a workspace-owned audio asset.
        $sample = Asset::query()
            ->whereKey($validated['source_asset_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $sample) {
            return $this->error('invalid_sample', 'Voice sample not found in this workspace.', 422);
        }
        if (! str_starts_with((string) $sample->mime_type, 'audio/') && $sample->asset_type !== 'audio') {
            return $this->error('invalid_sample', 'The voice sample must be an audio file.', 422);
        }

        // Chatterbox requires a WAV reference — convert non-WAV uploads (mp3/m4a)
        // once here so synthesis always passes a valid audio_prompt.
        try {
            $sampleId = $this->ensureWavSampleId($sample, (int) $user->workspace_id, (int) $user->getKey());
        } catch (RuntimeException $e) {
            return $this->error('sample_convert_failed', $e->getMessage(), 422);
        }

        $profile = VoiceProfile::query()->create([
            'workspace_id'       => $user->workspace_id,
            'name'               => $validated['name'],
            'provider'           => 'replicate:chatterbox',
            // Zero-shot: no real voice id. A stable per-sample key so the TTS
            // job can resolve this profile (and its WAV sample) from the scene.
            'provider_voice_key' => 'clone-'.$sampleId,
            'language'           => 'en',
            'gender_label'       => 'Cloned',
            'voice_type'         => 'cloned',
            'is_cloned'          => true,
            'source_asset_id'    => $sampleId,
            'status'             => 'active',
        ]);

        return response()->json([
            'data' => ['voice_profile' => $this->serialize($profile)],
            'meta' => [],
        ], 201);
    }

    /**
     * Ensure the clone reference sample is WAV (Chatterbox requirement). WAV
     * uploads pass through; mp3/m4a/etc. are transcoded once to a clean
     * mono 24kHz WAV and stored as a new asset. Returns the asset id to use.
     */
    private function ensureWavSampleId(Asset $sample, int $workspaceId, int $userId): int
    {
        $isWav = str_contains(strtolower((string) $sample->mime_type), 'wav')
            || str_ends_with(strtolower((string) $sample->storage_url), '.wav');
        if ($isWav) {
            return $sample->getKey();
        }

        $storage = app(StorageService::class);
        $bytes = $storage->get((string) $sample->storage_url);
        if ($bytes === null) {
            $url = $storage->extractPath((string) $sample->storage_url) !== null
                ? $storage->url((string) $sample->storage_url)
                : (string) $sample->storage_url;
            $bytes = @file_get_contents($url) ?: null;
        }
        if (! $bytes) {
            throw new RuntimeException('Could not read the uploaded voice sample.');
        }

        $tmpIn = sys_get_temp_dir().'/clone-in-'.Str::uuid();
        $tmpOut = sys_get_temp_dir().'/clone-out-'.Str::uuid().'.wav';
        file_put_contents($tmpIn, $bytes);

        $result = Process::timeout(120)->run([
            'ffmpeg', '-y', '-i', $tmpIn,
            '-vn', '-acodec', 'pcm_s16le', '-ar', '24000', '-ac', '1',
            $tmpOut,
        ]);
        $wav = file_exists($tmpOut) ? file_get_contents($tmpOut) : false;
        @unlink($tmpIn);
        @unlink($tmpOut);

        if (! $result->successful() || ! $wav) {
            throw new RuntimeException('Could not convert the voice sample to WAV. Try a clean audio file.');
        }

        $storageUrl = $storage->put('audio/clones/'.Str::uuid().'.wav', $wav, ['ContentType' => 'audio/wav']);

        $asset = Asset::query()->create([
            'workspace_id'       => $workspaceId,
            'asset_type'         => 'audio',
            'title'              => 'Voice clone sample (WAV)',
            'storage_url'        => $storageUrl,
            'mime_type'          => 'audio/wav',
            'file_size_bytes'    => strlen($wav),
            'status'             => 'active',
            'created_by_user_id' => $userId,
        ]);

        return $asset->getKey();
    }

    /** Delete a cloned voice (workspace-scoped, cloned voices only). */
    public function destroy(Request $request, int $voiceProfileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profile = VoiceProfile::query()
            ->whereKey($voiceProfileId)
            ->where('workspace_id', $user->workspace_id)
            ->where('is_cloned', true)
            ->first();
        if (! $profile) {
            return $this->error('not_found', 'Cloned voice not found.', 404);
        }

        $profile->delete();

        return response()->json(['data' => ['deleted' => true], 'meta' => []]);
    }

    private function serialize(VoiceProfile $voice): array
    {
        return [
            'id'                 => $voice->getKey(),
            'workspace_id'       => $voice->workspace_id,
            'provider'           => $voice->provider,
            'name'               => $voice->name,
            'language'           => $voice->language,
            'accent'             => $voice->accent,
            'gender_label'       => $voice->gender_label,
            'voice_type'         => $voice->voice_type,
            'is_cloned'          => $voice->is_cloned,
            'provider_voice_key' => $voice->provider_voice_key,
            'status'             => $voice->status,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $voices = VoiceProfile::query()
            ->where('status', 'active')
            ->where(function ($query) use ($user): void {
                $query
                    ->whereNull('workspace_id')
                    ->orWhere('workspace_id', $user->workspace_id);
            })
            ->orderByRaw('workspace_id asc nulls first')
            ->orderBy('name')
            ->get()
            ->map(fn (VoiceProfile $voice): array => [
                'id' => $voice->getKey(),
                'workspace_id' => $voice->workspace_id,
                'provider' => $voice->provider,
                'name' => $voice->name,
                'language' => $voice->language,
                'accent' => $voice->accent,
                'gender_label' => $voice->gender_label,
                'voice_type' => $voice->voice_type,
                'is_cloned' => $voice->is_cloned,
                'provider_voice_key' => $voice->provider_voice_key,
                'status' => $voice->status,
            ])
            ->values()
            ->all();

        if ($voices === []) {
            $voices = $this->fallbackVoices();
        }

        return response()->json([
            'data' => [
                'voice_profiles' => $voices,
            ],
            'meta' => [],
        ]);
    }
}
