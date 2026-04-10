<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Channel;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\VoiceProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => $this->serializeUser($user),
                'usage' => $this->usageSummary($user),
            ],
            'meta' => [],
        ]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);

        $user->fill($validated)->save();

        return response()->json([
            'data' => [
                'user' => $this->serializeUser($user->fresh()),
                'usage' => $this->usageSummary($user),
            ],
            'meta' => [],
        ]);
    }

    public function storageSmoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $disk = Storage::disk('b2');
        $path = sprintf(
            'phase-0-smoke/%s/%s.txt',
            $user->workspace_id,
            Str::uuid()->toString(),
        );

        $contents = json_encode([
            'type' => 'phase_0_storage_smoke',
            'user_id' => $user->getKey(),
            'workspace_id' => $user->workspace_id,
            'generated_at' => Carbon::now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put($path, $contents, [
            'ContentType' => 'text/plain',
        ]);

        $temporaryUrl = $disk->temporaryUrl($path, now()->addMinutes(10));

        return response()->json([
            'data' => [
                'disk' => 'b2',
                'path' => $path,
                'temporary_url' => $temporaryUrl,
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return array{id:int,workspace_id:?int,name:string,email:string,timezone:string,role:string,status:string}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'workspace_id' => $user->workspace_id,
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'role' => $user->role,
            'status' => $user->status,
        ];
    }

    /**
     * @return array{
     *     plan:string,
     *     renders_used:int,
     *     render_limit:int,
     *     voice_minutes_used:int,
     *     voice_minutes_limit:int,
     *     dub_languages_used:int,
     *     dub_languages_limit:int,
     *     active_channels:int,
     *     channel_limit:int,
     *     voice_cloning_used:int,
     *     voice_cloning_limit:int,
     *     assets:int,
     *     brand_kits:int,
     *     projects:int
     * }
     */
    private function usageSummary(User $user): array
    {
        $workspaceId = $user->workspace_id;
        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->sum('duration_seconds');

        $dubLanguagesUsed = Project::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('primary_language')
            ->distinct('primary_language')
            ->count('primary_language');

        return [
            'plan' => 'Studio',
            'renders_used' => ExportJob::query()->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))->where('status', 'completed')->count(),
            'render_limit' => 200,
            'voice_minutes_used' => (int) ceil($voiceSeconds / 60),
            'voice_minutes_limit' => 120,
            'dub_languages_used' => $dubLanguagesUsed,
            'dub_languages_limit' => 3,
            'active_channels' => Channel::query()->where('workspace_id', $workspaceId)->where('status', 'active')->count(),
            'channel_limit' => 5,
            'voice_cloning_used' => VoiceProfile::query()->where('workspace_id', $workspaceId)->where('is_cloned', true)->count(),
            'voice_cloning_limit' => 2,
            'assets' => Asset::query()->where('workspace_id', $workspaceId)->where('status', 'active')->count(),
            'brand_kits' => BrandKit::query()->where('workspace_id', $workspaceId)->count(),
            'projects' => Project::query()->where('workspace_id', $workspaceId)->count(),
        ];
    }
}
