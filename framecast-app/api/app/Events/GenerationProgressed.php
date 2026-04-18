<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Schema;
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
        $this->recordProgress();
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

    private function recordProgress(): void
    {
        rescue(function (): void {
            if (! Schema::hasColumn('projects', 'generation_status_json')) {
                return;
            }

            $project = Project::query()->find($this->projectId);

            if (! $project) {
                return;
            }

            $status = is_array($project->generation_status_json)
                ? $project->generation_status_json
                : [];
            $stages = is_array($status['stages'] ?? null) ? $status['stages'] : [];

            $stages[$this->stage] = [
                'status' => $this->status,
                'message' => $this->message,
                'updated_at' => now()->toIso8601String(),
            ];

            $project->forceFill([
                'generation_status_json' => [
                    ...$status,
                    'current_stage' => $this->stage,
                    'current_status' => $this->status,
                    'last_message' => $this->message,
                    'stages' => $stages,
                ],
            ])->save();
        });
    }
}
