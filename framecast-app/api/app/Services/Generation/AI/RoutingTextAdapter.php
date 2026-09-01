<?php

namespace App\Services\Generation\AI;

/**
 * Routes each prompt template to the brain it deserves.
 *
 * Creative templates — where model quality is visible in the finished video —
 * go to the premium model; mechanical ones stay on the cheap tier. The list
 * lives in config so promoting a template is an edit, not a refactor. Any
 * premium failure falls back to the cheap adapter: a slightly blander script
 * beats a failed generation, always.
 */
class RoutingTextAdapter implements AIGenerationAdapter
{
    public function __construct(
        private readonly OpenAIGenerationAdapter $cheap,
        private readonly ReplicateClaudeAdapter $premium,
    ) {
    }

    public function generate(string $promptTemplateKey, array $variables, int $maxTokens = 900, float $temperature = 0.4, array $options = []): array
    {
        $premiumTemplates = (array) config('services.ai.premium_templates', []);

        if (in_array($promptTemplateKey, $premiumTemplates, true) && (string) config('services.replicate.api_token') !== '') {
            try {
                return $this->premium->generate($promptTemplateKey, $variables, $maxTokens, $temperature, $options);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('premium text model fell back to cheap tier', [
                    'template' => $promptTemplateKey,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return $this->cheap->generate($promptTemplateKey, $variables, $maxTokens, $temperature, $options);
    }
}
