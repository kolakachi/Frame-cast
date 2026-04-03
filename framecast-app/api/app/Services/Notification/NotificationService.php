<?php

namespace App\Services\Notification;

use App\Events\NotificationCreated;
use App\Models\WorkspaceNotification;

class NotificationService
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function create(
        int $workspaceId,
        string $title,
        string $message,
        string $type = 'info',
        ?int $userId = null,
        ?array $payload = null,
    ): WorkspaceNotification {
        $notification = WorkspaceNotification::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'payload_json' => $payload,
            'is_read' => false,
        ]);

        NotificationCreated::dispatch($notification);

        return $notification;
    }
}
