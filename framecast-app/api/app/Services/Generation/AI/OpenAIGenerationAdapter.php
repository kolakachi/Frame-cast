<?php

namespace App\Services\Generation\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIGenerationAdapter implements AIGenerationAdapter
{
    public function __construct(
        private readonly PromptTemplateRegistry $templates,
    ) {
    }

    public function generate(string $promptTemplateKey, array $variables, int $maxTokens = 900, float $temperature = 0.4): array
    {
        $template = $this->templates->template($promptTemplateKey);
        $systemPrompt = $template['system'];
        $userPrompt = $this->templates->render($template['user'], $variables);

        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        if ($apiKey === '') {
            return [
                'content' => $this->fallbackScript($variables),
                'provider_key' => 'openai',
                'model' => $model,
                'tokens_used' => 0,
            ];
        }

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('OpenAI generation request failed.');
        }

        $json = $response->json();
        $content = trim((string) data_get($json, 'choices.0.message.content', ''));

        if ($content === '') {
            throw new RuntimeException('OpenAI generation returned empty content.');
        }

        return [
            'content' => $content,
            'provider_key' => 'openai',
            'model' => $model,
            'tokens_used' => (int) data_get($json, 'usage.total_tokens', 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackScript(array $variables): string
    {
        $source = trim((string) ($variables['source_content'] ?? ''));
        $goal = trim((string) ($variables['content_goal'] ?? 'inform'));
        $tone = trim((string) ($variables['tone'] ?? 'neutral'));

        return "Hook: Here is a quick {$tone} take.\n\n"
            ."Body: {$source}\n\n"
            ."CTA: Follow for more {$goal} content.";
    }
}
