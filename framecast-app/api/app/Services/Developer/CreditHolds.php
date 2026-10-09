<?php

namespace App\Services\Developer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When the credits an operation holds come back (owner, 2026-10-10). One set of rules for Weave runs and API requests:
 *
 *   held          work is running: the quoted maximum is set aside
 *   charged       a delivered piece moves its cost from held to spent (OperationAccounting::debit)
 *   released      the work ended (finished, failed, cancelled, its video deleted): what is left comes back at once
 *   under review  a provider may have done work we cannot confirm: only those pieces' maximum stays held
 *   resolved      review settles it, or 24 hours pass: then the rest comes back and we absorb any provider cost
 *
 * Nothing is held for more than REVIEW_HOURS. Before 2026-10-10 a review held everything left (one refused scene kept
 * 368 credits overnight) and nothing ever expired.
 */
final class CreditHolds
{
    public const REVIEW_HOURS = 24;
    private const LIVE_JOBS = ['pending', 'running', 'released'];

    public static function state(object $op): string
    {
        if ((int) $op->reserved_credits <= 0) return 'released';
        return $op->status === 'needs_attention' ? 'under_review' : 'held';
    }

    /**
     * The most an operation under review can still owe: each unsettled provider call's limit less what it charged.
     * A queued job that may still run can charge up to anything left, so it keeps the whole hold.
     */
    public static function uncertain(object $op): int
    {
        $jobs = DB::table('api_operation_jobs')->where('operation_id', $op->id)->whereIn('status', self::LIVE_JOBS)->pluck('id');
        if ($jobs->contains(fn ($id) => ! str_starts_with((string) $id, 'create-call-'))) return (int) $op->reserved_credits;
        if (! \Illuminate\Support\Facades\Schema::hasTable('composition_attempts')) return 0;
        return (int) DB::table('composition_attempts')->where('operation_id', $op->id)
            ->where(fn ($q) => $q->whereIn('status', ['started', 'unknown'])->orWhereNull('result_hash')->orWhereNull('cost_microusd'))
            ->get(['credit_limit', 'charged_credits'])->sum(fn ($a) => max(0, (int) $a->credit_limit - (int) $a->charged_credits));
    }

    /** Under review: keep only what is uncertain, release the rest now. Returns the credits released. */
    public static function shrink(string $id): int
    {
        return DB::transaction(function () use ($id) {
            $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || $op->status !== 'needs_attention') return 0;
            $keep = min((int) $op->reserved_credits, self::uncertain($op));
            $released = (int) $op->reserved_credits - $keep;
            // With no job left to run, a review no longer counts against the workspace's active videos either.
            $idle = ! DB::table('api_operation_jobs')->where('operation_id', $id)->whereIn('status', self::LIVE_JOBS)
                ->where('id', 'not like', 'create-call-%')->exists();
            $update = ($released > 0 ? ['reserved_credits' => $keep] : []) + ($idle && (int) $op->capacity_slots > 0 ? ['capacity_slots' => 0] : []);
            if ($update) {
                // updated_at is left alone: it dates the last real activity, which the status page reads.
                DB::table('api_operations')->where('id', $id)->update($update);
                if ($released > 0) Log::info('credits.hold_shrunk', ['operation_id' => $id, 'released' => $released, 'kept' => $keep]);
            }
            return $released;
        });
    }

    /**
     * Past REVIEW_HOURS nothing stays held: the user gets the benefit of the doubt and any late provider charge is ours.
     * A review keeps its status for the team, with nothing held; an abandoned running operation (no live job, no
     * live build) is closed as failed. Returns the credits released.
     */
    public static function expire(string $id): int
    {
        return DB::transaction(function () use ($id) {
            $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || (int) $op->reserved_credits <= 0 || now()->subHours(self::REVIEW_HOURS)->lt($op->created_at)) return 0;
            if ($op->status === 'running') {
                if (DB::table('api_operation_jobs')->where('operation_id', $id)->whereIn('status', self::LIVE_JOBS)->exists()) return 0;
                if (\Illuminate\Support\Facades\Schema::hasTable('composition_runs')
                    && DB::table('composition_runs')->where('operation_id', $id)->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists()) return 0;
                DB::table('api_operations')->where('id', $id)->update(['status' => 'failed', 'producer_closed' => true, 'reserved_credits' => 0, 'updated_at' => now()]);
            } else {
                DB::table('api_operations')->where('id', $id)->update(['reserved_credits' => 0, 'capacity_slots' => 0]);
            }
            Log::warning('credits.hold_expired', ['operation_id' => $id, 'released' => (int) $op->reserved_credits, 'status' => $op->status]);
            return (int) $op->reserved_credits;
        });
    }

    /** A deleted video ends its work: its requests are cancelled and their unused holds come back at once. */
    public static function releaseForProject(int $projectId, int $workspaceId): int
    {
        $released = 0;
        // A video request records its project in project_id; a UGC request, one per take, in payload_json.project_ids.
        // The workspace's open requests are few, so they are matched here rather than with a JSON query.
        $ops = DB::table('api_operations')->join('api_quotes', 'api_quotes.id', '=', 'api_operations.quote_id')
            ->where('api_operations.workspace_id', $workspaceId)->whereIn('api_operations.status', ['running', 'needs_attention'])
            ->get(['api_operations.id', 'api_operations.reserved_credits', 'api_quotes.project_id', 'api_quotes.payload_json'])
            ->filter(fn ($op) => (int) $op->project_id === $projectId
                || in_array($projectId, array_map('intval', (array) (json_decode((string) $op->payload_json, true)['project_ids'] ?? [])), true));
        foreach ($ops as $op) {
            if (OperationAccounting::cancel($op->id, $workspaceId)) $released += (int) $op->reserved_credits;
        }
        return $released;
    }

    /** The scheduled pass: shrink every review to what is uncertain, expire what is older than REVIEW_HOURS. */
    public static function sweep(): array
    {
        $shrunk = $expired = [];
        foreach (DB::table('api_operations')->where('status', 'needs_attention')->where(fn ($q) => $q->where('reserved_credits', '>', 0)->orWhere('capacity_slots', '>', 0))->pluck('id') as $id) {
            if ($n = self::shrink($id)) $shrunk[$id] = $n;
        }
        foreach (DB::table('api_operations')->whereIn('status', ['running', 'needs_attention', 'failed', 'completed', 'cancelled'])->where('reserved_credits', '>', 0)
            ->where('created_at', '<', now()->subHours(self::REVIEW_HOURS))->pluck('id') as $id) {
            if ($n = self::expire($id)) $expired[$id] = $n;
        }
        return ['shrunk' => $shrunk, 'expired' => $expired];
    }
}
