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
    /**
     * The identity read (owner, 2026-10-12: Opus reads the face and body, Seedance gets text): every cue that makes this
     * person this person, and never their clothes, which each ad chooses for itself. Version 2 replaces the thinner
     * first read (eight short fields from a small model, clothes included).
     */
    private const VERSION = 2;

    private const FIELDS = ['age', 'gender_presentation', 'skin', 'face_shape', 'eyes', 'brows', 'nose', 'lips', 'jaw_cheeks',
        'hair', 'facial_hair', 'head_covering', 'glasses', 'build', 'distinctive'];

    private const PROMPT = <<<'TXT'
Describe this person so precisely that a video model could recreate the same face and body from words alone. Describe only what is visible; never guess. Leave out their clothes and the background entirely.

Answer JSON only, each value a short concrete phrase (empty when it does not apply):
{"age": "e.g. mid-thirties", "gender_presentation": "", "skin": "exact shade and undertone, e.g. warm medium-brown with golden undertone", "face_shape": "", "eyes": "shape, colour, spacing, lids", "brows": "", "nose": "", "lips": "", "jaw_cheeks": "jawline, cheekbones, chin", "hair": "colour, texture, length, parting, how it is worn", "facial_hair": "", "head_covering": "e.g. a soft beige hijab wrapped close around the face", "glasses": "", "build": "", "distinctive": "freckles, moles, dimples, marks, anything that identifies them"}
TXT;

    public function __construct(
        private readonly AIGenerationAdapter $ai,
        private readonly StorageService $storage,
    ) {
    }

    /** @return array<string, string>|null */
    public function sheet(Character $character): ?array
    {
        $cached = $character->appearance_json;
        if (is_array($cached) && (int) ($cached['v'] ?? 1) >= self::VERSION) {
            return array_diff_key($cached, ['v' => 1]);
        }

        $asset = Asset::query()->find($character->reference_asset_id);
        $bytes = $asset ? $this->storage->get((string) $asset->storage_url) : null;
        $key = (string) config('services.anthropic.key');
        if (! $bytes || strlen($bytes) > 8 * 1024 * 1024 || $key === '') {
            return null;
        }

        try {
            $jpeg = self::jpeg($bytes);
            $r = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.planner_model_creative', 'claude-opus-5-5'), 'max_tokens' => 1500,
                    'messages' => [['role' => 'user', 'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $jpeg !== null ? 'image/jpeg' : ($asset->mime_type ?: 'image/png'), 'data' => base64_encode($jpeg ?? $bytes)]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ]]]]);
            if (! $r->successful()) return null;
            $parsed = UgcPlan::decodeModelJson(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
        } catch (\Throwable) {
            return null;
        }

        $sheet = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($parsed[$field] ?? ''));
            if ($value !== '' && ! preg_match('/^(none|n\/a|no|not visible|empty)\.?$/i', $value)) $sheet[$field] = mb_substr($value, 0, 200);
        }
        if (count($sheet) < 6) {
            return null; // a thin read is worse than the saved description
        }

        $character->forceFill(['appearance_json' => $sheet + ['v' => self::VERSION]])->save();

        return $sheet;
    }

    /** The sheet as one prompt-ready description of the person (never their clothes); falls back to the saved description. */
    public function text(Character $character): string
    {
        $sheet = $this->sheet($character);
        if ($sheet === null) {
            return trim($character->name.($character->description ? ' — '.$character->description : ''));
        }
        // Written as sentences, not fragments: video models weight fluent descriptions better.
        $lead = trim(implode(', ', array_filter([$sheet['gender_presentation'] ?? 'person', $sheet['age'] ?? null])), ', ');
        $parts = ['A '.$lead.(isset($sheet['skin']) ? ' with '.$sheet['skin'].' skin' : '')];
        $labels = ['face_shape' => 'face', 'eyes' => 'eyes', 'brows' => 'brows', 'nose' => 'nose', 'lips' => 'lips', 'jaw_cheeks' => 'jaw and cheeks',
            'hair' => 'hair', 'facial_hair' => 'facial hair', 'head_covering' => 'head covering', 'glasses' => 'glasses', 'build' => 'build', 'distinctive' => 'distinctive'];
        foreach ($labels as $field => $label) {
            if (isset($sheet[$field])) $parts[] = ucfirst($label).': '.rtrim($sheet[$field], '.');
        }

        return implode('. ', $parts).'.';
    }

    private static function jpeg(string $bytes): ?string
    {
        $in = tempnam(sys_get_temp_dir(), 'face-');
        $out = $in.'.jpg';
        file_put_contents($in, $bytes);
        \Illuminate\Support\Facades\Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-i', $in, '-vf', "scale='min(1024,iw)':-2", '-q:v', '3', $out]);
        $jpeg = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($in); @unlink($out);

        return $jpeg !== '' ? $jpeg : null;
    }
}
