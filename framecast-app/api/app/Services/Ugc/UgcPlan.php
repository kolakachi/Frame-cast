<?php

namespace App\Services\Ugc;

use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use Illuminate\Validation\ValidationException;

/** Shared validation and pricing for both planning and execution. */
final class UgcPlan
{
    public const FORMATS = ['direct_camera', 'demo', 'story', 'reaction'];

    public const REACTION_TIER = 'quick';

    public const CAMERA = 'Authentic phone-recorded UGC. Eye-level medium close-up, eyes toward the lens, natural window light, casual lived-in setting. Preserve the reference person, outfit and setting across shots. No beauty filter, studio advertising look, text, logos or watermarks. Keep the face unobstructed and leave space above the head for a headline.';

    public const DELIVERY = 'Speak conversationally to one friend, with natural pauses and warmth, not an announcer or a sales pitch. Keep a consistent voice and pace.';

    public static function sameScript(string $a, string $b): bool
    {
        return preg_replace('/\s+/u', ' ', trim($a)) === preg_replace('/\s+/u', ' ', trim($b));
    }

    public static function script(array $segments): string
    {
        return trim(implode(' ', array_filter(array_column($segments, 'script_text'), fn ($text) => $text !== '')));
    }

    public static function normalise(mixed $raw, string $format): array
    {
        if (! in_array($format, self::FORMATS, true) || ! is_array($raw) || count($raw) < 1 || count($raw) > 12) {
            self::invalid('Choose a supported format with 1–12 shots.');
        }
        // Headline is validated below but needed above, where a silent cutaway
        // is only allowed if it carries one.
        $headlineFor = static fn (array $s): string => trim((string) ($s['headline'] ?? ''));

        $out = [];
        foreach ($raw as $seg) {
            if (! is_array($seg)) {
                self::invalid('Each shot must be an object.');
            }
            $kind = $seg['kind'] ?? '';
            $text = trim((string) ($seg['script_text'] ?? ''));
            $brief = trim((string) ($seg['visual_brief'] ?? ''));
            $motion = trim((string) ($seg['motion_prompt'] ?? ''));
            $seconds = (float) ($seg['seconds'] ?? 0);
            if (! in_array($kind, ['on_camera', 'b_roll', 'reaction'], true) || $brief === '' || mb_strlen($brief) > 1000) {
                self::invalid('Every shot needs a supported kind and a visual direction (up to 1,000 characters).');
            }
            if (! is_finite($seconds) || $seconds < 1 || $seconds > 60) {
                self::invalid('Shots must be between 1 and 60 seconds. Split longer speech into intentional takes.');
            }
            // A demo cutaway may be silent. Showing a screen recording or a
            // product shot under a headline, with no voice over it, is the
            // natural beat of a demo — requiring narration everywhere forced
            // the director to invent words for a shot that works better
            // without them. It stays disallowed in direct_camera and story,
            // where a silent shot is a mistake rather than a choice.
            $silentCutawayAllowed = $kind === 'b_roll' && $format === 'demo' && $headlineFor($seg) !== '';

            if ($kind === 'reaction') {
                if ($format !== 'reaction' || $text !== '' || ! in_array($seconds, [5.0, 10.0], true) || $motion === '') {
                    self::invalid('A reaction is one silent 5- or 10-second shot with a motion direction.');
                }
            } elseif ($format === 'reaction') {
                self::invalid('A reaction has one silent shot; it cannot contain spoken shots.');
            } elseif ($text === '' && ! $silentCutawayAllowed) {
                self::invalid($kind === 'b_roll'
                    ? 'A silent cutaway needs a headline, and only a demo can use one. Add narration or a headline.'
                    : 'Talking shots need narration.');
            } elseif (mb_strlen($text) > 1500) {
                self::invalid('Keep each shot under 1,500 spoken characters.');
            }
            if ($kind !== 'reaction') {
                $seconds = max($seconds, round(count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)) / 2.6, 1));
                if ($seconds > 60) {
                    self::invalid('This read exceeds the 60-second clip limit. Split it into separate takes.');
                }
            }
            $source = $kind === 'b_roll' ? ($seg['source'] ?? '') : null;
            if ($kind === 'b_roll' && ! in_array($source, ['upload', 'stock', 'generate'], true)) {
                self::invalid('Choose upload, stock or generate for each cutaway.');
            }
            $headline = trim((string) ($seg['headline'] ?? ''));
            $delivery = trim((string) ($seg['voice_direction'] ?? '')) ?: self::DELIVERY;
            if (mb_strlen($headline) > 180 || mb_strlen($motion) > 1000 || mb_strlen($delivery) > 500) {
                self::invalid('Shorten the headline (180), motion (1,000) or voice direction (500 characters).');
            }
            // Delivery pace. Clamped rather than rejected: a slider that
            // refuses the value it just produced is a worse experience than
            // one that quietly stays inside what the voice can actually do,
            // and outside this range TTS stops sounding human.
            $speed = round(max(0.5, min(2.0, (float) ($seg['speed'] ?? 1.0))), 2);

            $out[] = [
                'kind' => $kind, 'script_text' => $text, 'seconds' => $seconds,
                'visual_brief' => $brief, 'motion_prompt' => $motion,
                'voice_direction' => $delivery, 'speed' => $speed,
                'headline' => $headline, 'source' => $source,
                'asset_id' => $kind === 'b_roll' && $source !== 'generate' ? ((int) ($seg['asset_id'] ?? 0) ?: null) : null,
            ];
        }
        if (mb_strlen(self::script($out)) > 1500 || array_sum(array_column($out, 'seconds')) > 180) {
            self::invalid('Keep the complete take under 1,500 spoken characters and 180 seconds.');
        }
        if ($format === 'reaction' && (count($out) !== 1 || $out[0]['headline'] === '')) {
            self::invalid('A text-led reaction needs one shot and a headline.');
        }
        if ($format === 'direct_camera' && (count($out) !== 1 || $out[0]['kind'] !== 'on_camera')) {
            self::invalid('Direct-to-camera uses one continuous talking take (up to 60 seconds).');
        }
        if (count(array_filter($out, fn ($s) => $s['kind'] === 'on_camera')) > 4) {
            self::invalid('Use at most four deliberate talking takes. Shots are not silently converted to cutaways.');
        }

        return $out;
    }

    public static function quote(array $segments): int
    {
        $images = app(ImageAdapterFactory::class);
        $total = 0;
        foreach ($segments as $seg) {
            // Voice is only synthesised where there are words. A silent demo
            // cutaway must not be quoted for speech it will never use.
            if ($seg['kind'] !== 'reaction' && trim((string) $seg['script_text']) !== '') {
                $total += CreditService::TTS_GEMINI;
            }
            if (in_array($seg['kind'], ['on_camera', 'reaction'], true)) {
                $total += $images->referenceGenerationCost(null);
                $total += $seg['kind'] === 'reaction'
                    ? CreditService::animationCost(self::REACTION_TIER, CreditService::videoQuality(self::REACTION_TIER, null), (int) $seg['seconds'])
                    : CreditService::spokespersonCost((float) $seg['seconds']);
            } elseif ($seg['source'] === 'generate') {
                $total += $images->generationCost(null, false);
            }
        }

        return $total;
    }

    public static function warnings(array $segments): array
    {
        $warnings = ['Credits are an estimate: spoken duration is confirmed after speech synthesis. Review one take before generating a large batch.'];
        foreach ($segments as $i => $seg) {
            if ($seg['kind'] === 'b_roll' && $seg['source'] !== 'generate' && ! $seg['asset_id']) {
                $warnings[] = 'Shot '.($i + 1).' needs selected '.$seg['source'].' footage before generation. It will not be replaced with an AI image.';
            }
        }
        if (count($segments) > 1) {
            $warnings[] = 'Speech is recorded per shot. Choose direct-to-camera for an uninterrupted performance.';
        }

        return $warnings;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['segments' => $message]);
    }
}
