<?php

namespace App\Services\Ugc;

use App\Services\Generation\Image\NanoBananaProImageAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The take's look (owner, 2026-10-12): the cast presenter, dressed for this ad, in this ad's own version of the place,
 * made once and used as the presenter's reference in every clip instead of their photo from the cast. Take 291 sent the
 * cast photo (a denim shirt) with "wearing a rust sweater" in the text, and the first clip wore the denim; the place
 * photo went as a loose hint, and the room changed between clips. One picture now carries the face, the clothes and the
 * room together; the clip text carries only the action. The place is the ad's own version: the same kind and feel, its
 * own details, so two ads in the same place do not look alike.
 */
class UgcLook
{
    /** Every clip copies this picture's face, so it is held to a higher mark than a clip (2026-10-15: looks at 78 led to clips at 72). */
    public const PASS = 85;

    public function __construct(private readonly NanoBananaProImageAdapter $images, private readonly UgcFaceCheck $faces)
    {
    }

    /**
     * The dressed presenter as a data URI, with how closely it matched their photo; null when it could not be made (the
     * take then keeps the cast photo). A look under its pass mark (85) is made once more and the closer kept.
     *
     * @return array{uri: string, score: int|null}|null
     */
    public function make(string $presenter, ?string $place, string $wardrobe, string $placeText): ?array
    {
        $prompt = self::prompt($wardrobe, $placeText, $place !== null);
        $best = null;
        for ($try = 0; $try < 2; $try++) {
            try {
                $out = $this->images->generate($prompt, '', '9:16', ['reference_image_urls' => array_values(array_filter([$presenter, $place]))]);
                $bytes = Http::timeout(60)->get((string) $out['image_url'])->throw()->body();
            } catch (\Throwable $e) {
                Log::warning('ugc look: the image could not be made', ['error' => mb_substr($e->getMessage(), 0, 200)]);
                continue;
            }
            if ($bytes === '') continue;
            $uri = 'data:image/png;base64,'.base64_encode($bytes);
            $score = $this->faces->scoreImage($presenter, $uri);
            if ($best === null || ($score ?? 0) > ($best['score'] ?? 0)) $best = ['uri' => $uri, 'score' => $score];
            if ($score === null || $score >= self::PASS) break;
        }

        return $best;
    }

    public static function prompt(string $wardrobe, string $placeText, bool $hasPlace): string
    {
        $place = trim((string) preg_replace('/^this kind of place:\s*/i', '', $placeText));

        return 'A natural phone photo of the person in image 1: exactly the same face, skin tone, hair, any head covering and age. '
            .'They wear '.rtrim($wardrobe, '.').'. '
            .($place !== '' ? 'They are in '.rtrim($place, '.').($hasPlace ? ', the same kind of place as image 2 with the same feel, but your own version of it: its own layout and everyday details' : '').'. ' : '')
            .'Seen from the waist up, facing the camera at eye level, relaxed and friendly, hands empty and resting naturally. '
            .'Soft natural light, real skin texture, an ordinary lived-in room with plain walls and objects, free of writing and logos.';
    }
}
