<?php

namespace App\Services\CruiseControl;

use App\Models\Project;
use App\Models\Scene;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LLM glue. Takes user intent + project/scene context, returns a
 * structured action proposal. The controller wraps validation +
 * audit logging + the apply step on top of this.
 *
 * Model: gpt-4o-mini, response_format=json_object. ~$0.0002/turn at the
 * prompt sizes we send (≤ 1k input + ≤ 300 output).
 */
class CruiseControlService
{
    public function __construct(private CruiseToolRegistry $registry)
    {
    }

    /**
     * @param string $intent    user's free-text prompt
     * @param Project $project  the project being edited
     * @param ?Scene $scope     the scene the user is focused on (null = whole project)
     * @return array{reply_to_user:string, action:?array}
     */
    public function resolve(string $intent, Project $project, ?Scene $scope): array
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return [
                'reply_to_user' => 'Assistant is not configured. Please ask Kolawole to set OPENAI_API_KEY.',
                'action' => null,
            ];
        }

        $systemPrompt = $this->buildSystemPrompt($project, $scope);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(25)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature'     => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $intent],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('CruiseControl LLM call failed', [
                    'status' => $response->status(),
                    'body'   => mb_substr((string) $response->body(), 0, 500),
                ]);
                return ['reply_to_user' => "I couldn't understand that — try rephrasing.", 'action' => null];
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $parsed  = json_decode($content, true);

            if (! is_array($parsed)) {
                return ['reply_to_user' => "I got a malformed answer — try rephrasing.", 'action' => null];
            }

            $reply = trim((string) ($parsed['reply_to_user'] ?? "Okay."));
            $action = $parsed['action'] ?? null;

            // Validate the proposed tool. Whitelist enforced here — anything
            // the LLM tries that's not in the registry gets dropped silently
            // and we fall back to a clarifying reply.
            if (is_array($action)) {
                $toolName = (string) ($action['tool'] ?? '');
                $tool = $this->registry->get($toolName);
                if (! $tool) {
                    Log::info('CruiseControl LLM proposed unknown tool', ['tool' => $toolName]);
                    return [
                        'reply_to_user' => "I can't do that yet — try a voice swap, visual change, or music regen.",
                        'action' => null,
                    ];
                }

                // Backfill display fields the LLM may omit. The tool owns
                // the diff + cost; the LLM owns the conversational reply.
                $params = is_array($action['params'] ?? null) ? $action['params'] : [];
                $action = [
                    'tool'               => $toolName,
                    'params'             => $params,
                    'diff_lines'         => $tool->diffLines($project, $params),
                    'estimated_cost'     => $tool->estimateCost($project, $params),
                    'confirmation_class' => $tool->confirmationClass(),
                    'affected_section'   => $tool->affectedSection(),
                ];
            }

            return ['reply_to_user' => $reply, 'action' => $action];
        } catch (\Throwable $e) {
            Log::error('CruiseControl resolve exception', ['message' => $e->getMessage()]);
            return ['reply_to_user' => "Something went wrong on my end — try again.", 'action' => null];
        }
    }

    /**
     * Pack the system prompt with current state. Kept terse — long prompts
     * cost more AND degrade quality on gpt-4o-mini.
     */
    private function buildSystemPrompt(Project $project, ?Scene $scope): string
    {
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get(['id', 'scene_order', 'label', 'script_text']);

        $sceneList = $scenes->map(fn ($s) => sprintf(
            '  - id=%d order=%d label="%s" script="%s"',
            $s->id,
            $s->scene_order,
            mb_substr((string) $s->label, 0, 40),
            mb_substr((string) $s->script_text, 0, 80),
        ))->implode("\n");

        $scopeBlock = $scope
            ? "User's current focus: Scene {$scope->scene_order} (id={$scope->id})."
            : "User's current focus: the whole project (no specific scene selected).";

        $tools = $this->registry->promptCatalog();

        return <<<SYS
You are the WyvStudio video editor assistant. The user is editing a
short-form video. Resolve their intent to ONE tool call.

PROJECT
  id: {$project->getKey()}
  title: {$project->title}
  aspect_ratio: {$project->aspect_ratio}
  scenes: {$scenes->count()}

SCENES
{$sceneList}

{$scopeBlock}

AVAILABLE TOOLS
{$tools}

RULES
- If the user's request maps cleanly to a tool, set action with the
  smallest correct params. Default scene_id to the focused scene unless
  the user names a different one.
- If the user is ambiguous, set action=null and ask one short clarifying
  question in reply_to_user.
- If the user asks for something not supported, set action=null and
  briefly say what you CAN do.
- reply_to_user is one sentence, conversational, present-tense.

RESPONSE — STRICT JSON, NO MARKDOWN:
{
  "reply_to_user": "...",
  "action": null OR {
    "tool": "<one of the tool names above>",
    "params": { ... }
  }
}
SYS;
    }
}
