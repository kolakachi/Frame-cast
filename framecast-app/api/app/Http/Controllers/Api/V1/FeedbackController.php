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
    /**
     * Post-export rating — the one-time "how was that?" modal.
     *
     * Structured (rating + picked aspects + optional comment) so it can be
     * aggregated, unlike the freeform frustration box. Submitting OR
     * dismissing marks preferences.export_feedback_done server-side, in the
     * same request, so the modal shows exactly once per user.
     */
    public function rate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'rating'        => ['nullable', 'integer', 'min:1', 'max:5'],
            'options'       => ['nullable', 'array', 'max:8'],
            'options.*'     => ['string', 'max:60'],
            'comment'       => ['nullable', 'string', 'max:2000'],
            'project_id'    => ['nullable', 'integer'],
            'export_job_id' => ['nullable', 'integer'],
            'dismissed'     => ['sometimes', 'boolean'],
        ]);

        $dismissed = (bool) ($validated['dismissed'] ?? false);

        if (! $dismissed) {
            \App\Models\ProductFeedback::create([
                'workspace_id'  => $user->workspace_id,
                'user_id'       => $user->getKey(),
                'rating'        => $validated['rating'] ?? null,
                'options'       => $validated['options'] ?? null,
                'comment'       => $validated['comment'] ?? null,
                'trigger'       => 'export',
                'project_id'    => $validated['project_id'] ?? null,
                'export_job_id' => $validated['export_job_id'] ?? null,
            ]);

            \App\Services\Analytics\PostHogService::capture(
                $user->getKey(),
                'export_feedback_given',
                array_filter([
                    'rating'  => $validated['rating'] ?? null,
                    'options' => $validated['options'] ?? null,
                ]),
                $user->workspace_id,
            );

            rescue(fn () => Mail::raw(
                "Export feedback from {$user->email}
"
                .'Rating: '.($validated['rating'] ?? '—')."/5
"
                .'Aspects: '.implode(', ', $validated['options'] ?? [])."

"
                .($validated['comment'] ?? '(no comment)'),
                fn ($m) => $m->to(config('moderation.digest_email'))
                    ->subject('⭐ Export feedback: '.($validated['rating'] ?? '—').'/5 from '.$user->email),
            ), report: false);
        }

        // Once ever — dismissal counts as answered.
        $user->forceFill([
            'preferences_json' => array_merge($user->preferences_json ?? [], ['export_feedback_done' => true]),
        ])->save();

        return response()->json(['data' => ['received' => ! $dismissed]]);
    }

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
