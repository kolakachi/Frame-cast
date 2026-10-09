<?php

namespace App\Jobs;

use App\Services\Create\Industry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Learns what an existing workspace sells from its videos, once, for the dashboard (D5, 2026-10-09). */
class LearnWorkspaceIndustry implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(public int $workspaceId) {}

    public function handle(Industry $industry): void
    {
        if (DB::table('workspaces')->where('id', $this->workspaceId)->value('industry') !== null) return;
        if ($id = $industry->learn($this->workspaceId)) {
            DB::table('workspaces')->where('id', $this->workspaceId)->whereNull('industry')->update(['industry' => $id, 'industry_source' => 'learned']);
        }
    }
}
