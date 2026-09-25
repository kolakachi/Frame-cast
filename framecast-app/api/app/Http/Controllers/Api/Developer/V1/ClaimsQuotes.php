<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\ApiKey;
use App\Models\ApiQuote;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\CreditService;
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
    protected function claimQuote(string $quoteId, int $workspaceId, string $idempotencyKey, ?int $apiKeyId, string $kind, CreditService $credits): array|JsonResponse
    {
        return DB::transaction(function () use ($quoteId, $workspaceId, $idempotencyKey, $apiKeyId, $kind, $credits): array|JsonResponse {
            Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();

            $quote = ApiQuote::query()->whereKey($quoteId)->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if (! $quote) {
                return $this->fail('quote_not_found', 'No such quote in this workspace. Request a new one.', 404);
            }
            $quoteKind = $quote->payload_json['__kind'] ?? 'video';
            if ($quoteKind !== $kind) {
                return $this->fail('quote_kind_mismatch', "This quote is for a {$quoteKind}; use the matching create.", 409);
            }
            if ($quote->consumed_at) {
                if ($quote->idempotency_key === $idempotencyKey && $quote->project_id) {
                    return ['replay' => Project::query()->whereKey($quote->project_id)->where('workspace_id', $workspaceId)->first(), 'quote' => $quote];
                }

                return $this->fail('quote_consumed', 'This quote has already been used. Request a new one.', 409);
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

            $quote->forceFill(['consumed_at' => now(), 'idempotency_key' => $idempotencyKey])->save();

            return ['quote' => $quote];
        });
    }

    protected function releaseQuote(ApiQuote $quote): void
    {
        $quote->forceFill(['consumed_at' => null, 'idempotency_key' => null])->save();
    }

    protected function idempotencyKeyFrom(\Illuminate\Http\Request $request, array $input): ?string
    {
        $key = $input['idempotency_key'] ?? $request->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' ? mb_substr(trim($key), 0, 128) : null;
    }
}
