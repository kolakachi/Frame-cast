<?php

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DailyStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyStreakController extends Controller
{
    public function __construct(private DailyStreakService $streak)
    {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->whereKey($user->workspace_id)->first();
        if (! $workspace) {
            return response()->json(['error' => ['code' => 'no_workspace', 'message' => 'No workspace.']], 422);
        }
        return response()->json([
            'data' => $this->streak->state($workspace),
            'meta' => [],
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->whereKey($user->workspace_id)->first();
        if (! $workspace) {
            return response()->json(['error' => ['code' => 'no_workspace', 'message' => 'No workspace.']], 422);
        }

        $result = $this->streak->claim($workspace);

        if ($result['already_claimed']) {
            return response()->json([
                'error' => [
                    'code'    => 'already_claimed',
                    'message' => 'You already claimed today. Come back tomorrow.',
                ],
            ], 409);
        }

        return response()->json([
            'data' => array_merge(
                $result,
                $this->streak->state($workspace->refresh()),
            ),
            'meta' => [],
        ]);
    }
}
