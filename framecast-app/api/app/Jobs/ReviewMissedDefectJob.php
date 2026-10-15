<?php

namespace App\Jobs;

use App\Services\Create\MissedDefects;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** RD-14: reads one Create message for a fault the checks missed. Never blocks or changes the conversation. */
class ReviewMissedDefectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $workspaceId, public string $conversationId, public string $revisionId, public string $note) {}

    public function handle(MissedDefects $defects): void
    {
        try {
            $defects->review($this->workspaceId, $this->conversationId, $this->revisionId, $this->note);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
