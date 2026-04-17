<?php

namespace App\Services\Generation\Translation;

interface TranslationAdapter
{
    /**
     * @param  array<int, string>  $texts
     * @return array{translations:array<int,array{source:string,translated:string}>,provider_key:string,source_language:string,target_language:string}
     */
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage, ?string $contextHint = null, bool $preserveFormatting = true): array;
}
