<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModerationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin Trust & Safety triage. Lists moderation events with filtering by
 * source / severity / review state, exposes a detail view for the event,
 * and lets an admin mark an event reviewed with an action_taken value.
 */
class AdminModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source'         => ['nullable', 'string', Rule::in([
                ModerationEvent::SOURCE_GENERATION_REJECTION,
                ModerationEvent::SOURCE_USER_REPORT,
                ModerationEvent::SOURCE_PATTERN_ALERT,
                ModerationEvent::SOURCE_ADMIN_ACTION,
            ])],
            'severity'       => ['nullable', 'string', Rule::in([
                ModerationEvent::SEVERITY_INFO,
                ModerationEvent::SEVERITY_LOW,
                ModerationEvent::SEVERITY_MEDIUM,
                ModerationEvent::SEVERITY_HIGH,
                ModerationEvent::SEVERITY_CRITICAL,
            ])],
            'workspace_id'   => ['nullable', 'integer'],
            'unreviewed'     => ['nullable', 'boolean'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ModerationEvent::query()
            ->with(['workspace:id,name,plan_tier', 'user:id,email,name'])
            ->orderByDesc('created_at');

        if (! empty($validated['source'])) {
            $query->where('source', $validated['source']);
        }
        if (! empty($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }
        if (! empty($validated['workspace_id'])) {
            $query->where('workspace_id', $validated['workspace_id']);
        }
        if (filter_var($validated['unreviewed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('reviewed_at');
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        // Counters for the admin tab header.
        $counters = [
            'total_24h'      => ModerationEvent::query()->where('created_at', '>=', now()->subDay())->count(),
            'unreviewed'     => ModerationEvent::query()->whereNull('reviewed_at')->count(),
            'high_severity'  => ModerationEvent::query()->whereIn('severity', [ModerationEvent::SEVERITY_HIGH, ModerationEvent::SEVERITY_CRITICAL])->whereNull('reviewed_at')->count(),
        ];

        return response()->json([
            'data' => [
                'events'   => $paginator->getCollection()->map(fn (ModerationEvent $e) => $this->serialize($e))->all(),
                'counters' => $counters,
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $eventId): JsonResponse
    {
        $event = ModerationEvent::query()
            ->with(['workspace:id,name,plan_tier,status', 'user:id,email,name', 'reviewer:id,email,name'])
            ->whereKey($eventId)
            ->first();

        if (! $event) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Event not found.']], 404);
        }

        // Recent events for the same workspace, for context.
        $relatedEvents = ModerationEvent::query()
            ->where('workspace_id', $event->workspace_id)
            ->where('id', '!=', $event->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ModerationEvent $e) => $this->serialize($e, full: false))
            ->all();

        return response()->json([
            'data' => [
                'event'           => $this->serialize($event, full: true),
                'related_events'  => $relatedEvents,
            ],
        ]);
    }

    public function review(Request $request, int $eventId): JsonResponse
    {
        $event = ModerationEvent::query()->whereKey($eventId)->first();
        if (! $event) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Event not found.']], 404);
        }

        $validated = $request->validate([
            'action_taken' => ['required', 'string', Rule::in([
                ModerationEvent::ACTION_NO_ACTION,
                ModerationEvent::ACTION_WARNING_SENT,
                ModerationEvent::ACTION_CONTENT_REMOVED,
                ModerationEvent::ACTION_FEATURE_SUSPENDED,
                ModerationEvent::ACTION_ACCOUNT_SUSPENDED,
                ModerationEvent::ACTION_WORKSPACE_TERMINATED,
                ModerationEvent::ACTION_REPORTED_TO_AUTHORITIES,
            ])],
            'action_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $event->forceFill([
            'action_taken'        => $validated['action_taken'],
            'action_notes'        => $validated['action_notes'] ?? null,
            'reviewed_at'         => now(),
            'reviewed_by_user_id' => $request->user()?->getKey(),
        ])->save();

        return response()->json(['data' => ['event' => $this->serialize($event->fresh(['workspace', 'user', 'reviewer']), full: true)]]);
    }

    private function serialize(ModerationEvent $e, bool $full = false): array
    {
        $base = [
            'id'         => $e->getKey(),
            'source'     => $e->source,
            'severity'   => $e->severity,
            'operation'  => $e->operation,
            'reason'     => $e->reason,
            'workspace'  => $e->workspace ? ['id' => $e->workspace->id, 'name' => $e->workspace->name, 'plan_tier' => $e->workspace->plan_tier] : null,
            'user'       => $e->user ? ['id' => $e->user->id, 'email' => $e->user->email, 'name' => $e->user->name] : null,
            'created_at' => $e->created_at?->toIso8601String(),
            'reviewed_at'  => $e->reviewed_at?->toIso8601String(),
            'action_taken' => $e->action_taken,
        ];

        if (! $full) {
            return $base;
        }

        return array_merge($base, [
            'project_id'         => $e->project_id,
            'scene_id'           => $e->scene_id,
            'prompt'             => $e->prompt,
            'reference_asset_id' => $e->reference_asset_id,
            'report_email'         => $e->report_email,
            'report_url'           => $e->report_url,
            'report_message'       => $e->report_message,
            'report_violation_type'=> $e->report_violation_type,
            'metadata'           => $e->metadata,
            'action_notes'       => $e->action_notes,
            'reviewer'           => $e->reviewer ? ['id' => $e->reviewer->id, 'email' => $e->reviewer->email, 'name' => $e->reviewer->name] : null,
        ]);
    }
}
