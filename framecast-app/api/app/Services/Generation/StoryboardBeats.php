<?php

namespace App\Services\Generation;

use App\Models\Asset;
use App\Services\Ugc\ProductVision;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The shot read as three moments before the storyboard is drawn (owner, 2026-10-31). The image model was handed the
 * whole description three times with only "opening / middle / final moment" added, so "she's tired of sitting and
 * raises the desk" came back as three copies of the reference: standing at a raised desk. A cheap model reads the
 * description (and the reference, when there is one) and says what each frame shows and what stays the same; the
 * video is then asked for the motion from the picked frame onwards.
 */
class StoryboardBeats
{
    private const PROMPT = 'You plan a 3-frame storyboard for one short video shot (about 5 to 10 seconds). The frames are drawn by an image model, one at a time, and the user then picks the frame the video starts from. '
        .'Split the shot described below into its start, middle and end. Each frame is a single still, in two or three sentences: say where the people are and how they are posed, the state of every object that changes '
        .'(for example: a desk at sitting height, the desk half way up, the desk at standing height), and the camera framing. Make the frames visibly different whenever the action changes something; '
        .'when the shot has little action, let the camera or the subject move a little between frames. Follow the description over the picture: when the reference shows a later or different moment than the start, the start frame still shows the start. '
        .'"keep" says concretely who and what stays the same in every frame (people\'s look and clothes, product, place, light, style){REF}. '
        .'"motion" says, for a video model, what happens from each frame to the end of the shot, in one or two plain sentences each (start to end; middle to end; end: how the shot settles). '
        .'Answer JSON only: {"frames": ["start", "middle", "end"], "keep": "...", "motion": ["from start", "from middle", "from end"]}. Shot: ';

    /**
     * Three frame descriptions, what stays the same, and the motion from each frame; null when the model could not be
     * asked or answered badly (the storyboard is then drawn from the description alone, as before).
     *
     * @return array{frames: list<string>, keep: string, motion: list<string>}|null
     */
    public function plan(string $prompt, ?Asset $reference, int $workspaceId): ?array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') return null;
        if (empty(\App\Services\Usage\UsageContext::current()['workspace_id'])) \App\Services\Usage\UsageContext::set($workspaceId);

        $content = [];
        $jpeg = $reference ? ProductVision::small($reference) : null;
        if ($jpeg !== null) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]];
        $ref = $jpeg !== null
            ? ', taken from the reference image above, which every frame is drawn to match'
            : '; there is no reference image, so invent one specific look and say it fully, since each frame is drawn from it';
        $content[] = ['type' => 'text', 'text' => str_replace('{REF}', $ref, self::PROMPT).mb_substr(trim($prompt), 0, 1000)];

        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 900,
                    'messages' => [['role' => 'user', 'content' => $content]]]);
        } catch (\Throwable $e) {
            Log::warning('storyboard beats: the call failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $r->successful()) {
            Log::warning('storyboard beats: the call failed', ['status' => $r->status()]);

            return null;
        }

        return self::parse(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
    }

    /** The model's answer as a plan; null unless it names three frames. */
    public static function parse(string $text): ?array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json)) return null;
        $lines = fn ($list, int $max) => array_values(array_map(fn ($s) => mb_substr(trim($s), 0, $max), array_filter(is_array($list) ? $list : [], fn ($s) => is_string($s) && trim($s) !== '')));
        $frames = array_slice($lines($json['frames'] ?? null, 800), 0, 3);
        if (count($frames) !== 3) return null;
        $motion = array_slice($lines($json['motion'] ?? null, 400), 0, 3);

        return ['frames' => $frames, 'keep' => mb_substr(trim(is_string($json['keep'] ?? null) ? $json['keep'] : ''), 0, 500), 'motion' => count($motion) === 3 ? $motion : []];
    }
}
