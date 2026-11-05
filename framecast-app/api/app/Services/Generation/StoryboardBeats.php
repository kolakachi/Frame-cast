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
 * video, made from the first frame to the last, is told what happens on the way.
 */
class StoryboardBeats
{
    private const PROMPT = 'You plan a 3-frame storyboard for ONE continuous video shot of about {SECONDS} seconds, filmed by one camera with no cuts. '
        .'The frames are drawn by an image model, one at a time; the video then runs from the first frame to the last, so the three frames must read as moments of the same take. '
        .'Split the shot described below into its start, middle and end. Each frame is a single still, in two or three sentences: where the people are and how they are posed, '
        .'and the state of every object that changes (for example: a desk at sitting height, the desk half way up, the desk at standing height). '
        .'The camera is LOCKED: decide its position, angle and framing once and write it in "camera" (for example: "eye level, from behind them, both figures waist up, cathedral centred"). '
        .'All three frames are seen from exactly that camera. The frame descriptions describe only the people and objects and never mention the camera, a closer or wider view, a new angle or a rotation. '
        .'Size the action to the length: about one simple action per 4 to 5 seconds, the middle frame half way through it. People move only as far as they could in that time; nobody changes place or pose all at once. '
        .'The action moves forward: the end frame shows where it has got to, clearly different from the start, and never returns to the start pose (the video is made from the first and last frames only, so a shot that ends where it began does not move). '
        .'When the shot has little action, the change is small but still forward (he turns to look at her, his hand now in his pocket). Follow the description over the picture: when the reference shows a later or different moment than the start, the start frame still shows the start. '
        .'{CRAFT} '
        .'"keep" says concretely who and what stays the same in every frame (people\'s look and clothes, product, place, light, style){REF}. '
        .'"motion" says, for a video model, what happens from each frame to the end of the shot, in one or two plain sentences each (start to end, through the middle frame; middle to end; end: how the shot settles). '
        .'Answer JSON only, every key required, "camera" first: {"camera": "...", "frames": ["start", "middle", "end"], "keep": "...", "motion": ["from start", "from middle", "from end"]}. Shot: ';

    /**
     * Three frame descriptions, what stays the same, and the motion from each frame; null when the model could not be
     * asked or answered badly (the storyboard is then drawn from the description alone, as before).
     *
     * @return array{frames: list<string>, keep: string, camera: string, motion: list<string>}|null
     */
    public function plan(string $prompt, ?Asset $reference, int $workspaceId, int $seconds = 5): ?array
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
        $content[] = ['type' => 'text', 'text' => str_replace(['{REF}', '{SECONDS}', '{CRAFT}'], [$ref, (string) max(3, min(10, $seconds)), VideoCraft::rules('storyboard')], self::PROMPT).mb_substr(trim($prompt), 0, 1000)];

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

        $text = fn (string $k, int $max) => mb_substr(trim(is_string($json[$k] ?? null) ? $json[$k] : ''), 0, $max);

        return ['frames' => $frames, 'keep' => $text('keep', 500), 'camera' => $text('camera', 300), 'motion' => count($motion) === 3 ? $motion : []];
    }
}
