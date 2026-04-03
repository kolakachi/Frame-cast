<?php

namespace App\Events;

use App\Models\WorkspaceNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly WorkspaceNotification $notification,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workspace.'.$this->notification->workspace_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->getKey(),
            'workspace_id' => $this->notification->workspace_id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'payload' => $this->notification->payload_json,
            'is_read' => $this->notification->is_read,
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}
