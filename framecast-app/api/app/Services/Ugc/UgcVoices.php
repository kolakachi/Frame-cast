<?php

namespace App\Services\Ugc;

use App\Models\Character;

/**
 * One fixed voice per speaker for Omni takes that get lip-synced (2026-10-15). Omni makes its own voice in every clip,
 * so a speaker drifted from clip to clip and repeated or dropped words; each line is now read once by the speaker's
 * voice and synced onto Omni's motion. The voice follows the presenter's gender and the delivery asked for, and stays
 * the same for a character across takes (picked by the character's id), so a cast face always sounds like itself.
 */
final class UgcVoices
{
    /** Gemini voices that sound like a person talking to a phone, by gender and delivery. */
    private const VOICES = [
        'female' => ['friendly' => ['Sulafat', 'Aoede'], 'excited' => ['Laomedeia', 'Zephyr'], 'calm' => ['Despina', 'Achernar'], 'deadpan' => ['Callirrhoe', 'Erinome']],
        'male' => ['friendly' => ['Achird', 'Umbriel'], 'excited' => ['Puck', 'Sadachbia'], 'calm' => ['Algieba', 'Iapetus'], 'deadpan' => ['Zubenelgenubi', 'Schedar']],
    ];

    /** The delivery a voice direction asks for (the chat writes these openings), friendly when it says nothing clear. */
    public static function delivery(string $direction): string
    {
        $d = mb_strtolower($direction);
        return match (true) {
            str_contains($d, 'excited') || str_contains($d, 'upbeat') => 'excited',
            str_contains($d, 'calm') || str_contains($d, 'expert') => 'calm',
            str_contains($d, 'deadpan') || str_contains($d, 'dry') => 'deadpan',
            default => 'friendly',
        };
    }

    /** A presenter's gender from their character, else from how they are described; female when nothing says. */
    public static function gender(?int $characterId, string $description = ''): string
    {
        $g = $characterId ? mb_strtolower((string) Character::query()->whereKey($characterId)->value('gender')) : '';
        if ($g === '') $g = preg_match('/\b(man|male|guy|he|his|father|dad|boy)\b/i', $description) ? 'male' : 'female';
        return str_starts_with($g, 'm') ? 'male' : 'female';
    }

    public static function pick(string $gender, string $delivery, int $seed, array $taken = []): string
    {
        $list = self::VOICES[$gender][$delivery] ?? self::VOICES[$gender]['friendly'];
        $voice = $list[$seed % count($list)];
        // Two speakers of a skit never share a voice.
        return in_array($voice, $taken, true) ? $list[($seed + 1) % count($list)] : $voice;
    }

    /**
     * Give every spoken Omni chunk its speaker's voice: ['id' => Gemini voice, 'direction' => how it is said, 'speed'].
     *
     * @param  array<int, array>  $chunks    the compiled chunks (each may carry 'speaker' and 'dialogue')
     * @param  array<int, array>  $segments  the plan's segments (voice_direction, speed, speaker)
     * @param  array<int, int|null>  $cast   speaker number => character id (a single presenter is speaker 0)
     */
    public static function assign(array $chunks, array $segments, array $cast, string $description = ''): array
    {
        $voices = []; $taken = [];
        foreach ($chunks as $i => $chunk) {
            if (trim((string) ($chunk['dialogue'] ?? '')) === '' || ! empty($chunk['requires_audio'])) continue;
            $speaker = (int) ($chunk['speaker'] ?? 0);
            $said = collect($segments)->first(fn ($s) => trim((string) ($s['script_text'] ?? '')) !== '' && (int) ($s['speaker'] ?? 0) === $speaker)
                ?? collect($segments)->first(fn ($s) => trim((string) ($s['script_text'] ?? '')) !== '') ?? [];
            if (! isset($voices[$speaker])) {
                $characterId = $cast[$speaker] ?? $cast[0] ?? null;
                $direction = (string) ($said['voice_direction'] ?? '');
                $id = self::pick(self::gender($characterId, $description), self::delivery($direction), (int) ($characterId ?? $speaker), $taken);
                $taken[] = $id;
                $voices[$speaker] = ['id' => $id, 'direction' => $direction, 'speed' => (float) ($said['speed'] ?? 1.0)];
            }
            $chunks[$i]['voice'] = $voices[$speaker];
        }
        return $chunks;
    }
}
