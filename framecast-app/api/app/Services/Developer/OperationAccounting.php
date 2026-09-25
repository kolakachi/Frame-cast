<?php

namespace App\Services\Developer;

use App\Models\ApiKey;
use App\Models\ApiQuote;
use App\Models\Workspace;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable holds; no balance movement until CreditService records a debit. */
class OperationAccounting
{
    public const CONTEXT = 'wyv_api_operation';

    public static function enabled(): bool
    {
        return (bool) config('developer.operation_accounting', false);
    }

    public static function current(): ?string
    {
        return self::enabled() ? Context::getHidden(self::CONTEXT) : null;
    }

    /** Caller holds the spending workspace and credit pool locks. */
    public static function reserve(ApiQuote $quote, ?int $keyId): string
    {
        $workspace = Workspace::findOrFail($quote->workspace_id);
        $poolId = $workspace->creditRootId();
        $pool = Workspace::findOrFail($poolId);
        $reserved = self::reserved($poolId);
        if ($pool->creditsBalance() - $reserved < $quote->credits_max) {
            throw new \DomainException('insufficient_credits');
        }
        $key = $keyId ? ApiKey::findOrFail($keyId) : null;
        if ($key && $key->wouldExceedCap($quote->credits_max)) {
            throw new \DomainException('key_spend_cap_reached');
        }
        $active = DB::table('api_operations')->where('workspace_id', $workspace->id)->whereIn('status', ['running', 'needs_attention'])->sum('capacity_slots');
        $slots = max(1, (int) data_get($quote->payload_json, 'pricing.takes', 1));
        $limit = (int) config('developer.limits.max_active_videos');
        if ($limit > 0 && $active + $slots > $limit) {
            throw new \DomainException('too_many_active_videos');
        }
        $id = 'op_'.strtolower((string) Str::ulid());
        DB::table('api_operations')->insert([
            'id' => $id, 'quote_id' => $quote->id, 'workspace_id' => $workspace->id,
            'pool_workspace_id' => $poolId, 'api_key_id' => $keyId,
            'capacity_slots' => $slots, 'authorized_credits' => $quote->credits_max, 'reserved_credits' => $quote->credits_max,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! OperationFence::acquire($id)) throw new \DomainException('operation_busy');
        Context::addHidden(self::CONTEXT, $id);

        return $id;
    }

    public static function reserved(int $poolId, ?string $except = null): int
    {
        return (int) DB::table('api_operations')->where('pool_workspace_id', $poolId)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->sum('reserved_credits');
    }

    /** Inside the balance transaction, after pool locks. Returns attribution. */
    public static function debit(int $workspaceId, int $poolId, int $amount): array|false
    {
        $id = self::current();
        $pool = Workspace::findOrFail($poolId);
        if ($pool->creditsBalance() - self::reserved($poolId, $id) < $amount) {
            return false;
        }
        if (! $id) {
            return [];
        }
        $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
        if (! $op || $op->status !== 'running' || (int) $op->workspace_id !== $workspaceId
            || (int) $op->pool_workspace_id !== $poolId || $op->spent_credits + $amount > $op->authorized_credits) {
            return false;
        }
        DB::table('api_operations')->where('id', $id)->update([
            'spent_credits' => $op->spent_credits + $amount,
            'reserved_credits' => max(0, $op->reserved_credits - $amount), 'updated_at' => now(),
        ]);

        return ['api_operation_id' => $id, 'api_key_id' => $op->api_key_id];
    }

    /** Refund inside the same pool-locked transaction as balance and ledger. */
    public static function refund(int $workspaceId, int $amount): array
    {
        $id = self::current();
        if (! $id) {
            return [];
        }
        $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
        if (! $op || (int) $op->workspace_id !== $workspaceId || $amount > $op->spent_credits) {
            throw new \DomainException('Invalid API operation refund.');
        }
        DB::table('api_operations')->where('id', $id)->update([
            'spent_credits' => $op->spent_credits - $amount,
            'reserved_credits' => in_array($op->status, ['running', 'needs_attention'], true) ? $op->reserved_credits + $amount : 0,
            'updated_at' => now(),
        ]);

        return ['api_operation_id' => $id, 'api_key_id' => $op->api_key_id];
    }

    public static function queued(string $id, string $uuid): void
    {
        DB::transaction(function () use ($id, $uuid) {
            $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || $op->status !== 'running') {
                throw new \DomainException('Cannot dispatch work against a settled operation.');
            }
            DB::table('api_operation_jobs')->insertOrIgnore([
                'id' => $uuid, 'operation_id' => $id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** An explicit cancellation fences out old workers before releasing holds. */
    public static function cancel(string $id, int $workspaceId): bool
    {
        if (! OperationFence::acquire($id, true)) return false;
        try {
            return DB::transaction(function () use ($id, $workspaceId) {
                $op = DB::table('api_operations')->where('id', $id)->where('workspace_id', $workspaceId)->lockForUpdate()->first();
                if (! $op) return false;
                if (in_array($op->status, ['completed', 'failed', 'cancelled'], true)) return true;
                DB::table('api_operations')->where('id', $id)->update([
                    'status' => 'cancelled', 'producer_closed' => true, 'reserved_credits' => 0, 'updated_at' => now(),
                ]);
                DB::table('api_operation_jobs')->where('operation_id', $id)->whereIn('status', ['pending', 'running', 'released'])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                return true;
            });
        } finally {
            OperationFence::release($id, true);
        }
    }

    public static function close(string $id, ?string $jobId = null, bool $failed = false): void
    {
        DB::transaction(function () use ($id, $jobId, $failed) {
            $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || $op->status !== 'running') {
                return;
            }
            if ($jobId) {
                DB::table('api_operation_jobs')->where('id', $jobId)->where('operation_id', $id)
                    ->whereIn('status', ['pending', 'running', 'released'])->update(['status' => $failed ? 'failed' : 'completed', 'updated_at' => now()]);
            } else {
                DB::table('api_operations')->where('id', $id)->update(['producer_closed' => true]);
                $op->producer_closed = true;
            }
            if ($op->producer_closed && ! DB::table('api_operation_jobs')->where('operation_id', $id)->whereIn('status', ['pending', 'running', 'released'])->exists()) {
                $hasFailure = DB::table('api_operation_jobs')->where('operation_id', $id)->where('status', 'failed')->exists();
                DB::table('api_operations')->where('id', $id)->update([
                    'status' => $hasFailure ? 'failed' : 'completed', 'reserved_credits' => 0, 'updated_at' => now(),
                ]);
            }
        });
    }
}
