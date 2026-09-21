<?php

namespace App\Services\Generation\AI;

use App\Support\ScriptText;

/** Fail closed: a review outage must never authorize expensive production. */
class ContentReview
{
    public static function refused(array $result): bool
    {
        return ! empty($result['refusal'])
            || in_array($result['finish_reason'] ?? $result['stop_reason'] ?? '', ['refusal', 'content_filter'], true)
            || ScriptText::looksLikeRefusal((string) ($result['content'] ?? ''));
    }

    public static function unusable(array $result): bool
    {
        return trim((string) ($result['content'] ?? '')) === ''
            || ($result['provider_key'] ?? '') === 'local_fallback'
            || ($result['model'] ?? '') === 'deterministic'
            || in_array($result['finish_reason'] ?? '', ['length', 'max_tokens'], true);
    }

    /** Record only identifiers and fixed codes, never prompts, drafts or reviewer prose. */
    public static function logDecision(string $decision, string $reason, array $context = []): void
    {
        \Illuminate\Support\Facades\Log::warning('generation.validation', [
            ...array_intersect_key($context, array_flip(['project_id', 'workspace_id', 'stage', 'draft_attempt', 'review_attempt', 'provider', 'model'])),
            'decision' => $decision, 'reason_code' => $reason,
        ]);
    }

    public function review(AIGenerationAdapter $ai, string $source, string $candidate, array $context, array $options = []): array
    {
        // A transient reviewer failure retries this exact candidate, not generation.
        $deadline = min((float) ($options['deadline'] ?? INF), microtime(true) + 90);
        $logContext = [
            'project_id' => $options['usage_context']['project_id'] ?? null,
            'workspace_id' => $options['usage_context']['workspace_id'] ?? null,
            'stage' => $context['stage'] ?? 'unknown',
            'draft_attempt' => $options['draft_attempt'] ?? 1,
        ];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $logContext['review_attempt'] = $attempt;
            if (microtime(true) >= $deadline) {
                self::logDecision('unavailable', 'review_time_budget', $logContext);
                return ['decision' => 'unavailable'];
            }
            $result = [];
            $reason = 'review_transport_error';
            try {
                $result = $ai->generate('content_fidelity_review', [
                    'payload' => json_encode(['source' => $source, 'candidate' => $candidate, 'context' => $context], JSON_THROW_ON_ERROR),
                ], 700, 0, [
                    ...array_intersect_key($options, array_flip(['usage_context', 'images', 'image_detail'])),
                    'deadline' => $deadline,
                ]);
                $logContext['provider'] = $result['provider_key'] ?? 'unknown';
                $logContext['model'] = $result['model'] ?? 'unknown';
                if (microtime(true) >= $deadline) {
                    self::logDecision('unavailable', 'review_time_budget', $logContext);
                    return ['decision' => 'unavailable'];
                }
                if (self::refused($result)) {
                    // A reviewer refusal is not proof that the customer's brief is unsupported.
                    self::logDecision('unavailable', 'review_refused', $logContext);
                    return ['decision' => 'unavailable'];
                }
                $reason = 'review_unusable_response';
                if (! self::unusable($result)) {
                    $reason = 'review_invalid_response';
                    $review = $this->parse((string) $result['content']);
                    if ($review !== null) {
                        if ($review['decision'] !== 'pass') self::logDecision($review['decision'], 'review_'.$review['decision'], $logContext);
                        return $review;
                    }
                }
            } catch (\Throwable $e) {
                // No exception message: providers sometimes echo submitted content.
            }
            self::logDecision('unavailable', $reason, $logContext);
            if ($attempt < 3) {
                $delay = min(max(0, (int) config('services.ai.review_retry_delay_ms', 250)) * $attempt, 1000);
                $remainingMs = max(0, (int) (($deadline - microtime(true)) * 1000));
                if ($delay > 0) usleep(min($delay, $remainingMs) * 1000);
            }
        }
        return ['decision' => 'unavailable'];
    }

    private function parse(string $text): ?array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text));
        $review = json_decode($text, true);
        if (! is_array($review) || ! in_array($review['decision'] ?? '', ['pass', 'repair', 'unsupported', 'clarify'], true)
            || ! is_array($review['issues'] ?? null) || count($review['issues']) > 8) return null;
        foreach ($review['issues'] as $issue) {
            if (! is_string($issue) || mb_strlen($issue) > 500) return null;
        }
        if ($review['decision'] === 'pass' && $review['issues'] !== []) return null;
        return $review;
    }
}
