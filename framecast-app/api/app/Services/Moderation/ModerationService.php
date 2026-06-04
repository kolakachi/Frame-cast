<?php

namespace App\Services\Moderation;

use App\Models\ModerationEvent;
use Illuminate\Support\Facades\Log;

/**
 * Central write-path for every moderation event on the platform.
 *
 * All three sources (provider rejection, user report, pattern alert) funnel
 * through here so admin triage has one source of truth. The model constants
 * (ModerationEvent::SOURCE_*, SEVERITY_*, ACTION_*) are the canonical
 * vocabulary — never write a raw string directly into the table.
 *
 * Methods are designed to never throw on the happy path of "we couldn't
 * write the moderation row." A logging failure must never break a live
 * generation request. The trade-off: if we silently fail to log, we lose
 * the audit trail for that event. Acceptable on a 1-in-10K basis; we'd
 * notice in the daily pattern job if event counts dropped sharply.
 */
class ModerationService
{
    /**
     * Record an upstream-provider rejection (OpenAI moderation, Replicate
     * safety filter, our own classifier).
     *
     * @param  array<string,mixed>  $context  Loose context: prompt, operation,
     *                                        workspace_id, user_id, scene_id,
     *                                        reference_asset_id, metadata.
     */
    public function recordRejection(string $reason, array $context = []): ?ModerationEvent
    {
        try {
            return ModerationEvent::query()->create([
                'source'             => ModerationEvent::SOURCE_GENERATION_REJECTION,
                'severity'           => $this->classifyRejectionSeverity($reason),
                'workspace_id'       => $context['workspace_id'] ?? null,
                'user_id'            => $context['user_id'] ?? null,
                'project_id'         => $context['project_id'] ?? null,
                'scene_id'           => $context['scene_id'] ?? null,
                'operation'          => $context['operation'] ?? null,
                'reason'             => mb_substr($reason, 0, 2000),
                'prompt'             => isset($context['prompt']) ? mb_substr((string) $context['prompt'], 0, 4000) : null,
                'reference_asset_id' => $context['reference_asset_id'] ?? null,
                'metadata'           => $context['metadata'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ModerationService::recordRejection failed', [
                'error'  => $e->getMessage(),
                'reason' => $reason,
            ]);
            return null;
        }
    }

    /**
     * Record a user-submitted report from /report-content. Reporter is
     * anonymous-OK; the email is optional for unauthenticated reporters.
     *
     * @param  array<string,mixed>  $report
     */
    public function recordUserReport(array $report): ?ModerationEvent
    {
        try {
            return ModerationEvent::query()->create([
                'source'                => ModerationEvent::SOURCE_USER_REPORT,
                'severity'              => $this->classifyReportSeverity($report['violation_type'] ?? null),
                'workspace_id'          => $report['workspace_id'] ?? null,
                'user_id'               => $report['user_id'] ?? null,
                'reason'                => isset($report['message']) ? 'User-reported: ' . mb_substr((string) $report['message'], 0, 200) : 'User-submitted report',
                'report_email'          => isset($report['email']) ? mb_substr((string) $report['email'], 0, 255) : null,
                'report_url'            => isset($report['url']) ? mb_substr((string) $report['url'], 0, 1000) : null,
                'report_message'        => isset($report['message']) ? mb_substr((string) $report['message'], 0, 4000) : null,
                'report_violation_type' => $report['violation_type'] ?? null,
                'metadata'              => array_filter([
                    'user_agent' => $report['user_agent'] ?? null,
                    'ip_hash'    => $report['ip_hash'] ?? null,
                    'referrer'   => $report['referrer'] ?? null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ModerationService::recordUserReport failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Record an automated pattern-detection alert from
     * DetectAbusePatternsJob. Severity is set by the calling rule.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordPatternAlert(string $rule, string $reason, array $context = []): ?ModerationEvent
    {
        try {
            return ModerationEvent::query()->create([
                'source'       => ModerationEvent::SOURCE_PATTERN_ALERT,
                'severity'     => $context['severity'] ?? ModerationEvent::SEVERITY_MEDIUM,
                'workspace_id' => $context['workspace_id'] ?? null,
                'user_id'      => $context['user_id'] ?? null,
                'operation'    => $rule,
                'reason'       => mb_substr($reason, 0, 2000),
                'metadata'     => $context['metadata'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ModerationService::recordPatternAlert failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Classify the severity of a provider rejection from the refusal text.
     * Most provider rejections are 'low' (policy nudge); we escalate when
     * keywords suggest the user was trying to produce something serious.
     */
    private function classifyRejectionSeverity(string $reason): string
    {
        $lc = strtolower($reason);

        // Critical: any signal we'd report to authorities if we had evidence.
        if (preg_match('/csam|minor.+sexual|child.+sexual|child.+abuse/i', $reason)) {
            return ModerationEvent::SEVERITY_CRITICAL;
        }

        // High: deepfake / impersonation / non-consensual sexual content.
        if (preg_match('/deepfake|nonconsens|non-consens|impersonat|public figure|celebrity|nude|sexual|porn|nsfw/i', $reason)) {
            return ModerationEvent::SEVERITY_HIGH;
        }

        // Medium: violence, hate, weapons.
        if (preg_match('/violence|hate|weapon|terror|self-harm|suicide|extremis/i', $reason)) {
            return ModerationEvent::SEVERITY_MEDIUM;
        }

        return ModerationEvent::SEVERITY_LOW;
    }

    private function classifyReportSeverity(?string $violationType): string
    {
        return match ($violationType) {
            'csam'                => ModerationEvent::SEVERITY_CRITICAL,
            'nonconsensual_sexual',
            'deepfake_impersonation',
            'public_figure'       => ModerationEvent::SEVERITY_HIGH,
            'hate_violence',
            'misinformation',
            'fraud_scam'          => ModerationEvent::SEVERITY_MEDIUM,
            default               => ModerationEvent::SEVERITY_LOW,
        };
    }
}
