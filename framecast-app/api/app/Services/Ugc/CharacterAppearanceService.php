<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Models\Character;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Media\StorageService;

/**
 * A casting sheet read off the character's reference image, cached on the
 * row. It exists for engines that refuse face IMAGES (Seedance's likeness
 * filter): a detailed text description is the sanctioned way to get a close
 * variant of the character — the photo itself never leaves our storage.
 */
class CharacterAppearanceService
{
    private const FIELDS = ['age_range', 'gender_presentation', 'skin_tone', 'hair', 'face', 'build', 'style', 'distinctive'];

    public function __construct(
        private readonly AIGenerationAdapter $ai,
        private readonly StorageService $storage,
    ) {
    }

    /** @return array<string, string>|null */
    public function sheet(Character $character): ?array
    {
        $cached = $character->appearance_json;
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $asset = Asset::query()->find($character->reference_asset_id);
        $bytes = $asset ? $this->storage->get((string) $asset->storage_url) : null;
        if (! $bytes || strlen($bytes) > 8 * 1024 * 1024) {
            return null;
        }

        try {
            $result = $this->ai->generate('character_appearance_read', [], 500, 0.2, [
                'images' => [[
                    'url' => 'data:'.(($asset->mime_type ?: 'image/png')).';base64,'.base64_encode($bytes),
                    'title' => 'Character reference',
                ]],
                // Faces need real detail — 'low' is for "what is this a picture of".
                'image_detail' => 'high',
            ]);
            $parsed = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        $sheet = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($parsed[$field] ?? ''));
            if ($value !== '') {
                $sheet[$field] = mb_substr($value, 0, 120);
            }
        }
        if (count($sheet) < 4) {
            return null; // a thin read is worse than the saved description
        }

        $character->forceFill(['appearance_json' => $sheet])->save();

        return $sheet;
    }

    /** The sheet as one prompt-ready description; falls back to the saved description. */
    public function text(Character $character): string
    {
        $sheet = $this->sheet($character);
        if ($sheet === null) {
            return trim($character->name.($character->description ? ' — '.$character->description : ''));
        }

        // Written as a sentence, not telegraphic fragments — video models
        // weight fluent descriptions better, and the identity-critical
        // fields (skin, hair) get explicit nouns they cannot skim past.
        $lead = trim(implode(', ', array_filter([
            $sheet['gender_presentation'] ?? 'person', $sheet['age_range'] ?? null,
        ])), ', ');
        $parts = ['a '.$lead];
        if (isset($sheet['skin_tone'])) {
            $parts[0] .= ' with '.$sheet['skin_tone'].' skin';
        }
        if (isset($sheet['hair'])) {
            $parts[0] .= (isset($sheet['skin_tone']) ? ' and ' : ' with ').$sheet['hair'].' hair';
        }
        foreach (['face', 'build', 'style', 'distinctive'] as $field) {
            if (isset($sheet[$field])) {
                $parts[] = $sheet[$field];
            }
        }

        return implode('; ', $parts);
    }
}
