<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\ApiKey;
use App\Models\ApiQuote;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Developer\OperationAccounting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Turning a quote into permission to spend, shared by every create.
 *
 * Under the workspace row lock: the quote must exist here, be unconsumed
 * (or a replay with the same idempotency key), unexpired and of the right
 * kind; the idempotency key must be fresh; the in-flight cap, the key's
 * monthly cap and the balance must all cover the quoted maximum. Then the
 * quote is marked consumed. Creation happens after the lock is released,
 * and a failed creation hands the quote back.
 */
trait ClaimsQuotes
{
    /**
     * @return array{quote: ApiQuote, replay?: ?Project}|JsonResponse
     */
    protected function claimQuote(string $quoteId, int $workspaceId, string $idempotencyKey, ?int $apiKeyId, string $kind, CreditService $credits, array $expectedTarget = [], ?array $selection = null): array|JsonResponse
    {
        return DB::transaction(function () use ($quoteId, $workspaceId, $idempotencyKey, $apiKeyId, $kind, $credits, $expectedTarget, $selection): array|JsonResponse {
            $workspace = Workspace::findOrFail($workspaceId);
            Workspace::query()->whereIn('id', array_unique([$workspaceId, (int) ($workspace->parent_workspace_id ?: $workspaceId)]))
                ->orderBy('id')->lockForUpdate()->get();

            $quote = ApiQuote::query()->whereKey($quoteId)->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if (! $quote) {
                return $this->fail('quote_not_found', 'No such quote in this workspace. Request a new one.', 404);
            }
            $quoteKind = $quote->payload_json['__kind'] ?? 'video';
            if ($quoteKind !== $kind) {
                return $this->fail('quote_kind_mismatch', "This quote is for a {$quoteKind}; use the matching create.", 409);
            }
            // Validate the frozen target under the lock, before claiming OR replaying.
            // A wrong URL must never release a previously completed proposal.
            foreach ($expectedTarget as $field => $value) {
                if ((int) ($quote->payload_json[$field] ?? 0) !== $value) {
                    return $this->fail('quote_kind_mismatch', 'This quote belongs to a different target.', 409);
                }
            }
            if ($selection !== null) {
                $selection = array_values(array_unique(array_map('intval', $selection)));
                sort($selection);
                if ($quote->consumed_at && ($quote->payload_json['__selection'] ?? null) !== $selection) {
                    return $this->fail('idempotency_payload_mismatch', 'This plan was claimed with a different action selection. Use the original selection.', 409);
                }
            }
            if ($quote->consumed_at) {
                if ($quote->idempotency_key === $idempotencyKey && $quote->project_id) {
                    return ['replay' => Project::query()->whereKey($quote->project_id)->where('workspace_id', $workspaceId)->first(), 'quote' => $quote];
                }

                if ($quote->idempotency_key === $idempotencyKey) {
                    return response()->json(['data' => ['operation' => \App\Services\Developer\OperationStatus::forQuote($quote)], 'meta' => []], 202);
                }

                return $this->fail('quote_consumed', 'This quote has already been claimed. Check its operation status or retry with the original idempotency key; do not start replacement work.', 409);
            }
            if ($quote->isExpired()) {
                return $this->fail('quote_expired', 'This quote has expired. Request a new one and create within '.ApiQuote::TTL_MINUTES.' minutes.', 410);
            }
            if (ApiQuote::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $idempotencyKey)->exists()) {
                return $this->fail('idempotency_key_reused', 'This idempotency key was already used for a different quote.', 409);
            }

            $maxActive = (int) config('developer.limits.max_active_videos');
            $active = Project::query()->where('workspace_id', $workspaceId)->whereNotNull('api_key_id')->where('status', 'generating')->count();
            if ($maxActive > 0 && $active >= $maxActive) {
                return $this->fail('too_many_active_videos',
                    "{$active} API-created videos are already generating in this workspace (limit {$maxActive}). Wait for one to finish.",
                    429, ['active' => $active, 'limit' => $maxActive, 'retry_after_seconds' => 30]);
            }

            $apiKey = $apiKeyId ? ApiKey::query()->find($apiKeyId) : null;
            if ($apiKey && $apiKey->wouldExceedCap($quote->credits_max)) {
                $spent = $apiKey->spentThisMonth();

                return $this->fail('key_spend_cap_reached',
                    "This key may spend {$apiKey->spend_cap_credits} credits a month; {$spent} are spent and this is authorized for up to {$quote->credits_max}.",
                    402, ['spend_cap_credits' => $apiKey->spend_cap_credits, 'spent_this_month' => $spent, 'authorized_max' => $quote->credits_max]);
            }

            $balance = $credits->balance($workspaceId);
            if ($balance < $quote->credits_max) {
                return $this->fail('insufficient_credits',
                    "This may cost up to {$quote->credits_max} credits; the balance is {$balance}.",
                    402, ['balance' => $balance, 'authorized_max' => $quote->credits_max, 'shortage' => $quote->credits_max - $balance]);
            }

            if (OperationAccounting::enabled()) {
                try {
                    OperationAccounting::reserve($quote, $apiKeyId);
                } catch (\DomainException $e) {
                    return $this->fail($e->getMessage(), 'This operation exceeds the available credit or operation allowance, including pending work.',
                        $e->getMessage() === 'too_many_active_videos' ? 429 : 402);
                }
            }

            if ($selection !== null) {
                $quote->payload_json = array_merge($quote->payload_json, ['__selection' => $selection]);
            }
            $quote->payload_json = array_merge($quote->payload_json, ['__claim_token' => bin2hex(random_bytes(16))]);
            $quote->forceFill(['consumed_at' => now(), 'idempotency_key' => $idempotencyKey])->save();

            return ['quote' => $quote];
        });
    }

    /** Checkpoint before an action and after its result; never infer a retry is safe. */
    protected function checkpoint(ApiQuote $quote, array $results, ?int $inProgress): void
    {
        $payload = $quote->payload_json;
        $payload['execution_progress'] = ['applied' => $results, 'in_progress_index' => $inProgress];
        $quote->forceFill(['payload_json' => $payload])->save();
    }

    protected function releaseQuote(ApiQuote $quote): void
    {
        $token = $quote->payload_json['__claim_token'] ?? null;
        if (! $token) return;
        DB::transaction(function () use ($quote, $token) {
            $stored = ApiQuote::query()->whereKey($quote->id)->lockForUpdate()->first();
            if (! $stored || ($stored->payload_json['__claim_token'] ?? null) !== $token
                || $stored->project_id || isset($stored->payload_json['result']) || isset($stored->payload_json['execution_progress'])) return;
            $payload = $stored->payload_json;
            unset($payload['__claim_token']);
            $stored->forceFill(['consumed_at' => null, 'idempotency_key' => null, 'payload_json' => $payload])->save();
        });
    }

    protected function idempotencyKeyFrom(\Illuminate\Http\Request $request, array $input): ?string
    {
        $key = $input['idempotency_key'] ?? $request->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' ? mb_substr(trim($key), 0, 128) : null;
    }
}
