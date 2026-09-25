<?php

namespace App\Services\Developer;

use App\Models\ApiQuote;
use Illuminate\Support\Facades\DB;

/** Read-only recovery information. Elapsed time never releases a hold. */
class OperationStatus
{
    public static function forQuote(ApiQuote $quote): array
    {
        $f = $quote->payload_json;
        $op = OperationAccounting::enabled()
            ? DB::table('api_operations')->where('quote_id', $quote->id)->where('workspace_id', $quote->workspace_id)->latest('created_at')->first() : null;
        $kind = $f['__kind'] ?? 'video';
        $resultRecorded = match ($kind) {
            'edit', 'assistant_plan' => isset($f['result']),
            'ugc' => isset($f['project_ids']),
            'character_image' => isset($f['generation_id']),
            default => $quote->project_id !== null,
        };
        $lastActivity = $quote->updated_at;
        if ($op && $op->updated_at > $lastActivity->toDateTimeString()) {
            $lastActivity = \Illuminate\Support\Carbon::parse($op->updated_at);
        }
        $old = $lastActivity->lt(now()->subMinutes((int) config('developer.recovery_attention_minutes', 30)));
        $state = ! $quote->consumed_at ? 'not_started'
            : (($op && in_array($op->status, ['failed', 'needs_attention'], true)) ? 'needs_attention'
                : (($old && (! $resultRecorded || ($op && $op->status === 'running'))) ? 'needs_attention'
                    : ($op ? ($op->status === 'running' ? 'running' : ($resultRecorded ? 'settled' : 'needs_attention'))
                        : ($resultRecorded ? 'accepted' : 'running'))));

        if ($op?->status === 'cancelled') $state = 'cancelled';

        return [
            'quote_id' => $quote->id, 'operation_id' => $op?->id,
            'kind' => $kind, 'state' => $state,
            'result_recorded' => $resultRecorded,
            // These describe the API execution, not whether a generated video is ready.
            'result' => $f['result'] ?? null,
            'progress' => $f['execution_progress'] ?? null,
            'project_ids' => $kind === 'character_image' ? [] : ($f['project_ids'] ?? ($quote->project_id ? [(int) $quote->project_id] : [])),
            'generation_id' => $kind === 'character_image' ? ($f['generation_id'] ?? null) : null,
            'credits' => $op ? ['authorized_max' => (int) $op->authorized_credits,
                'spent' => (int) $op->spent_credits, 'reserved' => (int) $op->reserved_credits] : null,
            'jobs_pending' => $op ? DB::table('api_operation_jobs')->where('operation_id', $op->id)->whereIn('status', ['pending', 'running', 'released'])->count() : null,
            'retry_after_seconds' => $state === 'running' ? 15 : null,
            'next' => match ($state) {
                'cancelled' => 'Remaining work is fenced off and its unused reservation released. Completed charges/results are retained. Review them before authorizing new work.',
                'not_started' => 'The quote has not been consumed. Retry the original request with its original idempotency key; expiry rules still apply.',
                'needs_attention' => 'Execution needs investigation. Do not submit a replacement paid operation. Reservations have not been released merely because time elapsed.',
                'running' => 'Poll this quote again. Do not obtain a replacement quote or change the idempotency key.',
                default => 'Use the recorded result or replay the original request with the same idempotency key. Poll video/generation status separately for media readiness.',
            },
        ];
    }
}
