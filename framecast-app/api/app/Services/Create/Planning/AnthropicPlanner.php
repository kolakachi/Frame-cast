<?php
namespace App\Services\Create\Planning;

use Illuminate\Support\Facades\{Http, Log};
use RuntimeException;

/** Bounded planning with read-only reference tools. Planning is funded by WyvStudio. */
class AnthropicPlanner implements Planner
{
    public function __construct(private string $model, private string $key) {}

    private function request(array $messages, string $effort, int $maxTokens, ?string $toolChoice, int $timeout): \Illuminate\Http\Client\Response
    {
        $body = [
            'model' => $this->model, 'max_tokens' => $maxTokens, 'output_config' => ['effort' => $effort],
            'system' => [['type' => 'text', 'text' => PlanPrompt::system(), 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => $messages,
        ];
        if ($toolChoice !== null) {
            $body['tools'] = [self::inspectionTool()];
            $body['tool_choice'] = ['type' => $toolChoice];
        }
        $response = Http::withHeaders(['x-api-key' => $this->key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout($timeout)
            ->post('https://api.anthropic.com/v1/messages', $body);
        if (! $response->successful()) {
            // The provider's own reason, so a refused request is diagnosable (no key or prompt text is in it).
            rescue(fn () => \Illuminate\Support\Facades\Log::warning('Planner request refused', ['model' => $this->model, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 400)]), report: false);
            throw new RuntimeException('Planner request failed.');
        }
        return $response;
    }

    /** Models whose thinking cannot be turned off; they do not accept tool_choice "any" or "tool". */
    public static function alwaysThinks(string $model): bool
    {
        return (bool) preg_match('/opus-5/', $model);
    }

    private static function inspectionTool(): array
    {
        return ['name' => 'inspect_reference', 'description' => 'Read a closer view of an attached reference before planning purchases. Choose timestamps/crops for uncertain character, layout or transition details. Frames: 1–8 times (images use [0]). Sequence: up to 2 seconds, count 2–8 or every_frame true with page 1–15 (8 actual frames/page, max 120/window). Shots: heuristic scan up to 30 seconds. Crop uses normalized x/y/width/height. Only returned pages were inspected; no audio analysis. At most four requests across two inspection turns; then return a plan with remaining uncertainty.',
            'input_schema' => ['type' => 'object', 'required' => ['asset_id', 'params'], 'additionalProperties' => false,
                'properties' => ['asset_id' => ['type' => 'integer'], 'params' => ['type' => 'object', 'required' => ['mode'], 'additionalProperties' => false,
                    'properties' => ['mode' => ['type' => 'string', 'enum' => ['frames', 'sequence', 'shots']],
                        'times' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ['type' => 'number', 'minimum' => 0]],
                        'start' => ['type' => 'number', 'minimum' => 0], 'end' => ['type' => 'number', 'minimum' => 0],
                        'count' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 8], 'every_frame' => ['type' => 'boolean', 'enum' => [true]],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 15],
                        'crop' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['x', 'y', 'width', 'height'],
                            'properties' => array_fill_keys(['x', 'y', 'width', 'height'], ['type' => 'number', 'minimum' => 0, 'maximum' => 1])]]]]]];
    }

    public function plan(array $context): array
    {
        $effort = (string) config('create.planner_effort', 'high');
        $eligible = ! empty($context['_workspace_id']) && collect($context['files'] ?? [])->contains(fn ($f) => ($f['purpose'] ?? '') === 'reference' && in_array($f['asset_type'] ?? '', ['image', 'video'], true));
        $messages = [['role' => 'user', 'content' => PlanPrompt::userContent($context)]];
        $inspector = app(PlannerReferenceInspector::class);
        $calls = []; $evidence = []; $inspections = []; $requests = 0; $plan = null; $draft = null; $asked = false; $repairable = null;
        $unlimited = \App\Services\Create\PilotPolicy::unlimited();
        $maxCalls = $unlimited ? ($eligible ? 10 : 4) : ($eligible ? 4 : 3);
        // Ceiling is shared across all responses, including malformed-plan recovery.
        $remainingOutput = $unlimited ? 120000 : 40000;
        // A plan that accounts for every studied reference moment is long; one reply must have room for all of it.
        $perCall = $unlimited ? 32000 : 24000;
        $maxInspections = $unlimited ? 16 : 4; $inspectionTurns = $unlimited ? 6 : 2;
        // A studied reference has already been looked at closely: one round of inspection, then the plan is written once
        // (more rounds made the planner write a full draft beside each request, then the plan again).
        if (collect($context['files'] ?? [])->contains(fn ($f) => ! empty(data_get($f, 'reference.study')))) { $maxInspections = 4; $inspectionTurns = 1; }
        $deadline = $context['_planner_deadline'] ?? microtime(true) + 100;
        $unreceipted = false;
        try {
            for ($turn = 0; $turn < $maxCalls; $turn++) {
                $remainingSeconds = (int) floor($deadline - microtime(true));
                if ($remainingSeconds < 1) throw new RuntimeException('Planner time budget exhausted.');
                $tools = $eligible && $turn < $inspectionTurns && $requests < $maxInspections;
                $unreceipted = true;
                // Models that always think (Opus 5.5) refuse a forced tool choice; they are asked to inspect first instead.
                $force = $turn === 0 && ! self::alwaysThinks($this->model);
                $response = $this->request($messages, $effort, min($perCall, $remainingOutput), $tools ? ($force ? 'any' : 'auto') : ($eligible ? 'none' : null), $remainingSeconds);
                $u = $response->json('usage', []);
                $calls[] = ['message_id' => $response->json('id'), 'input_tokens' => $u['input_tokens'] ?? null,
                    'output_tokens' => $u['output_tokens'] ?? null, 'cache_read_tokens' => $u['cache_read_input_tokens'] ?? null,
                    'cache_write_tokens' => $u['cache_creation_input_tokens'] ?? null, 'stop_reason' => $response->json('stop_reason')];
                $unreceipted = false;
                // Missing usage cannot grant more output budget.
                $remainingOutput -= (int) ($u['output_tokens'] ?? $perCall);
                $content = $response->json('content', []);
                $toolUses = array_values(array_filter($content, fn ($b) => ($b['type'] ?? '') === 'tool_use'));
                $text = collect($content)->where('type', 'text')->pluck('text')->implode('');
                // A complete plan written next to a further inspection request is kept as a draft, not thrown away.
                if ($toolUses && trim($text) !== '') $draft = PlanPrompt::extract($text) ?? $draft;
                if ($toolUses) {
                    if (! $tools || count($toolUses) > 8) throw new RuntimeException('Planner exceeded the reference inspection protocol.');
                    $messages[] = ['role' => 'assistant', 'content' => $content];
                    $results = [];
                    foreach ($toolUses as $tool) {
                        $requests++;
                        try {
                            if ($requests > $maxInspections || ($tool['name'] ?? '') !== 'inspect_reference') throw new RuntimeException('Inspection unavailable.');
                            $inspectionContext = $context + ['_planner_deadline' => $deadline];
                            $result = $inspector->inspect($inspectionContext, is_array($tool['input'] ?? null) ? $tool['input'] : []);
                            $evidence[] = $result['evidence'];
                            $inspections[] = ['status' => 'inspected', 'evidence_id' => $result['evidence']['id']];
                            $blocks = [['type' => 'text', 'text' => json_encode($result['evidence'], JSON_THROW_ON_ERROR)], $result['image']];
                            $results[] = ['type' => 'tool_result', 'tool_use_id' => $tool['id'], 'content' => $blocks];
                        } catch (\Throwable $e) {
                            $inspections[] = ['status' => 'unavailable', 'reason' => $requests > $maxInspections ? 'inspection_limit' : 'invalid_or_unavailable_reference'];
                            $results[] = ['type' => 'tool_result', 'tool_use_id' => $tool['id'], 'is_error' => true,
                                'content' => 'Inspection unavailable or outside limits. Use valid attached reference IDs and bounded params; state missing evidence in uncertain. Do not invent observations.'];
                        }
                    }
                    $messages[] = ['role' => 'user', 'content' => $results];
                    if ($turn === $inspectionTurns - 1 || $requests >= $maxInspections) $messages[] = ['role' => 'user', 'content' => 'Inspection budget reached. Return the final plan now using available evidence and explicit uncertainties.'];
                } else {
                    $plan = PlanPrompt::extract($text);
                    if ($plan && ! $repairable && ($problems = PlanPrompt::problems($plan, $context)) && $remainingOutput >= 4000 && $deadline - microtime(true) > 90 && $turn < $maxCalls - 1) {
                        // A plan that stops short or points at beats it never made goes back once with what is wrong; the original stays the fallback.
                        $repairable = $plan; $plan = null;
                        $messages[] = ['role' => 'assistant', 'content' => $content];
                        $messages[] = ['role' => 'user', 'content' => "The plan has these problems:\n- ".implode("\n- ", $problems)."\nReply with the complete corrected plan as one JSON object."];
                        continue;
                    }
                    if ($plan) break;
                    if (! $asked && $response->json('stop_reason') !== 'max_tokens' && ($content || $draft)) {
                        // The reply ended without the plan (often "the plan above is final"): ask for it once, keeping every inspection.
                        $asked = true;
                        if ($content) $messages[] = ['role' => 'assistant', 'content' => $content];
                        $messages[] = ['role' => 'user', 'content' => 'That reply did not contain the plan. Reply now with the complete final plan as one JSON object, updated with everything the inspections showed.'];
                        continue;
                    }
                    if ($effort === 'medium') break;
                    // A plan cut off at the length limit comes back the same length unless asked to be shorter.
                    if ($response->json('stop_reason') === 'max_tokens')
                        $messages[] = ['role' => 'user', 'content' => 'That reply was cut off at the length limit before the plan was complete. Reply with the complete plan as one JSON object, written compactly: short strings, one line of how per reference decision, nothing restated from the study.'];
                    // Retry the same evidence, not a fresh context that discards inspected details.
                    $effort = 'medium';
                }
                if ($remainingOutput < 1000) break;
            }
            $plan ??= $repairable ?? $draft;
            if (! $plan) throw new RuntimeException('Planner returned no complete plan within its call budget.');
            $usage = ['message_id' => end($calls)['message_id'], 'calls' => $calls, 'call_count' => count($calls), 'inspection_attempts' => $inspections];
            foreach (['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'] as $field) {
                $usage[$field] = in_array(null, array_column($calls, $field), true) ? null : array_sum(array_column($calls, $field));
            }
            return ['plan' => $plan, 'provider' => 'anthropic:'.$this->model, 'usage' => $usage, 'reference_evidence' => $evidence];
        } catch (\Throwable $e) {
            // Retain receipts even when a later request fails; never log prompts, media or credentials.
            Log::warning('create.planner.failed', ['workspace_id' => $context['_workspace_id'] ?? null, 'calls' => $calls,
                'inspection_attempts' => $inspections, 'possible_unreceipted_call' => $unreceipted]);
            throw $e;
        } finally { $inspector->close(); }
    }
}
