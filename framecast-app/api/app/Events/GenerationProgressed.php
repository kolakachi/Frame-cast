<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GenerationProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly string $stage,
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('project.'.$this->projectId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'generation.progress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'stage' => $this->stage,
            'status' => $this->status,
            'message' => $this->message,
            ...$this->meta,
        ];
    }
}
