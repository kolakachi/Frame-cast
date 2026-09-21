<?php

namespace App\Services\Generation\AI;

/** Routes creative work to premium; only transport failures use a fallback.
 * Explicit refusals are returned to the caller's gate, never retried elsewhere.
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
                $result = $this->premium->generate($promptTemplateKey, $variables, $maxTokens, $temperature, $options);

                return $result;
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
