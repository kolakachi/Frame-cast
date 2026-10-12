<?php

namespace App\Services\Ugc;

use App\Services\Generation\Image\NanoBananaProImageAdapter;
use Illuminate\Support\Facades\Http;

/**
 * The storyboard (pilot, 2026-10-12, staff only): one still per clip before any video is made, each clip then starting
 * from its own still. The first still sets the room, the outfit and the light; every later still is drawn from it, so
 * the clips share one room instead of each inventing its own (take 285: white, wood and grey kitchens in one ad). A
 * still shows the first moment of its shot, before the action starts, so the clip performs the action once.
 */
class UgcStoryboard
{
    public function __construct(private readonly NanoBananaProImageAdapter $images)
    {
    }

    /**
     * One still per chunk, as data URIs, in order.
     *
     * @param  list<array<string, mixed>>  $chunks  UgcOneShotCompiler output (after the ready check)
     * @param  list<string>  $refs  the take's reference images: the presenter first, then the product, then any place photo
     * @return list<string>
     */
    public function make(array $chunks, array $refs): array
    {
        $presenter = $refs[0] ?? null;
        $product = $refs[1] ?? null;
        $stills = [];
        foreach (array_values($chunks) as $i => $chunk) {
            $images = $i === 0 ? array_slice(array_values(array_filter([$presenter, $product, $refs[2] ?? null])), 0, 3)
                : array_values(array_filter([$presenter, $stills[0], $product]));
            $stills[] = $this->still(self::prompt((string) ($chunk['prompt'] ?? ''), $i, $product !== null, isset($refs[2])), $images);
        }

        return $stills;
    }

    /** What the still is asked for: the shot's own description and staging, the references named by position. */
    public static function prompt(string $clipPrompt, int $i, bool $hasProduct, bool $hasPlace): string
    {
        $parts = explode("\n\n", $clipPrompt);
        $scene = (string) ($parts[1] ?? $parts[0] ?? '');
        // A still has no sound: the spoken lines and the rules about them go.
        $scene = (string) preg_replace('/\s*The presenter says[^"]*"[^"]*"\.?/u', '', $scene);
        $scene = (string) preg_replace('/\s*(They say exactly these words|No speech, narration).*$/su', '', $scene);
        $scene = (string) preg_replace('/^One silent shot from the first frame to the last, nothing before or after it:\s*/u', '', trim($scene));
        $staging = collect($parts)->first(fn ($p) => str_starts_with($p, 'Physical staging, follow exactly: '));

        $names = $i === 0
            ? 'Image 1 is the presenter: keep their face, skin tone and hair exactly.'.($hasProduct ? ' Image 2 is the product: keep its exact shape, colours and label.' : '')
                .($hasPlace ? ' Image '.($hasProduct ? 3 : 2).' shows only the kind of place; make your own version of it.' : '')
            : 'Image 1 is the presenter: keep their face exactly. Image 2 is the opening frame of this same video: keep its exact room, furniture, walls, window, light, and the presenter\'s outfit and hair.'
                .($hasProduct ? ' Image 3 is the product: keep its exact shape, colours and label.' : '');

        return 'One frame from a vertical 9:16 creator video filmed on a phone: the first moment of this shot, before its action starts. '
            .$names."\n\nThis shot: ".trim($scene).($staging ? "\n".trim($staging) : '')
            ."\n\nPhotoreal phone-camera image, natural light, real skin texture. Exactly one of the product. No phone in frame unless the shot says it is propped in view.";
    }

    private function still(string $prompt, array $images): string
    {
        $out = $this->images->generate($prompt, '', '9:16', ['reference_image_urls' => $images]);
        $bytes = Http::timeout(60)->get((string) $out['image_url'])->throw()->body();
        if ($bytes === '') throw new \RuntimeException('A storyboard still came back empty. Nothing was charged; try again.');

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
