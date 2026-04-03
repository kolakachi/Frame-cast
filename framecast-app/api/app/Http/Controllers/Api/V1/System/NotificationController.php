<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = WorkspaceNotification::query()
            ->where('workspace_id', $user->workspace_id)
            ->where(function ($query) use ($user): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $user->getKey());
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'notifications' => $notifications->map(fn (WorkspaceNotification $notification): array => $this->serialize($notification))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notification = WorkspaceNotification::query()
            ->whereKey($notificationId)
            ->where('workspace_id', $user->workspace_id)
            ->where(function ($query) use ($user): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $user->getKey());
            })
            ->first();

        if (! $notification) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Notification not found.',
                ],
            ], 404);
        }

        $notification->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();

        return response()->json([
            'data' => [
                'notification' => $this->serialize($notification->fresh()),
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WorkspaceNotification $notification): array
    {
        return [
            'id' => $notification->getKey(),
            'workspace_id' => $notification->workspace_id,
            'user_id' => $notification->user_id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'payload' => $notification->payload_json,
            'is_read' => $notification->is_read,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
