<?php

namespace App\Services\Generation\AI;

use InvalidArgumentException;

class PromptTemplateRegistry
{
    /**
     * @return array{system:string,user:string}
     */
    public function template(string $key): array
    {
        return match ($key) {
            'script_from_prompt' => [
                'system' => 'You are a short-form video script writer. Return plain script text only.',
                'user' => "Create a concise social video script.\nTone: {{tone}}\nGoal: {{content_goal}}\nLanguage: {{language}}\nSource: {{source_content}}",
            ],
            'script_from_url' => [
                'system' => 'You are a short-form video script writer. Rewrite source material into plain script text only.',
                'user' => "Rewrite this URL-derived content into a short-form video script.\nTone: {{tone}}\nGoal: {{content_goal}}\nLanguage: {{language}}\nSource: {{source_content}}",
            ],
            'scene_breakdown' => [
                'system' => 'You split scripts into scenes. Return JSON only in this shape: {"scenes":[{"scene_type":"hook|narration|transition|text_card|quote","label":"...","script_text":"...","duration_seconds":number}]}',
                'user' => "Break this script into 1-20 scenes for short-form video.\nLanguage: {{language}}\nScript:\n{{script_text}}",
            ],
            'hook_options' => [
                'system' => 'Generate hook options for a short-form video. Return JSON only in this shape: {"hooks":[{"text":"..."}]} with 3 to 10 options.',
                'user' => "Generate 3-10 hook options from this script.\nLanguage: {{language}}\nScript:\n{{script_text}}",
            ],
            'scene_rewrite' => [
                'system' => 'You rewrite exactly one short-form video scene. Return plain rewritten scene text only. Do not add labels, bullets, notes, or extra commentary. Preserve all locked facts and keep the output in the same language as the input. Maintain continuity with adjacent scenes.',
                'user' => "Rewrite this single scene for a short-form video.\nProject: {{project_title}}\nMode: {{mode}}\nLanguage: {{language}}\nScene type: {{scene_type}}\nScene label: {{scene_label}}\nPrevious scene:\n{{previous_scene}}\nCurrent scene:\n{{script_text}}\nNext scene:\n{{next_scene}}\nScene outline:\n{{scene_outline}}",
            ],
            'scene_insert' => [
                'system' => 'You write exactly one new short-form video scene that fits naturally into an existing sequence. Return plain scene text only. Do not add labels, bullets, notes, or explanations.',
                'user' => "Create one new scene.\nProject: {{project_title}}\nLanguage: {{language}}\nTone: {{tone}}\nRequested scene type: {{scene_type}}\nUser draft seed:\n{{current_text}}\nPrevious scene:\n{{previous_scene}}\nNext scene:\n{{next_scene}}\nScene outline:\n{{scene_outline}}",
            ],
            default => throw new InvalidArgumentException("Unknown prompt template key: {$key}"),
        };
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function render(string $template, array $variables): string
    {
        $rendered = $template;

        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', (string) $value, $rendered);
        }

        return $rendered;
    }
}
