<?php

namespace App\Jobs;

use App\Mail\ModerationDigestMail;
use App\Models\ModerationEvent;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\Moderation\ModerationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily abuse-pattern scanner.
 *
 * Closes the gap between the upstream-provider catch-everything moderation
 * (which only blocks individual generations) and the user-report inbox
 * (which only catches what humans notice). Runs once per 24 hours on the
 * scheduler container, scans the last 24 hours of generations + moderation
 * events, and creates pattern_alert moderation events for each rule that
 * fires. Optionally emails a single-shot digest to the configured admin
 * address if any new alerts landed.
 *
 * Rules implemented in v1:
 *   1) Workspaces with >= N provider rejections in the last 24h (rule:
 *      'rejection_burst'). Strong signal of deliberate probing or
 *      automated abuse. Threshold from config/moderation.php.
 *   2) Scene visual_prompts (last 24h) that contain any of the high-risk
 *      terms in config/moderation.php (rule: 'high_risk_term'). One alert
 *      per (workspace_id, term) pair to avoid storms.
 *
 * Rules deferred (see LEGAL_AND_TRUST_AND_SAFETY.md):
 *   - Same reference asset hash used across multiple workspaces (signals
 *     a shared celebrity reference photo).
 *   - Velocity spikes (workspace generations / hour > rolling p99).
 *   - Republished-share-link spam.
 */
class DetectAbusePatternsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'detect-abuse-patterns';
    }

    public function handle(): void
    {
        $since = CarbonImmutable::now()->subHours(24);
        $newAlerts = [];

        $newAlerts = array_merge(
            $newAlerts,
            $this->scanRejectionBursts($since),
        );

        $newAlerts = array_merge(
            $newAlerts,
            $this->scanHighRiskTerms($since),
        );

        if ($newAlerts !== []) {
            $this->sendDigest($newAlerts);
        }

        Log::info('DetectAbusePatternsJob completed', [
            'new_alerts'   => count($newAlerts),
            'window_start' => $since->toIso8601String(),
        ]);
    }

    /**
     * Rule 1: workspaces that crossed the rejection threshold in 24h.
     *
     * @return array<int,ModerationEvent>
     */
    private function scanRejectionBursts(CarbonImmutable $since): array
    {
        $threshold = (int) config('moderation.rejection_threshold_per_workspace_24h', 5);

        $bursts = ModerationEvent::query()
            ->where('source', ModerationEvent::SOURCE_GENERATION_REJECTION)
            ->where('created_at', '>=', $since)
            ->whereNotNull('workspace_id')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->selectRaw('workspace_id, COUNT(*) as rejection_count')
            ->get();

        $alerts = [];
        $service = app(ModerationService::class);

        foreach ($bursts as $burst) {
            // Avoid duplicating an alert if one already exists for the same
            // workspace in the same 24h window.
            $exists = ModerationEvent::query()
                ->where('source', ModerationEvent::SOURCE_PATTERN_ALERT)
                ->where('operation', 'rejection_burst')
                ->where('workspace_id', $burst->workspace_id)
                ->where('created_at', '>=', $since)
                ->exists();

            if ($exists) {
                continue;
            }

            $event = $service->recordPatternAlert(
                'rejection_burst',
                "Workspace had {$burst->rejection_count} provider rejections in the last 24h (threshold {$threshold}).",
                [
                    'workspace_id' => $burst->workspace_id,
                    'severity'     => ModerationEvent::SEVERITY_HIGH,
                    'metadata'     => [
                        'rejection_count' => (int) $burst->rejection_count,
                        'threshold'       => $threshold,
                        'window_hours'    => 24,
                    ],
                ],
            );

            if ($event) {
                $alerts[] = $event;
            }
        }

        return $alerts;
    }

    /**
     * Rule 2: high-risk terms in scene prompts in the last 24h. One alert
     * per (workspace, term) so admins can investigate without being
     * flooded if a single workspace runs many similar prompts.
     *
     * @return array<int,ModerationEvent>
     */
    private function scanHighRiskTerms(CarbonImmutable $since): array
    {
        $terms = (array) config('moderation.high_risk_terms', []);
        if ($terms === []) {
            return [];
        }

        // Pull all scenes touched in the window. We pull and scan in PHP
        // rather than constructing a giant LIKE-OR query — clearer code
        // and at this stage the volume is small.
        $scenes = Scene::query()
            ->where('updated_at', '>=', $since)
            ->whereNotNull('visual_prompt')
            ->with('project:id,workspace_id,created_by_user_id')
            ->get(['id', 'project_id', 'visual_prompt', 'updated_at']);

        if ($scenes->isEmpty()) {
            return [];
        }

        // (workspace_id, term) tuples we've already alerted on in this window.
        $alreadyAlerted = ModerationEvent::query()
            ->where('source', ModerationEvent::SOURCE_PATTERN_ALERT)
            ->where('operation', 'high_risk_term')
            ->where('created_at', '>=', $since)
            ->get(['workspace_id', 'metadata'])
            ->mapWithKeys(function ($e) {
                $term = $e->metadata['term'] ?? null;
                if (! $e->workspace_id || ! $term) {
                    return [];
                }
                return [$e->workspace_id . '|' . $term => true];
            })
            ->toArray();

        $alerts = [];
        $service = app(ModerationService::class);

        foreach ($scenes as $scene) {
            $prompt = strtolower((string) $scene->visual_prompt);
            $workspaceId = $scene->project?->workspace_id;
            if (! $workspaceId) {
                continue;
            }

            foreach ($terms as $term) {
                $needle = strtolower($term);
                if ($needle === '' || ! str_contains($prompt, $needle)) {
                    continue;
                }

                $key = $workspaceId . '|' . $term;
                if (isset($alreadyAlerted[$key])) {
                    continue;
                }
                $alreadyAlerted[$key] = true;

                $event = $service->recordPatternAlert(
                    'high_risk_term',
                    "Prompt in workspace contains high-risk term: \"{$term}\". Scene {$scene->id}.",
                    [
                        'workspace_id' => $workspaceId,
                        'user_id'      => $scene->project->created_by_user_id ?? null,
                        'severity'     => ModerationEvent::SEVERITY_MEDIUM,
                        'metadata'     => [
                            'term'             => $term,
                            'scene_id'         => $scene->id,
                            'prompt_snippet'   => mb_substr($scene->visual_prompt, 0, 200),
                        ],
                    ],
                );

                if ($event) {
                    $alerts[] = $event;
                }
            }
        }

        return $alerts;
    }

    /**
     * Email a short digest summarising new alerts. Sent only when there
     * is content to report — quiet days stay quiet.
     *
     * @param array<int,ModerationEvent> $alerts
     */
    private function sendDigest(array $alerts): void
    {
        $email = (string) config('moderation.digest_email', 'hello@wyvstudio.com');
        if ($email === '') {
            return;
        }
        try {
            Mail::to($email)->queue(new ModerationDigestMail($alerts));
        } catch (\Throwable $e) {
            Log::warning('DetectAbusePatternsJob: digest mail failed', [
                'error' => $e->getMessage(),
                'count' => count($alerts),
            ]);
        }
    }
}
