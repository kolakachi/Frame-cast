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
 *
 * "Failure" includes a refusal. A model that declines the brief returns HTTP
 * 200 with the decline as its content — no error, no flag — so the fallback
 * below never fired and the refusal was written into the video as if it were
 * a script. Models draw the line in different places, and most declines on a
 * marketing brief are false positives, so a decline is retried once on the
 * other tier. If both decline, the refusal is returned unchanged and the
 * caller (GenerateScriptJob) fails the project with a message the user can
 * act on. Two tries, then stop — never a hunt for a model that will say yes.
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

                if (! \App\Support\ScriptText::looksLikeRefusal((string) ($result['content'] ?? ''))) {
                    return $result;
                }

                \Illuminate\Support\Facades\Log::warning('premium text model declined the brief; retrying on the cheap tier', [
                    'template' => $promptTemplateKey,
                    'refusal'  => mb_substr((string) ($result['content'] ?? ''), 0, 200),
                ]);
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
