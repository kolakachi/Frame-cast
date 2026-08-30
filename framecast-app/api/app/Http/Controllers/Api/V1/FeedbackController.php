<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * In-app frustration feedback — the box the rageclick prompt submits to.
 *
 * Exists because silent churners don't email support: a refunded customer
 * rageclicked the editor for 40 minutes with the chat widget on screen and
 * never used it. Interrupting AT the moment of frustration converts silent
 * refunders into reporters. Lands in the admin Trust & Safety inbox (the one
 * with the badge) and mails hello@ immediately.
 */
class FeedbackController extends Controller
{
    public function store(Request $request, ModerationService $moderation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'page'    => ['nullable', 'string', 'max:300'],
            'trigger' => ['nullable', 'string', 'max:40'],   // 'rageclick' | 'manual'
        ]);

        // One report per user per 2 minutes — enough for a correction
        // ("actually it's the export button"), never a spam channel.
        if (! Cache::add('feedback:'.$user->getKey(), 1, now()->addMinutes(2))) {
            return response()->json(['data' => ['received' => true]]);
        }

        $moderation->recordUserReport([
            'workspace_id'   => $user->workspace_id,
            'user_id'        => $user->getKey(),
            'email'          => $user->email,
            'url'            => $validated['page'] ?? null,
            'message'        => $validated['message'],
            'violation_type' => 'frustration_feedback',
        ]);

        rescue(fn () => Mail::raw(
            "In-app feedback ({$validated['trigger']}) from {$user->email}\n"
            ."Page: ".($validated['page'] ?? '?')."\n\n"
            .$validated['message'],
            fn ($m) => $m->to(config('moderation.digest_email'))
                ->subject('🖐 In-app feedback: '.mb_substr($validated['message'], 0, 60)),
        ), report: false);

        return response()->json(['data' => ['received' => true]]);
    }
}
