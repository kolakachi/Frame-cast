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
            if ($kind === 'reaction') {
                if ($format !== 'reaction' || $text !== '' || ! in_array($seconds, [5.0, 10.0], true) || $motion === '') {
                    self::invalid('A reaction is one silent 5- or 10-second shot with a motion direction.');
                }
            } elseif ($text === '' || mb_strlen($text) > 1500 || $format === 'reaction') {
                self::invalid('Spoken formats need narration in every shot; reactions must be silent.');
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
            $out[] = [
                'kind' => $kind, 'script_text' => $text, 'seconds' => $seconds,
                'visual_brief' => $brief, 'motion_prompt' => $motion,
                'voice_direction' => $delivery, 'headline' => $headline, 'source' => $source,
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
            if ($seg['kind'] !== 'reaction') {
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
