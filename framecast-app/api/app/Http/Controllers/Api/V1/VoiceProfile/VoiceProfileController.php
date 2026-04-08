<?php

namespace App\Http\Controllers\Api\V1\VoiceProfile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VoiceProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
