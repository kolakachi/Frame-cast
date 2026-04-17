<?php

namespace App\Services\Generation\AI;

interface AIGenerationAdapter
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array{content:string,provider_key:string,model:string,tokens_used:int}
     */
    public function generate(string $promptTemplateKey, array $variables, int $maxTokens = 900, float $temperature = 0.4, array $options = []): array;
}
