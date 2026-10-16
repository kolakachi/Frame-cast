<?php

namespace App\Services\Create;

use App\Services\CreditService;
use Illuminate\Support\Facades\DB;

/**
 * CHECK-GUARD (2026-10-16): what a build disputed or kept failing on, kept for review. Marking one wrong refunds the
 * failed build's own work (the builder's calls); media it bought stays in the conversation and is reused on a retry.
 */
class CheckDisputes
{
    /** From the worker's result: check_disputes when a version was delivered, check_repeats when the build failed. */
    public static function record(object $run, string $status, array $result, ?string $revisionId): int
    {
        $rows = match ($status) { 'preview_ready' => $result['check_disputes'] ?? [], 'failed' => $result['check_repeats'] ?? [], default => [] };
        if (! is_array($rows)) return 0;
        $n = 0;
        foreach (array_slice($rows, 0, 8) as $d) {
            if (! is_array($d) || ! preg_match('/^[a-z_]{2,48}$/D', (string) ($d['code'] ?? ''))) continue;
            $seen = array_values(array_filter((array) ($d['seen_at'] ?? []), 'is_int'));
            DB::table('create_check_disputes')->insert([
                'workspace_id' => $run->workspace_id, 'conversation_id' => $run->conversation_id, 'run_id' => $run->id, 'revision_id' => $revisionId,
                'outcome' => $status === 'failed' ? 'failed' : 'delivered', 'code' => $d['code'],
                'selector' => isset($d['selector']) ? mb_substr((string) $d['selector'], 0, 160) : null,
                'message' => isset($d['message']) ? mb_substr((string) $d['message'], 0, 600) : null,
                'seen_at' => json_encode(array_slice($seen, 0, 8)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $n++;
        }
        return $n;
    }

    /** What the failed build itself charged: its builder calls, not the media kept for reuse, less any earlier refund. */
    public static function refundable(string $runId): int
    {
        $spent = (int) DB::table('credit_ledger')->where('metadata->composition_run_id', $runId)->where('operation', '!=', 'create_plan_media')->where('credits', '>', 0)->sum('credits');
        $back = (int) -DB::table('credit_ledger')->where('operation', 'refund:check_wrong:'.$runId)->sum('credits');
        return max(0, $spent - $back);
    }

    /** wrong: the check was mistaken (a failed build is refunded); right: the check was correct. */
    public static function decide(int $id, string $verdict): ?array
    {
        abort_unless(in_array($verdict, ['wrong', 'right'], true), 422);
        return DB::transaction(function () use ($id, $verdict) {
            $d = DB::table('create_check_disputes')->where('id', $id)->lockForUpdate()->first();
            if (! $d) return null;
            $refund = 0;
            if ($verdict === 'wrong' && $d->outcome === 'failed' && DB::table('composition_runs')->where('id', $d->run_id)->value('status') === 'failed') {
                $refund = self::refundable($d->run_id);
                if ($refund > 0) app(CreditService::class)->refund((int) $d->workspace_id, $refund, 'check_wrong:'.$d->run_id);
            }
            DB::table('create_check_disputes')->where('id', $id)->update(['verdict' => $verdict, 'refunded_credits' => $d->refunded_credits + $refund, 'decided_at' => now(), 'updated_at' => now()]);
            return ['id' => $id, 'verdict' => $verdict, 'refunded' => $refund, 'workspace_id' => (int) $d->workspace_id];
        });
    }
}
