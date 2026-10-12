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
        private readonly AnthropicClaudeAdapter $direct,
    ) {
    }

    public function generate(string $promptTemplateKey, array $variables, int $maxTokens = 900, float $temperature = 0.4, array $options = []): array
    {
        $premiumTemplates = (array) config('services.ai.premium_templates', []);

        // The UGC director and planner on a model Replicate does not serve; a failed call falls to the premium route.
        if (in_array($promptTemplateKey, (array) config('services.ai.direct_templates', []), true) && (string) config('services.anthropic.key') !== '') {
            try {
                return $this->direct->generate($promptTemplateKey, $variables, $maxTokens, $temperature, $options);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('direct text model fell back to the premium route', [
                    'template' => $promptTemplateKey,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        if (in_array($promptTemplateKey, $premiumTemplates, true) && (string) config('services.replicate.api_token') !== '') {
            try {
                $result = $this->premium->generate($promptTemplateKey, $variables, $maxTokens, $temperature, $options);

                return $result;
            } catch (\Throwable $e) {
                // The fallback keeps users working; our account running dry still alerts the team.
                \App\Services\Vendors\VendorAlerts::observe('replicate', $e->getMessage(), null, ['kind' => 'text:'.$promptTemplateKey]);
                \Illuminate\Support\Facades\Log::warning('premium text model fell back to cheap tier', [
                    'template' => $promptTemplateKey,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return $this->cheap->generate($promptTemplateKey, $variables, $maxTokens, $temperature, $options);
    }
}
