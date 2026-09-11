<?php

namespace App\Support;

/**
 * Model-dependent tuning keys for an OpenAI chat completion.
 *
 * The GPT-5 family rejects a custom `temperature` outright — the request fails
 * with a 400, not a warning — and renamed `max_tokens` to
 * `max_completion_tokens`. Older models accept the original pair.
 *
 * This exists because the distinction was handled in OpenAIGenerationAdapter
 * while four other places built their own request bodies. When the cheap model
 * was pointed at gpt-5-mini those four began failing every call: the one-shot
 * prompt parser fell back to returning the prompt as the script, so customers
 * got a video of their own brief read aloud and were charged for it.
 *
 * Anything POSTing to /v1/chat/completions should spread this rather than
 * hand-writing the keys.
 */
final class OpenAiChatParams
{
    /**
     * @return array<string,mixed> keys to merge into the request body
     */
    public static function tuning(string $model, int $maxTokens, float $temperature = 0.4): array
    {
        if (str_starts_with($model, 'gpt-5')) {
            // Temperature is omitted, not defaulted: sending the default
            // explicitly is also rejected.
            //
            // The budget is widened because these are reasoning models: the
            // thinking is billed against max_completion_tokens and emits no
            // visible text, so a budget sized for the answer alone is spent
            // before the answer starts and the call returns "" — which reads
            // as a malformed response rather than as running out of room.
            return ['max_completion_tokens' => max($maxTokens * 4, 4000)];
        }

        return ['temperature' => $temperature, 'max_tokens' => $maxTokens];
    }
}
