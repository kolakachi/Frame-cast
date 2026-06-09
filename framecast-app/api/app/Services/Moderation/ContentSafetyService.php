<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proactive input classifier — screens user prompts BEFORE we spend money
 * generating, beyond relying on the upstream provider to reject. Uses
 * OpenAI's moderation endpoint (free, purpose-built) and records a
 * ModerationEvent on any flag. This is the safeguard MOR/Stripe reviewers
 * scrutinize most for AI image/video/voice tools (see spec/MOR_COMPLIANCE.md).
 *
 * Fail-open by design: if the moderation API is unconfigured or errors, we
 * allow the request through (the upstream generator still applies its own
 * safety filter) — a moderation outage must not block all legitimate work.
 * We only ever BLOCK on a definitive flag.
 */
class ContentSafetyService
{
    public function __construct(private ModerationService $moderation)
    {
    }

    /**
     * Screen a piece of user text. Returns null when clean (allow), or a
     * user-safe rejection message when it should be blocked.
     *
     * @param  array<string,mixed>  $context  workspace_id/user_id/project_id/scene_id/operation
     */
    public function screenText(string $text, array $context = []): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return null; // unconfigured → fail open
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->post('https://api.openai.com/v1/moderations', [
                    'model' => 'omni-moderation-latest',
                    'input' => mb_substr($text, 0, 4000),
                ]);

            if (! $response->successful()) {
                Log::warning('ContentSafetyService: moderation API error', ['status' => $response->status()]);
                return null; // API error → fail open
            }

            $result = $response->json('results.0') ?? [];
            if (! ($result['flagged'] ?? false)) {
                return null; // clean
            }

            $categories = array_keys(array_filter(
                (array) ($result['categories'] ?? []),
                static fn ($v) => $v === true,
            ));

            // Record for admin triage. The category list drives severity
            // classification in ModerationService (sexual/minors → critical/high).
            $this->moderation->recordRejection(
                'Prompt blocked by content safety: '.implode(', ', $categories ?: ['flagged']),
                array_merge($context, [
                    'prompt'   => $text,
                    'metadata' => ['categories' => $categories, 'classifier' => 'openai_moderation'],
                ]),
            );

            return $this->userMessageFor($categories);
        } catch (\Throwable $e) {
            report($e);
            return null; // never block legit work on a classifier crash
        }
    }

    /** Map flagged categories to a clear, non-leaky user message. */
    private function userMessageFor(array $categories): string
    {
        $joined = implode(' ', $categories);
        if (preg_match('/sexual|minors/i', $joined)) {
            return 'This request was blocked: we don\'t generate sexual or explicit content. Please revise your prompt.';
        }
        if (preg_match('/hate|harass/i', $joined)) {
            return 'This request was blocked: it appears to contain hateful or harassing content. Please revise your prompt.';
        }
        if (preg_match('/violence|self.?harm/i', $joined)) {
            return 'This request was blocked: it appears to contain violent or self-harm content. Please revise your prompt.';
        }
        return 'This request was blocked by our content safety policy. Please revise your prompt and try again.';
    }
}
