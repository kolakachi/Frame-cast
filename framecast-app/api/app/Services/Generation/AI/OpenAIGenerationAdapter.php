<?php

namespace App\Services\Generation\AI;

use App\Services\ApiUsageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAIGenerationAdapter implements AIGenerationAdapter
{
    public function __construct(
        private readonly PromptTemplateRegistry $templates,
        private readonly ApiUsageService $usage,
    ) {
    }

    public function generate(string $promptTemplateKey, array $variables, int $maxTokens = 900, float $temperature = 0.4, array $options = []): array
    {
        $template = $this->templates->template($promptTemplateKey);
        $systemPrompt = $template['system'];
        $userPrompt = $this->templates->render($template['user'], $variables);

        if (isset($options['system_prefix']) && is_string($options['system_prefix']) && $options['system_prefix'] !== '') {
            $systemPrompt = $options['system_prefix']."\n\n".$systemPrompt;
        }

        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $usageContext = $this->usage->contextFromOptions($options);

        if ($apiKey === '') {
            return [
                'content' => $this->fallbackContent($promptTemplateKey, $variables),
                'provider_key' => 'openai',
                'model' => $model,
                'tokens_used' => 0,
            ];
        }

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $this->userMessageContent($userPrompt, $options)],
                    ],
                ]);

            if (! $response->ok()) {
                throw new RuntimeException('OpenAI generation request failed.');
            }

            $json = $response->json();
            $content = trim((string) data_get($json, 'choices.0.message.content', ''));
            $promptTokens = (int) data_get($json, 'usage.prompt_tokens', 0);
            $completionTokens = (int) data_get($json, 'usage.completion_tokens', 0);
            $totalTokens = (int) data_get($json, 'usage.total_tokens', 0);

            if ($content === '') {
                throw new RuntimeException('OpenAI generation returned empty content.');
            }

            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service' => 'text_generation',
                'operation' => $promptTemplateKey,
                'model' => $model,
                'status' => 'succeeded',
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost_usd' => $this->usage->estimateTextCost($model, $promptTokens, $completionTokens, $totalTokens),
            ]);

            return [
                'content' => $content,
                'provider_key' => 'openai',
                'model' => $model,
                'tokens_used' => $totalTokens,
            ];
        } catch (Throwable $exception) {
            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service' => 'text_generation',
                'operation' => $promptTemplateKey,
                'model' => $model,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

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
     * @param  array<string, mixed>  $options
     * @return string|list<array<string, mixed>>
     */
    private function userMessageContent(string $userPrompt, array $options): string|array
    {
        $images = $options['images'] ?? [];

        if (! is_array($images) || $images === []) {
            return $userPrompt;
        }

        $content = [
            ['type' => 'text', 'text' => $userPrompt],
        ];

        foreach (array_slice($images, 0, 15) as $image) {
            if (! is_array($image)) {
                continue;
            }

            $url = trim((string) ($image['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $title = trim((string) ($image['title'] ?? ''));

            if ($title !== '') {
                $content[] = ['type' => 'text', 'text' => "Image reference: {$title}"];
            }

            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $url,
                    'detail' => 'low',
                ],
            ];
        }

        return $content;
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

        if ($promptTemplateKey === 'score_hooks') {
            return $this->fallbackScoreHooks($variables);
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

        if ($sourceType === 'images') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $source) ?: [])));
            $imageLines = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Image ')));

            if ($imageLines === []) {
                $imageLines = ['Image 1: uploaded source image'];
            }

            $beats = [];

            foreach (array_slice($imageLines, 0, 15) as $index => $line) {
                $beats[] = ($index === 0 ? 'Hook' : 'Scene '.($index + 1)).": Use this uploaded image as the visual anchor. {$line}. Add the viewer context in a concise {$tone} narration beat.";
            }

            return implode("\n\n", $beats);
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
     * Deterministic fallback for score_hooks — assigns placeholder scores so the
     * UI renders score badges even when the API key is absent.
     *
     * @param  array<string, mixed>  $variables
     */
    private function fallbackScoreHooks(array $variables): string
    {
        $hooksJson = trim((string) ($variables['hooks_json'] ?? '[]'));
        $hooks = json_decode($hooksJson, true);

        if (! is_array($hooks)) {
            return json_encode(['scores' => []], JSON_UNESCAPED_SLASHES);
        }

        $fallbackScores = [72, 65, 58, 80, 63, 55, 70, 68, 61, 75];
        $fallbackReasons = [
            'Clear pattern interrupt but the claim could be sharper.',
            'Decent curiosity gap — specificity would push it higher.',
            'Low urgency; try leading with a concrete result instead.',
            'Strong emotional pull and specific outcome — well-structured hook.',
            'Moderate curiosity but the opening word is weak.',
        ];

        $scores = [];
        foreach (array_values($hooks) as $i => $hook) {
            $id = (int) ($hook['id'] ?? ($i + 1));
            $scores[] = [
                'id' => $id,
                'score' => $fallbackScores[$i % count($fallbackScores)],
                'reason' => $fallbackReasons[$i % count($fallbackReasons)],
            ];
        }

        return (string) json_encode(['scores' => $scores], JSON_UNESCAPED_SLASHES);
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
