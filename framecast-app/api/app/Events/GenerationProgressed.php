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
     * @return array{project_id:int,stage:string,status:string,message:?string}
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'stage' => $this->stage,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
