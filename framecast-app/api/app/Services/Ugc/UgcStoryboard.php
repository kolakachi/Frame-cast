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
        $stills = [];
        foreach (array_keys(array_values($chunks)) as $i) $stills[] = $this->one($chunks, $refs, $i, $stills[0] ?? null);

        return $stills;
    }

    /** One clip's still; a later still is drawn from the opening one. */
    public function one(array $chunks, array $refs, int $i, ?string $opening): string
    {
        $presenter = $refs[0] ?? null;
        $product = $refs[1] ?? null;
        $prompt = (string) (array_values($chunks)[$i]['prompt'] ?? '');
        $face = ! self::handsOnly($prompt);
        // The product photo first (take 287: a hands-only close-up given it last, behind the opening frame, redrew the label).
        $images = array_values(array_filter($i === 0 ? [$product, $presenter, $refs[2] ?? null] : [$product, $presenter, $opening !== null ? self::small($opening) : null]));

        return $this->still(self::prompt($prompt, $i, $product !== null, $i === 0 && isset($refs[2]), $face), $images);
    }

    /** A shot framed on hands and the product, with no face in it. */
    public static function handsOnly(string $clipPrompt): bool
    {
        $scene = (string) (explode("\n\n", $clipPrompt)[1] ?? '');
        return (bool) preg_match('/\b(framed on|close[- ]up on|close framing on|top-down)\b[^.]*\b(hands?|palm)\b/iu', $scene) && ! preg_match('/\b(face|talks|says|smiles|selfie)\b/iu', $scene);
    }

    /** What the still is asked for: the shot's own description and staging, the references named by position. */
    public static function prompt(string $clipPrompt, int $i, bool $hasProduct, bool $hasPlace, bool $face = true): string
    {
        $parts = explode("\n\n", $clipPrompt);
        $scene = (string) ($parts[1] ?? $parts[0] ?? '');
        // A still has no sound: the spoken lines and the rules about them go.
        $scene = (string) preg_replace('/\s*The presenter says[^"]*"[^"]*"\.?/u', '', $scene);
        $scene = (string) preg_replace('/\s*(They say exactly these words|No speech, narration).*$/su', '', $scene);
        $scene = (string) preg_replace('/^(The next shot of the same presenter in the same room[^.]*\.\s*)?(One silent shot from the first frame to the last, nothing before or after it:\s*)?/u', '', trim($scene));
        // The action comes after this frame (take 287's pour still showed the peanuts already in her palm).
        $action = '';
        if (preg_match('/\s*Action and sound:\s*(.*?)(?=\s*Staging:|$)/su', $scene, $m)) {
            $action = trim((string) preg_replace('/(?<=\.)\s.*$/su', '', trim($m[1])));
            $scene = trim(str_replace($m[0], '', $scene));
        }
        $staging = collect($parts)->first(fn ($p) => str_starts_with($p, 'Physical staging, follow exactly: '));

        $n = 1;
        $names = [];
        if ($hasProduct) $names[] = 'Image '.($n++).' is the product: copy its packaging exactly as printed, the logo, lettering, illustration and colours; never redesign or retype it.';
        $names[] = 'Image '.($n++).' is the presenter'.($face ? ': keep their face, skin tone and hair exactly.' : ': only their hands and sleeves are in this shot, with their skin tone.');
        if ($i > 0) $names[] = 'Image '.($n++).' is the opening frame of this same video: keep its exact room, furniture, walls, window, light, and the presenter\'s outfit.';
        elseif ($hasPlace) $names[] = 'Image '.($n++).' shows only the kind of place; make your own version of it.';

        return 'One frame from a vertical 9:16 creator video filmed on a phone: the first moment of this shot. '.implode(' ', $names)
            ."\n\nThis shot: ".trim($scene)
            .($action !== '' ? "\nRight after this frame: ".$action.' This frame is the moment before it, so nothing of it has happened yet.' : '')
            .($staging ? "\n".trim($staging) : '')
            ."\n\nPhotoreal phone-camera image, natural light, real skin texture. Exactly one of the product. No phone in frame unless the shot says it is propped in view.";
    }

    /** A reference copy of a still, small enough to send alongside the others. */
    private static function small(string $uri): string
    {
        $in = tempnam(sys_get_temp_dir(), 'sb-').'.png';
        $out = tempnam(sys_get_temp_dir(), 'sb-').'.jpg';
        file_put_contents($in, base64_decode(substr($uri, strpos($uri, ',') + 1)));
        $ok = \Illuminate\Support\Facades\Process::timeout(30)->run(['ffmpeg', '-y', '-i', $in, '-vf', 'scale=768:-2', '-q:v', '3', $out])->successful() && filesize($out);
        $small = $ok ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($out)) : $uri;
        @unlink($in); @unlink($out);

        return $small;
    }

    private function still(string $prompt, array $images): string
    {
        $out = $this->images->generate($prompt, '', '9:16', ['reference_image_urls' => $images]);
        $bytes = Http::timeout(60)->get((string) $out['image_url'])->throw()->body();
        if ($bytes === '') throw new \RuntimeException('A storyboard still came back empty. Nothing was charged; try again.');

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
