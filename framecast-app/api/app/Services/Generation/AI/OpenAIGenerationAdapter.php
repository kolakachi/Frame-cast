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
                'content' => $this->fallbackContent($promptTemplateKey, $variables),
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
    private function fallbackContent(string $promptTemplateKey, array $variables): string
    {
        if ($promptTemplateKey === 'scene_breakdown') {
            return $this->fallbackSceneBreakdown($variables);
        }

        return $this->fallbackScript($variables);
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

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackSceneBreakdown(array $variables): string
    {
        $scriptText = trim((string) ($variables['script_text'] ?? ''));
        $chunks = preg_split('/\n{2,}/', $scriptText) ?: [];
        $scenes = [];

        foreach (array_slice($chunks, 0, 8) as $index => $chunk) {
            $text = trim($chunk);

            if ($text === '') {
                continue;
            }

            $scenes[] = [
                'scene_type' => $index === 0 ? 'hook' : 'narration',
                'label' => 'Scene '.($index + 1),
                'script_text' => $text,
                'duration_seconds' => 6,
            ];
        }

        if ($scenes === []) {
            $scenes[] = [
                'scene_type' => 'narration',
                'label' => 'Scene 1',
                'script_text' => $scriptText,
                'duration_seconds' => 6,
            ];
        }

        return (string) json_encode(['scenes' => $scenes], JSON_UNESCAPED_SLASHES);
    }
}
