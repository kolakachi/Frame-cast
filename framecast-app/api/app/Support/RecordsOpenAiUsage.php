<?php

namespace App\Support;

use App\Services\ApiUsageService;

/**
 * Usage recording for the callers that POST to OpenAI directly.
 *
 * These paths were invisible: no rows, no error rate, nothing to alert on.
 * A configured model that rejected every request therefore degraded output
 * for two weeks without surfacing anywhere. Anything talking to OpenAI
 * outside OpenAIGenerationAdapter should record through this.
 */
trait RecordsOpenAiUsage
{
    /**
     * @param  array<string,mixed>  $usage    the provider's `usage` block, when present
     * @param  float|null  $started           microtime(true) taken before the request
     * @param  array<string,mixed>  $context  extra columns (workspace_id, project_id, ...)
     */
    protected function recordOpenAiUsage(
        string $operation,
        string $model,
        string $status,
        array $usage = [],
        ?float $started = null,
        ?string $error = null,
        array $context = [],
    ): void {
        // Never let telemetry break the thing it is measuring.
        rescue(fn () => app(ApiUsageService::class)->record(array_merge([
            'provider'          => 'openai',
            'service'           => 'text',
            'operation'         => $operation,
            'model'             => $model,
            'status'            => $status,
            'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens'      => (int) ($usage['total_tokens'] ?? 0),
            'error_message'     => $error,
            'metadata_json'     => $started !== null
                ? ['ms' => (int) round((microtime(true) - $started) * 1000)]
                : null,
        ], $context)));
    }
}
