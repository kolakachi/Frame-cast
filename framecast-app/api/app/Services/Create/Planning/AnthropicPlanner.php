<?php
namespace App\Services\Create\Planning;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One planning call to the Claude API directly, for models not hosted on
 * Replicate (Opus 5.5). The long system prompt is marked cacheable, so
 * repeated plans pay the cached-read rate for it.
 */
class AnthropicPlanner implements Planner
{
    public function __construct(private string $model, private string $key) {}

    public function plan(array $context): array
    {
        $response = Http::withHeaders(['x-api-key' => $this->key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model, 'max_tokens' => 3000,
                'system' => [['type' => 'text', 'text' => PlanPrompt::system(), 'cache_control' => ['type' => 'ephemeral']]],
                'messages' => [['role' => 'user', 'content' => PlanPrompt::user($context)]],
            ]);
        if (! $response->successful()) throw new RuntimeException('Planner request failed.');
        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $plan = PlanPrompt::extract($text);
        if (! $plan) throw new RuntimeException('Planner returned no plan.');
        $u = $response->json('usage', []);
        return ['plan' => $plan, 'provider' => 'anthropic:'.$this->model, 'usage' => ['message_id' => $response->json('id'),
            'input_tokens' => $u['input_tokens'] ?? null, 'output_tokens' => $u['output_tokens'] ?? null,
            'cache_read_tokens' => $u['cache_read_input_tokens'] ?? null, 'cache_write_tokens' => $u['cache_creation_input_tokens'] ?? null]];
    }
}
