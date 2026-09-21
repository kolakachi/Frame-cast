<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Reservations and releases share the workspace lock used by the quota check. */
class UgcPassTakeService
{
    public function attach(int $workspaceId, string $requestId, int $projectId): void
    {
        $claim = CreditLedgerEntry::query()->where('workspace_id', $workspaceId)
            ->where('operation', 'ugc_pass_take')->whereNull('project_id')
            ->where('metadata->request_id', $requestId)->orderByDesc('id')->first();
        $claim?->forceFill(['project_id' => $projectId])->save();
    }

    public function releaseProject(int $projectId): void
    {
        // Use the reservation, not the current plan or project: upgrades and
        // deleted projects must not prevent a failed take being returned.
        $claim = CreditLedgerEntry::query()->where('operation', 'ugc_pass_take')
            ->where('project_id', $projectId)->first();
        if ($claim) {
            $this->release((int) $claim->workspace_id, [(int) $claim->id]);
        }
    }

    public function releaseRequest(int $workspaceId, string $requestId): void
    {
        $ids = CreditLedgerEntry::query()->where('workspace_id', $workspaceId)
            ->where('operation', 'ugc_pass_take')->where('metadata->request_id', $requestId)
            ->pluck('id')->all();
        $this->release($workspaceId, $ids);
    }

    private function release(int $workspaceId, array $ids): void
    {
        DB::transaction(function () use ($workspaceId, $ids): void {
            Workspace::whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            foreach ($ids as $id) {
                $claim = CreditLedgerEntry::findOrFail($id);
                $alreadyReleased = CreditLedgerEntry::query()->where('workspace_id', $workspaceId)
                    ->where('operation', 'refund:ugc_pass_take')
                    ->where(function ($query) use ($id, $claim): void {
                        $query->where('metadata->reservation_id', $id);
                        // Honor releases written by the previous implementation.
                        if ($claim->project_id) {
                            $query->orWhere('project_id', $claim->project_id);
                        }
                    })->exists();
                if ($alreadyReleased) {
                    continue;
                }
                CreditLedgerEntry::create([
                    'workspace_id' => $workspaceId, 'project_id' => $claim->project_id,
                    'operation' => 'refund:ugc_pass_take', 'credits' => 0,
                    'balance_after' => app(CreditService::class)->balance($workspaceId),
                    'metadata' => ['reservation_id' => (int) $id, 'request_id' => $claim->metadata['request_id'] ?? null],
                ]);
            }
        });
    }
}
