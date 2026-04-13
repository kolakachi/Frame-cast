<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function __construct(private readonly WorkspaceUsageService $usageService) {}

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => $this->serializeUser($user),
                'usage' => $this->usageService->summaryForUser($user),
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
            'preferences' => ['sometimes', 'array'],
            'preferences.auto_generate_captions' => ['sometimes', 'boolean'],
            'preferences.preview_before_render' => ['sometimes', 'boolean'],
            'preferences.auto_music' => ['sometimes', 'boolean'],
            'preferences.watermark_enabled' => ['sometimes', 'boolean'],
        ]);

        $user->fill(collect($validated)->except('preferences')->all());

        if (array_key_exists('preferences', $validated)) {
            $user->preferences_json = array_merge(
                $this->defaultPreferences(),
                $user->preferences_json ?? [],
                $validated['preferences'],
            );
        }

        $user->save();

        return response()->json([
            'data' => [
                'user' => $this->serializeUser($user->fresh()),
                'usage' => $this->usageService->summaryForUser($user),
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
     * @return array{id:int,workspace_id:?int,name:string,email:string,timezone:string,role:string,status:string,preferences:array<string,bool>}
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
            'preferences' => array_merge($this->defaultPreferences(), $user->preferences_json ?? []),
        ];
    }

    /**
     * @return array{auto_generate_captions:bool,preview_before_render:bool,auto_music:bool,watermark_enabled:bool}
     */
    private function defaultPreferences(): array
    {
        return [
            'auto_generate_captions' => true,
            'preview_before_render' => true,
            'auto_music' => true,
            'watermark_enabled' => false,
        ];
    }

}
