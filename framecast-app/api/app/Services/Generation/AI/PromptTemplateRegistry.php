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
