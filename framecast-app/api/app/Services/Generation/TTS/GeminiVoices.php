<?php

namespace App\Services\Generation\TTS;

/**
 * The 30 prebuilt Gemini Flash TTS voices and their character descriptors.
 *
 * Single source of truth for:
 *  - the seeder that publishes them as global VoiceProfile rows (the picker
 *    is data-driven off /voice-profiles, so seeding makes them appear),
 *  - the router's "is this a Gemini voice?" check, and
 *  - the adapter's voice validation (unknown name → default).
 *
 * Names are passed verbatim to Replicate's `voice` input (capitalized, as
 * Google publishes them). The descriptor is the voice's delivery character.
 */
final class GeminiVoices
{
    /** Fallback when the requested voice is unknown/empty. Mirrors config. */
    public const DEFAULT_VOICE = 'Kore';

    /** voice name => one-word delivery character (shown in the picker). */
    public const VOICES = [
        'Zephyr'        => 'Bright',
        'Puck'          => 'Upbeat',
        'Charon'        => 'Informative',
        'Kore'          => 'Firm',
        'Fenrir'        => 'Excitable',
        'Leda'          => 'Youthful',
        'Orus'          => 'Firm',
        'Aoede'         => 'Breezy',
        'Callirrhoe'    => 'Easy-going',
        'Autonoe'       => 'Bright',
        'Enceladus'     => 'Breathy',
        'Iapetus'       => 'Clear',
        'Umbriel'       => 'Easy-going',
        'Algieba'       => 'Smooth',
        'Despina'       => 'Smooth',
        'Erinome'       => 'Clear',
        'Algenib'       => 'Gravelly',
        'Rasalgethi'    => 'Informative',
        'Laomedeia'     => 'Upbeat',
        'Achernar'      => 'Soft',
        'Alnilam'       => 'Firm',
        'Schedar'       => 'Even',
        'Gacrux'        => 'Mature',
        'Pulcherrima'   => 'Forward',
        'Achird'        => 'Friendly',
        'Zubenelgenubi' => 'Casual',
        'Vindemiatrix'  => 'Gentle',
        'Sadachbia'     => 'Lively',
        'Sadaltager'    => 'Knowledgeable',
        'Sulafat'       => 'Warm',
    ];

    /** True if $voiceId is one of the Gemini prebuilt voices (case-sensitive). */
    public static function isGeminiVoice(?string $voiceId): bool
    {
        return $voiceId !== null && array_key_exists($voiceId, self::VOICES);
    }

    /** A known Gemini voice name, or the default if the input is unknown/empty. */
    public static function resolve(?string $voiceId): string
    {
        return self::isGeminiVoice($voiceId) ? (string) $voiceId : self::DEFAULT_VOICE;
    }
}
