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
