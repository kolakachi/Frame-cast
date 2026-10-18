<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Models\Character;
use App\Services\Generation\Image\CharacterImageAdapter;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A presenter's pencil character sheet (owner, 2026-10-18): how their look reaches Seedance, which declines any picture
 * it reads as a real person but renders a grayscale drawing as a real one (the Layla tests: hair, build and features
 * carry over; the face is a close resemblance, not an exact match). Made once from the character's own photo, kept in
 * character_sketches (internal: never an asset, never in the library), and made again only when that photo changes.
 * Our cost (about $0.30), never the user's.
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

        return $bytes !== null ? 'data:'.self::mime($bytes).';base64,'.base64_encode($bytes) : null;
    }

    /** The current sheet's file as it is stored, for admin: [bytes, row], or null when there is none for the current photo. */
    public function current(Character $character): ?array
    {
        $row = $this->row($character);
        $bytes = $row ? $this->storage->get((string) $row->storage_url) : null;

        return is_string($bytes) && $bytes !== '' ? [$bytes, $row] : null;
    }

    /** Draw the sheet again from the current photo (admin's Remake), replacing the one in use. */
    public function remake(Character $character, ?int $userId = null): ?string
    {
        DB::table('character_sketches')->where('character_id', $character->getKey())->where('from_asset_id', (int) $character->reference_asset_id)->delete();
        $uri = $this->uri($character);
        if ($uri !== null && $userId !== null) {
            DB::table('character_sketches')->where('character_id', $character->getKey())->where('from_asset_id', (int) $character->reference_asset_id)->update(['made_by_user_id' => $userId]);
        }

        return $uri;
    }

    /** A sheet admin uploaded in place of the drawn one, for the character's current photo. */
    public function upload(Character $character, string $bytes, string $extension, ?int $userId = null): void
    {
        $url = $this->storage->put(sprintf('character-sketches/%d/%s.%s', $character->getKey(), Str::uuid(), $extension), $bytes, ['ContentType' => self::mime($bytes)]);
        DB::table('character_sketches')->updateOrInsert(
            ['character_id' => $character->getKey(), 'from_asset_id' => (int) $character->reference_asset_id],
            ['storage_url' => $url, 'source' => 'uploaded', 'made_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public static function mime(string $bytes): string
    {
        return str_starts_with($bytes, "\xFF\xD8") ? 'image/jpeg' : (str_starts_with($bytes, 'RIFF') ? 'image/webp' : 'image/png');
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
            $url = $this->storage->put(sprintf('character-sketches/%d/%s.png', $character->getKey(), Str::uuid()), $png, ['ContentType' => 'image/png']);
            DB::table('character_sketches')->updateOrInsert(
                ['character_id' => $character->getKey(), 'from_asset_id' => (int) $character->reference_asset_id],
                ['storage_url' => $url, 'source' => 'drawn', 'created_at' => now(), 'updated_at' => now()],
            );

            return $png;
        });
    }

    /** The saved sheet, while it was made from the character's current photo. */
    private function saved(Character $character): ?string
    {
        return $this->current($character)[0] ?? null;
    }

    private function row(Character $character): ?object
    {
        return DB::table('character_sketches')->where('character_id', $character->getKey())
            ->where('from_asset_id', (int) $character->reference_asset_id)->first();
    }
}
