<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateTTSJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Change a scene's spoken script. Two modes:
 *   - direct: user supplies the new text verbatim
 *   - rewrite: user supplies a tone/style hint; the LLM produces a new
 *              version of the existing script in that tone
 *
 * Always marks voice as outdated so GenerateTTSJob re-synthesizes the
 * audio with the new line. Cost = TTS regen (2 cr). Rewrite mode adds a
 * cheap ~$0.0001 LLM call on top, which we eat.
 */
class UpdateSceneScriptTool implements CruiseTool
{
    public function name(): string { return 'update_scene_script'; }

    public function description(): string
    {
        return 'Edit what a scene says. Two modes: provide new_text to replace the script verbatim, OR provide rewrite_tone (e.g. "more punchy", "warmer", "Wes Anderson") to have the assistant rewrite the existing line in that tone. Always re-records the voice afterward.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'new_text' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Verbatim replacement. 1-2 sentences, ~80-180 chars. First/second person — what the voice SAYS, not a description of the scene.',
            ],
            'rewrite_tone' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Tone hint when you want the assistant to rewrite the existing script. e.g. "punchier", "warmer", "more confident", "in a Wes Anderson narration style". Ignored when new_text is set.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'script'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $previous = mb_substr((string) $scene?->script_text, 0, 70);
        $lines = ["Scene: {$scene?->scene_order}", "Was: \"{$previous}\""];
        if (! empty($params['new_text'])) {
            $lines[] = 'New: "' . mb_substr((string) $params['new_text'], 0, 70) . '"';
        } elseif (! empty($params['rewrite_tone'])) {
            $lines[] = 'Rewrite tone: ' . $params['rewrite_tone'];
        }
        $lines[] = 'Voice will be re-recorded';
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int { return CreditService::TTS; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }

        $newText = trim((string) ($params['new_text'] ?? ''));
        $tone    = trim((string) ($params['rewrite_tone'] ?? ''));

        // Rewrite mode — small LLM call to recast the existing line.
        if ($newText === '' && $tone !== '') {
            $newText = $this->rewriteWithTone((string) $scene->script_text, $tone);
        }

        if ($newText === '') {
            throw new RuntimeException('Provide new_text or rewrite_tone.');
        }
        if (mb_strlen($newText) > 1000) {
            throw new RuntimeException('Script is too long (max 1000 chars).');
        }

        $voiceSettings = $scene->voice_settings_json ?? [];
        $voiceSettings['is_outdated'] = true;

        $scene->forceFill([
            'script_text'         => $newText,
            'voice_settings_json' => $voiceSettings,
            'status'              => 'edited',
        ])->save();

        GenerateTTSJob::dispatch($project->getKey());

        return [
            'summary'       => "Updated Scene {$scene->scene_order} script + re-recording voice",
            'credits_spent' => CreditService::TTS,
        ];
    }

    /**
     * Tiny gpt-4o-mini call to recast a line. Our cost (~$0.0001) so we
     * don't charge the user — keeps the rewrite UX friction-free.
     */
    private function rewriteWithTone(string $current, string $tone): string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('Rewrite needs an OpenAI key.');
        }
        try {
            $r = Http::withToken($apiKey)->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
                'model'       => config('services.openai.cheap_model', 'gpt-4o-mini'),
                'temperature' => 0.5,
                'messages'    => [
                    ['role' => 'system', 'content' => "You rewrite short-video scripts. Output ONLY the new line — no quotes, no preamble. 1-2 sentences max. Preserve the meaning; change only the tone."],
                    ['role' => 'user',   'content' => "Current line: \"{$current}\"\nTone wanted: {$tone}\nRewrite:"],
                ],
            ]);
            if (! $r->successful()) {
                Log::warning('UpdateSceneScript rewrite failed', ['status' => $r->status()]);
                throw new RuntimeException('Rewrite failed — try giving me the new text directly.');
            }
            $text = trim((string) data_get($r->json(), 'choices.0.message.content', ''));
            $text = trim($text, "\"' \t\n\r\0\x0B");
            if ($text === '') {
                throw new RuntimeException('Rewrite returned empty — try again.');
            }
            return $text;
        } catch (\Throwable $e) {
            Log::warning('UpdateSceneScript rewrite exception', ['msg' => $e->getMessage()]);
            throw new RuntimeException('Rewrite is unavailable right now — give me the new text directly.');
        }
    }
}
