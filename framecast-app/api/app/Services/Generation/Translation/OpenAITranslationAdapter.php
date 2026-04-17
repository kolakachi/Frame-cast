<?php

namespace App\Services\Generation\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAITranslationAdapter implements TranslationAdapter
{
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage, ?string $contextHint = null, bool $preserveFormatting = true): array
    {
        $texts = array_values(array_map(static fn (mixed $text): string => (string) $text, $texts));
        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        if ($apiKey === '') {
            return $this->fallback($texts, $sourceLanguage, $targetLanguage);
        }

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'max_tokens' => 2500,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You translate short-form video scripts. Return JSON only in this shape: {"translations":[{"source":"...","translated":"..."}]}. Preserve meaning, formatting, line breaks, numbers, names, and CTAs.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'source_language' => $sourceLanguage,
                                'target_language' => $targetLanguage,
                                'context_hint' => $contextHint ?: 'short-form social video script',
                                'preserve_formatting' => $preserveFormatting,
                                'texts' => $texts,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ]);

            if (! $response->ok()) {
                throw new RuntimeException('OpenAI translation request failed with status '.$response->status());
            }

            $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            $decoded = json_decode($this->stripCodeFence($content), true);
            $translations = is_array($decoded) ? ($decoded['translations'] ?? []) : [];

            if (! is_array($translations) || count($translations) !== count($texts)) {
                throw new RuntimeException('OpenAI translation returned an invalid payload.');
            }

            return [
                'translations' => array_map(
                    static fn (mixed $row, int $index): array => [
                        'source' => (string) ($row['source'] ?? $texts[$index] ?? ''),
                        'translated' => trim((string) ($row['translated'] ?? '')),
                    ],
                    $translations,
                    array_keys($translations),
                ),
                'provider_key' => 'openai',
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
            ];
        } catch (Throwable $exception) {
            Log::warning('Translation fell back to deterministic local content.', [
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback($texts, $sourceLanguage, $targetLanguage);
        }
    }

    /**
     * @param  array<int, string>  $texts
     * @return array{translations:array<int,array{source:string,translated:string}>,provider_key:string,source_language:string,target_language:string}
     */
    private function fallback(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        return [
            'translations' => array_map(
                static fn (string $text): array => [
                    'source' => $text,
                    'translated' => "[{$targetLanguage}] ".$text,
                ],
                $texts,
            ),
            'provider_key' => 'local_fallback',
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
        ];
    }

    private function stripCodeFence(string $content): string
    {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;

        return preg_replace('/\s*```$/', '', $content) ?? $content;
    }
}
