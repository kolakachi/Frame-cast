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

    /**
     * Perceived gender per voice (best-effort — Google doesn't publish a strict
     * mapping; tweak individual entries if a voice reads differently). Used as
     * the VoiceProfile gender_label so the picker can show/filter male vs female.
     */
    public const GENDER = [
        'Zephyr'        => 'Female',
        'Puck'          => 'Male',
        'Charon'        => 'Male',
        'Kore'          => 'Female',
        'Fenrir'        => 'Male',
        'Leda'          => 'Female',
        'Orus'          => 'Male',
        'Aoede'         => 'Female',
        'Callirrhoe'    => 'Female',
        'Autonoe'       => 'Female',
        'Enceladus'     => 'Male',
        'Iapetus'       => 'Male',
        'Umbriel'       => 'Male',
        'Algieba'       => 'Male',
        'Despina'       => 'Female',
        'Erinome'       => 'Female',
        'Algenib'       => 'Male',
        'Rasalgethi'    => 'Male',
        'Laomedeia'     => 'Female',
        'Achernar'      => 'Female',
        'Alnilam'       => 'Male',
        'Schedar'       => 'Male',
        'Gacrux'        => 'Female',
        'Pulcherrima'   => 'Female',
        'Achird'        => 'Male',
        'Zubenelgenubi' => 'Male',
        'Vindemiatrix'  => 'Female',
        'Sadachbia'     => 'Female',
        'Sadaltager'    => 'Male',
        'Sulafat'       => 'Female',
    ];

    /** Perceived gender for a voice (Male/Female), or Neutral if unknown. */
    public static function gender(string $name): string
    {
        return self::GENDER[$name] ?? 'Neutral';
    }

    /**
     * Default voice for an inferred speaker gender, so auto-assigned voices
     * (one-shot) match the on-screen person — a male subject gets a male voice,
     * not the female default (which breaks lip-sync). Accepts male/female/neutral.
     */
    public static function defaultForGender(?string $gender): string
    {
        return match (mb_strtolower((string) $gender)) {
            'male' => 'Charon',   // clear, neutral male narrator
            default => self::DEFAULT_VOICE, // Kore (female) for female + neutral
        };
    }

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
