<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\User;
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
                'user' => [
                    'id' => $user->getKey(),
                    'workspace_id' => $user->workspace_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                ],
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
}
