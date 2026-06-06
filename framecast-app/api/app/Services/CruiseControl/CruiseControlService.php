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

    private function projectDefaultVoice(Project $project): string
    {
        return (string) (data_get($project->default_voice_settings_json, 'voice_id') ?? 'alloy');
    }

    /**
     * USER PREFERENCES block. Empty (just a blank line) when no prefs
     * are set — keeps the prompt tight for users who haven't touched
     * the Assistant settings.
     */
    private function buildPrefsBlock(?\App\Models\Workspace $workspace): string
    {
        if (! $workspace) return '';
        $lines = [];
        if ($workspace->cruise_image_model) {
            $lines[] = "- Default image model: {$workspace->cruise_image_model}. Pass model_key on regenerate_image unless the user explicitly names a different model.";
        }
        if ($workspace->cruise_animation_tier) {
            $lines[] = "- Default animation tier: {$workspace->cruise_animation_tier}. Use this for animate_scene and chain_animate_tier unless the user says \"cinematic\"/\"premium\"/\"high quality\" (then upgrade).";
        }
        if ($workspace->cruise_visual_source) {
            $lines[] = match ($workspace->cruise_visual_source) {
                'stock_video' => '- Visual source bias: STOCK VIDEO. For vague "add a visual" / "swap the visual" intents, route to find_stock_video. For specific creative descriptions, regenerate_image still wins.',
                'stock_image' => '- Visual source bias: STOCK IMAGE. Same rule with find_stock_image.',
                'audiogram'   => '- Visual source bias: AUDIOGRAM. Same rule with set_audiogram_visual.',
                default       => '',
            };
        }
        if (empty($lines)) return '';
        return "USER PREFERENCES (hints — explicit user wording always wins)\n" . implode("\n", $lines) . "\n";
    }

    /**
     * @param string $intent          user's free-text prompt
     * @param Project $project        the project being edited
     * @param ?Scene $scope           the scene the user is focused on (null = whole project)
     * @param array<int, array{role:string, text:string}> $history  prior turns
     *                                (oldest first). Used so the LLM can resolve
     *                                pronouns like "it" across turns. Capped to
     *                                the last 6 entries before sending.
     * @return array{reply_to_user:string, action:?array, actions:array<int,array>}
     */
    public function resolve(string $intent, Project $project, ?Scene $scope, array $history = []): array
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return [
                'reply_to_user' => 'Assistant is not configured. Please ask Kolawole to set OPENAI_API_KEY.',
                'action' => null,
                'actions' => [],
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
                return ['reply_to_user' => "I couldn't understand that — try rephrasing.", 'action' => null, 'actions' => []];
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $parsed  = json_decode($content, true);

            if (! is_array($parsed)) {
                return ['reply_to_user' => "I got a malformed answer — try rephrasing.", 'action' => null, 'actions' => []];
            }

            $reply = trim((string) ($parsed['reply_to_user'] ?? "Okay."));

            // Accept either: actions[] (preferred multi-action shape) or
            // action (legacy single). Normalise into actions[] and keep
            // action populated with the first entry for back-compat.
            $rawActions = [];
            if (isset($parsed['actions']) && is_array($parsed['actions'])) {
                $rawActions = $parsed['actions'];
            } elseif (is_array($parsed['action'] ?? null)) {
                $rawActions = [$parsed['action']];
            }

            // Hard cap to keep the chat readable + cost predictable.
            $rawActions = array_slice($rawActions, 0, 6);

            $actions = [];
            foreach ($rawActions as $raw) {
                if (! is_array($raw)) continue;
                $toolName = (string) ($raw['tool'] ?? '');
                $tool = $this->registry->get($toolName);
                if (! $tool) {
                    Log::info('CruiseControl LLM proposed unknown tool', ['tool' => $toolName]);
                    continue;
                }
                $params = is_array($raw['params'] ?? null) ? $raw['params'] : [];
                $actions[] = [
                    'tool'               => $toolName,
                    'params'             => $params,
                    'diff_lines'         => $tool->diffLines($project, $params),
                    'estimated_cost'     => $tool->estimateCost($project, $params),
                    'confirmation_class' => $tool->confirmationClass(),
                    'affected_section'   => $tool->affectedSection(),
                ];
            }

            // If the LLM tried to propose actions but ALL of them were
            // invalid tools, fall back to the friendly catalogue reply
            // instead of returning an empty array silently.
            if (! empty($rawActions) && empty($actions)) {
                return [
                    'reply_to_user' => "I can't do that yet — try a voice swap, visual change, or music regen.",
                    'action' => null,
                    'actions' => [],
                ];
            }

            return [
                'reply_to_user' => $reply,
                'action'  => $actions[0] ?? null,
                'actions' => $actions,
            ];
        } catch (\Throwable $e) {
            Log::error('CruiseControl resolve exception', ['message' => $e->getMessage()]);
            return ['reply_to_user' => "Something went wrong on my end — try again.", 'action' => null, 'actions' => []];
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
            ->get(['id', 'scene_order', 'label', 'script_text',
                   'voice_settings_json', 'visual_style', 'character_id',
                   'visual_type']);

        // Per-scene voice + style + character so the LLM can keep new
        // scenes consistent with existing ones ("match the previous
        // scene's voice"). Without this the LLM had to ask or guess.
        $sceneList = $scenes->map(function ($s) {
            $voiceId = data_get($s->voice_settings_json, 'voice_id', '?');
            $bits = [
                "id={$s->id}",
                "order={$s->scene_order}",
                "voice={$voiceId}",
            ];
            if ($s->visual_style)  $bits[] = "style={$s->visual_style}";
            if ($s->character_id)  $bits[] = "character_id={$s->character_id}";
            if ($s->visual_type)   $bits[] = "visual={$s->visual_type}";
            $bits[] = 'label="' . mb_substr((string) $s->label, 0, 30) . '"';
            $bits[] = 'script="' . mb_substr((string) $s->script_text, 0, 70) . '"';
            return '  - ' . implode(' ', $bits);
        })->implode("\n");

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

        // Brand kits — same idea. Lets the LLM resolve "use my Acme kit" to
        // a brand_kit_id for apply_brand_kit.
        $brandKits = \App\Models\BrandKit::query()
            ->where('workspace_id', $project->workspace_id)
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name']);
        $kitList = $brandKits->isEmpty()
            ? '  (none)'
            : $brandKits->map(fn ($k) => "  - id={$k->id} name=\"{$k->name}\"")->implode("\n");

        $scopeBlock = $scope
            ? "User's current focus: Scene {$scope->scene_order} (id={$scope->id})."
            : "User's current focus: the whole project (no specific scene selected).";

        // Workspace defaults — bias the LLM towards the user's saved prefs
        // when they didn't explicitly name a model/tier/source. Hints only;
        // explicit user wording still wins.
        $workspace = \App\Models\Workspace::query()->whereKey($project->workspace_id)->first();
        $prefsBlock = $this->buildPrefsBlock($workspace);

        $tools = $this->registry->promptCatalog();

        return <<<SYS
You are the WyvStudio video editor assistant. The user is editing a
short-form video. Resolve their intent to ONE tool call.

PROJECT
  id: {$project->getKey()}
  title: {$project->title}
  aspect_ratio: {$project->aspect_ratio}
  scenes: {$scenes->count()}
  tone: {$project->tone}
  default_voice: {$this->projectDefaultVoice($project)}
  default_visual_style: {$project->ai_broll_style}

SCENES (voice, style, character per scene — keep new actions consistent)
{$sceneList}

CHARACTERS (saved in workspace)
{$characterList}

BRAND KITS (saved in workspace)
{$kitList}

{$scopeBlock}

{$prefsBlock}
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
- "image AND animation" / "[description] and animate it" / "make a
  new image and make it move" -> ONE call to regenerate_image with
  chain_animate_tier set (default "quick"). Do NOT propose a separate
  animate_scene action — chain_animate_tier covers both in one apply.
- "add a [scene/cta/intro/outro]" -> add_scene. Write the script in
  first/second person speech. Visual prompt is a concrete image
  description.

WRITING IMAGE PROMPTS — be the prompt engineer the user isn't
- When a tool takes a prompt_override or visual_prompt, write a RICH
  500–1000 character prompt. Do NOT echo the user's short request.
- Cover: SUBJECT (who/what, pose, expression, clothing), SETTING
  (location, environment, props), LIGHTING (golden hour / overcast /
  candlelit / harsh studio), CAMERA (wide shot / close-up / over-the-
  shoulder / low angle), MOOD (intimate / triumphant / melancholic),
  STYLE CUES (cinematic, hyperreal, painterly), and concrete TEXTURAL
  DETAILS (fabric, weather, surfaces, atmosphere).
- Keep it in one flowing block of natural English, comma-separated
  phrases — not a bulleted list.
- The user said "a man holding a woman under a tree facing a
  cathedral" → you write "A tender mid-shot from behind of a young
  man in a charcoal wool overcoat holding a woman in an ivory cotton
  dress close to his side, her hand resting on his shoulder, both
  silhouetted under the heavy canopy of an ancient oak whose gnarled
  branches frame the scene. Soft golden-hour light filters through
  the leaves, dappling their backs and the dusty path ahead.
  Twenty metres away rises a gothic stone cathedral with tall arched
  windows, weathered grey limestone walls and a high spire piercing
  the warm honey sky. Cinematic depth of field, faint dust motes in
  the air, painterly atmosphere, photoreal, unposed and intimate."
  (~800 chars — that's the bar.)

CONSISTENCY WITH EXISTING SCENES
- Read the SCENES list above. When ADDING a scene or RE-RECORDING
  a voice without an explicit voice_id from the user, MATCH the
  voice_id that the majority of existing scenes use (or the
  project default_voice). Do NOT silently switch to 'alloy'.
- When ADDING a scene without an explicit style, MATCH the project's
  default_visual_style (or the style most scenes use).
- When ADDING a scene, write the script_text so it flows naturally
  from the closest existing scene — pick up its tone, callbacks, and
  any concrete nouns the project has already established (subject,
  location, brand).
- When ADDING a scene right after a character-locked scene, copy the
  character_id forward unless the user names a different character.

CLARIFY ONLY IF YOU MUST
- If the user gave you enough to act, ACT. Don't ask for asset IDs
  unless the user explicitly named an existing asset.
- If the user is genuinely ambiguous, set action=null and ask ONE
  short clarifying question. Otherwise just resolve.
- If the user asks for something not supported, set action=null and
  briefly say what you CAN do (list 2-3 capabilities, not all 6).

MULTIPLE ACTIONS IN ONE REQUEST
- You CAN propose multiple actions in one turn. Emit them in the
  actions[] array in the order they should run. Hard cap: 6 per
  turn. The user gets a stack of cards with an "Apply all" button.
- "Change voice on scenes 1 and 5 and animate scene 5" → THREE
  actions: rerecord_voice(scene 1), rerecord_voice(scene 5),
  animate_scene(scene 5). Same scene in multiple actions is fine.
- "Rerecord voice on scenes 1, 3, 5" → THREE rerecord_voice
  actions, one per scene. Don't try to be clever and only do one.
- chain_animate_tier on regenerate_image is still the one
  in-action exception: image + animation for the SAME scene in
  one apply (no need for a separate animate_scene).
- reply_to_user should briefly summarise the plan when there's >1
  action: "I'll rerecord scene 1 + 5 voices, then animate scene 5.
  Tap Apply all when ready."

- reply_to_user is one sentence, conversational, present-tense.

RESPONSE — STRICT JSON, NO MARKDOWN:
{
  "reply_to_user": "...",
  "actions": [
    { "tool": "<one of the tool names above>", "params": { ... } }
    // 0 actions = clarifying question or unsupported
    // 1 action  = single proposal
    // 2-6 actions = multi-action plan, runs sequentially
  ]
}
SYS;
    }
}
