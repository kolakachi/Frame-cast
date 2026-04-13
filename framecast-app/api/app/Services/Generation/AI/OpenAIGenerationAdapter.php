<?php

namespace App\Services\Generation\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

        try {
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
        } catch (Throwable $exception) {
            Log::warning('AI generation fell back to deterministic local content.', [
                'template' => $promptTemplateKey,
                'error' => $exception->getMessage(),
            ]);

            return [
                'content' => $this->fallbackContent($promptTemplateKey, $variables),
                'provider_key' => 'local_fallback',
                'model' => 'deterministic',
                'tokens_used' => 0,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackContent(string $promptTemplateKey, array $variables): string
    {
        if ($promptTemplateKey === 'scene_breakdown') {
            return $this->fallbackSceneBreakdown($variables);
        }

        if ($promptTemplateKey === 'hook_options') {
            return $this->fallbackHookOptions($variables);
        }

        if ($promptTemplateKey === 'scene_rewrite') {
            return $this->fallbackSceneRewrite($variables);
        }

        if ($promptTemplateKey === 'scene_insert') {
            return $this->fallbackSceneInsert($variables);
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
        $sourceType = trim((string) ($variables['source_type'] ?? 'prompt'));

        if ($sourceType === 'csv_topic') {
            $source = $this->fallbackCsvTopic($source);
        }

        if ($sourceType === 'product_description') {
            return "Hook: This is for people who need a simpler way to solve a real workflow problem.\n\n"
                ."Problem: {$source}\n\n"
                ."Benefit: Show the viewer how this product helps them move faster without adding more complexity.\n\n"
                ."CTA: Try it when you want a cleaner way to handle {$goal}.";
        }

        if (in_array($sourceType, ['audio_upload', 'video_upload'], true)) {
            return "Hook: We are turning this existing {$sourceType} into a sharper short-form clip.\n\n"
                ."Context: The source file is {$source}.\n\n"
                ."Main point: Keep the strongest idea, add clear captions, and make the opening more direct.\n\n"
                ."CTA: Review the generated scenes and replace this draft with the transcript when transcription is connected.";
        }

        return "Hook: Here is a quick {$tone} take.\n\n"
            ."Body: {$source}\n\n"
            ."CTA: Follow for more {$goal} content.";
    }

    private function fallbackCsvTopic(string $source): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $source) ?: [])));

        if (count($lines) < 2) {
            return $source;
        }

        $headers = str_getcsv($lines[0]);
        $firstRow = str_getcsv($lines[1]);
        $row = [];

        foreach ($headers as $index => $header) {
            $row[strtolower(trim((string) $header))] = trim((string) ($firstRow[$index] ?? ''));
        }

        $topic = $row['topic'] ?? 'this topic';
        $angle = $row['angle'] ?? 'what people need to know';
        $audience = $row['audience'] ?? 'the viewer';
        $cta = $row['cta'] ?? 'take one simple action today';

        return "Topic: {$topic}\nAngle: {$angle}\nAudience: {$audience}\nCTA: {$cta}";
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

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackHookOptions(array $variables): string
    {
        $scriptText = trim((string) ($variables['script_text'] ?? ''));
        $lead = mb_substr($scriptText, 0, 70);

        $hooks = [
            ['text' => 'Stop scrolling: '.$lead],
            ['text' => 'This one shift changes your outcome fast.'],
            ['text' => 'Most people miss this until it is too late.'],
            ['text' => 'Use this framework for your next short.'],
            ['text' => 'Here is the simplest way to get started.'],
        ];

        return (string) json_encode(['hooks' => $hooks], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackSceneRewrite(array $variables): string
    {
        $scriptText = trim((string) ($variables['script_text'] ?? ''));
        $mode = (string) ($variables['mode'] ?? 'simplify');

        if ($scriptText === '') {
            return '';
        }

        return match ($mode) {
            'shorten' => mb_substr($scriptText, 0, max(20, (int) floor(mb_strlen($scriptText) * 0.7))),
            'expand' => $scriptText.' This is the part most people overlook.',
            'stronger_hook' => 'Stop scrolling: '.$scriptText,
            'more_punchy' => preg_replace('/\s+/', ' ', $scriptText).' Period.',
            'more_educational' => $scriptText.' Here is why it matters.',
            'more_salesy' => $scriptText.' This is your cue to act now.',
            default => $scriptText,
        };
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackSceneInsert(array $variables): string
    {
        $seed = trim((string) ($variables['current_text'] ?? ''));
        $previous = trim((string) ($variables['previous_scene'] ?? ''));
        $next = trim((string) ($variables['next_scene'] ?? ''));
        $sceneType = (string) ($variables['scene_type'] ?? 'narration');
        $projectTitle = trim((string) ($variables['project_title'] ?? 'this topic'));

        $basis = $seed !== '' ? $seed : ($previous !== '' ? $previous : ($next !== '' ? $next : $projectTitle));

        return match ($sceneType) {
            'hook' => 'Stop scrolling: '.$basis,
            'transition' => 'That leads directly to the next point: '.$basis,
            'text_card' => mb_substr($basis, 0, 80),
            'quote' => '"'.trim($basis, "\" \n\r\t").'"',
            default => $next !== ''
                ? 'Here is the bridge into the next idea: '.$next
                : 'Here is the key point about '.$basis.'.',
        };
    }
}
