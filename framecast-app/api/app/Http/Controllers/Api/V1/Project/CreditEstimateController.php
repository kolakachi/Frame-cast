<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditEstimateController extends Controller
{
    public function __construct(private readonly CreditService $credits) {}

    public function estimate(Request $request): JsonResponse
    {
        $request->validate([
            'source_type'          => 'required|string',
            'source_content_raw'   => 'nullable|string|max:10000',
            'visual_generation_mode' => 'required|string',
            'ai_image_quality'     => 'nullable|string|in:low,medium,high',
            'duration_target_seconds' => 'nullable|integer|min:5|max:600',
            'animate_tier'         => 'nullable|string|in:quick,balanced,premium,seedance_lite,seedance_pro,veo_fast,seedance_25',
            'animate_quality'      => 'nullable|string|max:16',
            'animation_pacing' => 'nullable|string|in:short,long',
            'voice_id'             => 'nullable|string|max:255',
            'character_id'         => 'nullable|integer',
        ]);

        /** @var User $user */
        $user = $request->user();

        $estimate = $this->credits->estimateProject(
            sourceType:    $request->string('source_type')->value(),
            sourceContent: $request->input('source_content_raw'),
            visualMode:    $request->string('visual_generation_mode')->value(),
            aiQuality:     $request->input('ai_image_quality', 'medium'),
            durationSeconds: (int) $request->input('duration_target_seconds', 60),
            animateTier:   $request->input('animate_tier'),
            animateQuality: $request->input('animate_quality'),
            animationPacing: $request->input('animation_pacing'),
            voiceId:       $request->input('voice_id'),
            usesCharacter: $request->filled('character_id'),
        );

        $balance    = $this->credits->balance((int) $user->workspace_id);
        $canAfford  = $balance >= $estimate['credits_min'];

        return response()->json([
            'data' => [
                ...$estimate,
                'workspace_balance' => $balance,
                'can_afford'        => $canAfford,
                'shortage'          => $canAfford ? 0 : $estimate['credits_min'] - $balance,
            ],
        ]);
    }
}
