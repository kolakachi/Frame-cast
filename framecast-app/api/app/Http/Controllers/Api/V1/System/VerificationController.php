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
        ]);

        $user->fill($validated)->save();

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

}
