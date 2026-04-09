<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly int $exportJobId,
        public readonly string $status,
        public readonly int $progressPercent,
        public readonly ?string $message = null,
        public readonly ?string $fileName = null,
        public readonly ?string $failureReason = null,
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
        return 'export.progress';
    }

    /**
     * @return array{project_id:int,export_job_id:int,status:string,progress_percent:int,message:?string,file_name:?string,failure_reason:?string}
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'export_job_id' => $this->exportJobId,
            'status' => $this->status,
            'progress_percent' => $this->progressPercent,
            'message' => $this->message,
            'file_name' => $this->fileName,
            'failure_reason' => $this->failureReason,
        ];
    }
}
