<?php

namespace App\Services\Generation\AI;

use App\Services\ApiUsageService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude via Replicate — the premium brain for creative templates.
 *
 * Scripts, hooks, breakdowns and visual concepts are where model quality is
 * visible in the product; they also run once per video, so even a 20x price
 * over gpt-4o-mini is cents against dollars of image/video COGS. Served
 * through Replicate so it rides the existing vendor account and token.
 */
class ReplicateClaudeAdapter implements AIGenerationAdapter
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

        $token = (string) config('services.replicate.api_token');
        $model = (string) config('services.ai.premium_model', 'anthropic/claude-sonnet-5');
        $usageContext = $this->usage->contextFromOptions($options);

        if ($token === '') {
            throw new RuntimeException('Replicate token missing for premium text generation.');
        }

        // The Replicate schema has no temperature; effort is its quality knob.
        $input = [
            'prompt'        => $userPrompt,
            'system_prompt' => $systemPrompt,
            'max_tokens'    => max(256, $maxTokens),
            'effort'        => (string) config('services.ai.premium_effort', 'medium'),
        ];

        $start = Http::withToken($token)
            ->withHeaders(['Prefer' => 'wait=55'])
            ->timeout(70)
            ->post("https://api.replicate.com/v1/models/{$model}/predictions", ['input' => $input]);

        if (! $start->successful()) {
            throw new RuntimeException("premium text model failed to start ({$start->status()}): ".mb_substr($start->body(), 0, 200));
        }

        $prediction = $start->json();
        $id = $prediction['id'] ?? null;

        for ($i = 0; $i < 24 && ! in_array($prediction['status'] ?? '', ['succeeded', 'failed', 'canceled'], true); $i++) {
            sleep(3);
            $prediction = Http::withToken($token)->timeout(30)
                ->get("https://api.replicate.com/v1/predictions/{$id}")->json();
        }

        if (($prediction['status'] ?? '') !== 'succeeded') {
            throw new RuntimeException('premium text model '.($prediction['status'] ?? 'timed out').': '.mb_substr((string) ($prediction['error'] ?? ''), 0, 200));
        }

        $output = $prediction['output'] ?? '';
        $content = is_array($output) ? implode('', $output) : (string) $output;

        // Claude often wraps JSON in a fence even when told not to; the JSON
        // consumers (scene_breakdown etc.) json_decode strictly, so unwrap.
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content));
        }

        // Sonnet 5 ~$3/$15 per 1M tokens; chars/4 approximates tokens.
        $inTok  = (int) ceil((mb_strlen($systemPrompt) + mb_strlen($userPrompt)) / 4);
        $outTok = (int) ceil(mb_strlen($content) / 4);
        $this->usage->record([
            ...$usageContext,
            'provider' => 'replicate',
            'service' => 'text_generation',
            'operation' => $promptTemplateKey,
            'model' => $model,
            'status' => 'succeeded',
            'units' => $inTok + $outTok,
            'estimated_cost_usd' => round(($inTok * 3 + $outTok * 15) / 1e6, 6),
        ]);

        return [
            'content' => $content,
            'provider_key' => 'replicate:'.$model,
            'model' => $model,
            'tokens_used' => $inTok + $outTok,
        ];
    }
}
