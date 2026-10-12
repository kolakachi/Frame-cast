<?php

namespace App\Services\Generation\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude straight from Anthropic, for the templates whose model is not on Replicate (owner, 2026-10-12: the UGC
 * director and shot planner on Sonnet 5.5). The call is costed by VendorUsageRecorder like every Anthropic call.
 */
class AnthropicClaudeAdapter implements AIGenerationAdapter
{
    public function __construct(private readonly PromptTemplateRegistry $templates)
    {
    }

    public function generate(string $promptTemplateKey, array $variables, int $maxTokens = 900, float $temperature = 0.4, array $options = []): array
    {
        $template = $this->templates->template($promptTemplateKey);
        $system = $template['system'];
        if (isset($options['system_prefix']) && is_string($options['system_prefix']) && $options['system_prefix'] !== '') {
            $system = $options['system_prefix']."\n\n".$system;
        }
        $key = (string) config('services.anthropic.key');
        if ($key === '') throw new RuntimeException('Anthropic key missing for direct text generation.');
        $model = (string) config('services.ai.direct_model', 'claude-sonnet-5-5');

        // The same wall-clock budget as the Replicate route: an interactive request must fail over, not hang.
        $deadline = min(microtime(true) + 75, (float) ($options['deadline'] ?? INF));
        if ($deadline <= microtime(true)) throw new RuntimeException('Text generation time budget exhausted.');
        $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()
            ->timeout(min(75, max(0.1, $deadline - microtime(true))))
            ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
                'output_config' => ['effort' => (string) config('services.ai.premium_effort', 'medium')],
                'messages' => [['role' => 'user', 'content' => $this->templates->render($template['user'], $variables)]]]);
        if (! $r->successful()) {
            \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status());
            throw new RuntimeException("direct text model failed ({$r->status()}): ".mb_substr($r->body(), 0, 200));
        }

        $content = trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
        if (str_starts_with($content, '```')) $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content));
        if ($content === '' && $r->json('stop_reason') === 'max_tokens') throw new RuntimeException('direct text model ran out of room before answering');

        return [
            'content' => $content,
            'provider_key' => 'anthropic:'.$model,
            'model' => $model,
            'tokens_used' => (int) $r->json('usage.input_tokens', 0) + (int) $r->json('usage.output_tokens', 0),
        ];
    }
}
