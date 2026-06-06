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
     * @param string $intent          user's free-text prompt
     * @param Project $project        the project being edited
     * @param ?Scene $scope           the scene the user is focused on (null = whole project)
     * @param array<int, array{role:string, text:string}> $history  prior turns
     *                                (oldest first). Used so the LLM can resolve
     *                                pronouns like "it" across turns. Capped to
     *                                the last 6 entries before sending.
     * @return array{reply_to_user:string, action:?array}
     */
    public function resolve(string $intent, Project $project, ?Scene $scope, array $history = []): array
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return [
                'reply_to_user' => 'Assistant is not configured. Please ask Kolawole to set OPENAI_API_KEY.',
                'action' => null,
            ];
        }

        $systemPrompt = $this->buildSystemPrompt($project, $scope);

        // Build the message stack with up to 6 prior turns so the LLM can
        // resolve cross-turn references like "it" / "that" / "the one
        // before". Without this, every call is an amnesic single-shot and
        // users have to repeat the full intent every turn — which is
        // exactly the bug that surfaced before this fix.
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice($history, -6) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = mb_substr((string) ($turn['text'] ?? ''), 0, 800);
            if ($text === '') continue;
            $messages[] = ['role' => $role, 'content' => $text];
        }
        $messages[] = ['role' => 'user', 'content' => $intent];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(25)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature'     => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => $messages,
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

        // Workspace characters — so the LLM can resolve "use my Kay" to a
        // character_id without asking. Capped to 10 so the prompt stays
        // tight; if the user has more, they'll need to name the id.
        $characters = \App\Models\Character::query()
            ->where('workspace_id', $project->workspace_id)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'name']);
        $characterList = $characters->isEmpty()
            ? '  (none)'
            : $characters->map(fn ($c) => "  - id={$c->id} name=\"{$c->name}\"")->implode("\n");

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

CHARACTERS (saved in workspace)
{$characterList}

{$scopeBlock}

AVAILABLE TOOLS
{$tools}

RULES
- If the user's request maps cleanly to a tool, set action with the
  smallest correct params. Default scene_id to the focused scene unless
  the user names a different one.
- Use the conversation history above to resolve pronouns ("it", "that",
  "the one") and avoid asking the user to repeat themselves.

ROUTING DISAMBIGUATION
- "generate / create / make / replace with / swap to a [DESCRIPTION]"
  -> regenerate_image, prompt_override = the description. NOT
  swap_visual_from_library.
- "use my [ASSET NAME] / use asset 142 / use the kitchen photo I uploaded"
  -> swap_visual_from_library, asset_id = the matched asset.
- "make it more [ADJECTIVE]" for visuals -> regenerate_image with a
  prompt_override that incorporates the adjective.
- Style cues map to the style param: "3D" / "Pixar" / "Disney" / "animated"
  -> 3d_animated. "anime" -> anime. "watercolor" -> watercolor.
  "cinematic" -> cinematic. "photo" / "realistic" -> photorealistic.
- "animate / make it move / add motion" -> animate_scene. Default
  tier="quick" unless user says "cinematic"/"premium" (premium) or
  "high quality"/"best" (premium).
- "add a [scene/cta/intro/outro]" -> add_scene. Write the script in
  first/second person speech. Visual prompt is a concrete image
  description.

CLARIFY ONLY IF YOU MUST
- If the user gave you enough to act, ACT. Don't ask for asset IDs
  unless the user explicitly named an existing asset.
- If the user is genuinely ambiguous, set action=null and ask ONE
  short clarifying question. Otherwise just resolve.
- If the user asks for something not supported, set action=null and
  briefly say what you CAN do (list 2-3 capabilities, not all 6).

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
