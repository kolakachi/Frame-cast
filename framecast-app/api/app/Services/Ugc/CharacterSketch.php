<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Models\Character;
use App\Services\Generation\Image\CharacterImageAdapter;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A presenter's pencil character sheet (owner, 2026-10-18): how their look reaches Seedance, which declines any picture
 * it reads as a real person but renders a grayscale drawing as a real one (the Layla tests: hair, build and features
 * carry over; the face is a close resemblance, not an exact match). Made once from the character's own photo, kept on
 * the character, and made again only when that photo changes. Our cost (about $0.30), never the user's.
 */
class CharacterSketch
{
    public const PROMPT = 'A hand-drawn realistic graphite pencil character reference sheet of this person, grayscale only, fine cross-hatching and soft shading on white sketch paper, visible pencil strokes, clearly a drawing. Three panels side by side on plain paper: LEFT a head-and-shoulders close-up looking at the viewer with a relaxed natural expression; CENTRE a full-body front view standing straight, arms relaxed; RIGHT a full-body back view showing their hair from behind. Plain simple everyday clothes, the same in every panel. Keep their exact identity in every panel: the same face shape, eyes, brows, nose, lips, skin texture, hair and build. No text, no labels.';

    public function __construct(private readonly StorageService $storage)
    {
    }

    /** The sheet as a data URI, made first if this character has none for its current photo; null if it cannot be. */
    public function uri(Character $character): ?string
    {
        $bytes = $this->bytes($character);

        return $bytes !== null ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }

    private function bytes(Character $character): ?string
    {
        if (! $character->reference_asset_id) return null;
        if (($saved = $this->saved($character)) !== null) return $saved;
        // One sheet per character even when two takes cast them at once.
        return Cache::lock('character-sketch:'.$character->getKey(), 240)->block(230, function () use ($character) {
            $character->refresh();
            if (($saved = $this->saved($character)) !== null) return $saved;
            $photo = Asset::query()->find($character->reference_asset_id);
            $source = $photo ? $this->storage->get((string) $photo->storage_url) : null;
            if (! is_string($source) || $source === '') return null;
            $made = app(CharacterImageAdapter::class)->generate(self::PROMPT, 'none', '16:9', [
                'reference_image_url' => 'data:'.($photo->mime_type ?: 'image/jpeg').';base64,'.base64_encode($source),
            ]);
            $png = ! empty($made['image_b64']) ? base64_decode((string) $made['image_b64'], true)
                : (! empty($made['image_url']) ? @file_get_contents((string) $made['image_url']) : false);
            if (! is_string($png) || $png === '') return null;
            $path = sprintf('characters/%d/sketch-%s.png', $character->getKey(), Str::uuid());
            $asset = Asset::query()->create([
                // Kept with the photo it was drawn from: a stock presenter has no workspace, its photo does (assets need one).
                'workspace_id' => $photo->workspace_id ?? $character->workspace_id,
                'asset_type' => 'image',
                'title' => mb_substr($character->name.' — pencil character sheet', 0, 255),
                'storage_url' => $this->storage->put($path, $png, ['ContentType' => 'image/png']),
                'mime_type' => 'image/png',
                'file_size_bytes' => strlen($png),
                'tags' => ['character_sketch'],
                'status' => 'active',
            ]);
            $character->forceFill(['sketch_json' => ['asset_id' => $asset->getKey(), 'from_asset_id' => (int) $character->reference_asset_id,
                'made_at' => now()->toIso8601String()]])->save();

            return $png;
        });
    }

    /** The saved sheet, while it was made from the character's current photo. */
    private function saved(Character $character): ?string
    {
        $sketch = (array) ($character->sketch_json ?? []);
        if ((int) ($sketch['from_asset_id'] ?? 0) !== (int) $character->reference_asset_id || empty($sketch['asset_id'])) return null;
        $asset = Asset::query()->find((int) $sketch['asset_id']);
        $bytes = $asset ? $this->storage->get((string) $asset->storage_url) : null;

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
