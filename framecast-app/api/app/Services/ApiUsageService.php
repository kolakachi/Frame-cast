<?php

namespace App\Services;

use App\Jobs\CheckBudgetAlertJob;
use App\Models\ApiUsageEvent;
use Illuminate\Support\Arr;

class ApiUsageService
{
    private const TEXT_PRICING_PER_MILLION = [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o' => ['input' => 5.00, 'output' => 15.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
    ];

    private const TTS_PRICING_PER_MILLION_CHARS = [
        'tts-1' => 15.00,
        'tts-1-hd' => 30.00,
        'gpt-4o-mini-tts' => 0.60,
    ];

    private const IMAGE_PRICING = [
        'dall-e-3:standard:1024x1024' => 0.040,
        'dall-e-3:standard:1024x1792' => 0.080,
        'dall-e-3:standard:1792x1024' => 0.080,
        'dall-e-3:hd:1024x1024' => 0.080,
        'dall-e-3:hd:1024x1792' => 0.120,
        'dall-e-3:hd:1792x1024' => 0.120,
    ];

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): ?ApiUsageEvent
    {
        $event = rescue(fn () => ApiUsageEvent::query()->create([
            'workspace_id' => $this->nullableInt($data['workspace_id'] ?? null),
            'project_id' => $this->nullableInt($data['project_id'] ?? null),
            'user_id' => $this->nullableInt($data['user_id'] ?? null),
            'provider' => (string) ($data['provider'] ?? 'unknown'),
            'service' => (string) ($data['service'] ?? 'unknown'),
            'operation' => $data['operation'] ?? null,
            'model' => $data['model'] ?? null,
            'status' => (string) ($data['status'] ?? 'succeeded'),
            'prompt_tokens' => max(0, (int) ($data['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int) ($data['completion_tokens'] ?? 0)),
            'total_tokens' => max(0, (int) ($data['total_tokens'] ?? 0)),
            'units' => max(0, (int) ($data['units'] ?? 0)),
            'estimated_cost_usd' => round((float) ($data['estimated_cost_usd'] ?? 0), 6),
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'metadata_json' => $data['metadata_json'] ?? null,
            'occurred_at' => now(),
        ]), null, false);

        $workspaceId = $this->nullableInt($data['workspace_id'] ?? null);
        if ($event && $workspaceId) {
            CheckBudgetAlertJob::dispatch($workspaceId);
        }

        return $event;
    }

    public function estimateTextCost(string $model, int $promptTokens, int $completionTokens, int $totalTokens = 0): float
    {
        $pricing = self::TEXT_PRICING_PER_MILLION[$model]
            ?? ['input' => 0.15, 'output' => 0.60];

        if ($promptTokens <= 0 && $completionTokens <= 0 && $totalTokens > 0) {
            $promptTokens = $totalTokens;
        }

        return (($promptTokens / 1_000_000) * $pricing['input'])
            + (($completionTokens / 1_000_000) * $pricing['output']);
    }

    public function estimateTtsCost(string $model, string $text): float
    {
        $price = self::TTS_PRICING_PER_MILLION_CHARS[$model] ?? 15.00;

        return (mb_strlen($text) / 1_000_000) * $price;
    }

    public function estimateImageCost(string $model, string $quality, string $size): float
    {
        $key = "{$model}:{$quality}:{$size}";

        return self::IMAGE_PRICING[$key] ?? 0.080;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{workspace_id:?int,project_id:?int,user_id:?int,metadata_json:array<string,mixed>}
     */
    public function contextFromOptions(array $options): array
    {
        $context = is_array($options['usage_context'] ?? null) ? $options['usage_context'] : [];

        return [
            'workspace_id' => $this->nullableInt($context['workspace_id'] ?? null),
            'project_id' => $this->nullableInt($context['project_id'] ?? null),
            'user_id' => $this->nullableInt($context['user_id'] ?? null),
            'metadata_json' => Arr::except($context, ['workspace_id', 'project_id', 'user_id']),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
