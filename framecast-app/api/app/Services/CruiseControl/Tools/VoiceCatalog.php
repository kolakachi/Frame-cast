<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\VoiceProfile;
use App\Services\Generation\TTS\GeminiVoices;

/**
 * Resolves a voice NAME (as a user/assistant would say it) to the stored
 * voice_id + provider used in voice_settings_json. Lets the Cruise assistant
 * pick any voice — the expressive Gemini set, the classic OpenAI voices, or one
 * of the workspace's cloned voices by name.
 */
class VoiceCatalog
{
    public const OPENAI = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'ash', 'coral', 'sage', 'ballad', 'verse'];

    /**
     * @return array{voice_id:string,provider:string}|null  null if the name matches nothing.
     */
    public static function resolve(int $workspaceId, string $name): ?array
    {
        $n = trim($name);
        if ($n === '') {
            return null;
        }

        if (in_array(strtolower($n), self::OPENAI, true)) {
            return ['voice_id' => strtolower($n), 'provider' => 'openai'];
        }

        // Gemini voices are capitalized (Kore, Charon); match case-insensitively.
        foreach (array_keys(GeminiVoices::VOICES) as $g) {
            if (strcasecmp($g, $n) === 0) {
                return ['voice_id' => $g, 'provider' => 'google'];
            }
        }

        // A cloned voice in this workspace, by its display name.
        $clone = VoiceProfile::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_cloned', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($n)])
            ->first();
        if ($clone) {
            return ['voice_id' => (string) $clone->provider_voice_key, 'provider' => 'replicate:chatterbox'];
        }

        return null;
    }

    /** Voice guidance for tool param descriptions (what the assistant can pick). */
    public static function describe(): string
    {
        return 'A voice name. Expressive (Gemini): Kore (firm), Charon (informative), Puck (upbeat/young), '
            .'Leda (youthful, reads like a teen), Aoede (breezy), Fenrir (excitable), Gacrux (mature/older), '
            .'Vindemiatrix (gentle, older woman), Algenib (gravelly, older man), Sulafat (warm). '
            .'Classic (OpenAI): alloy, nova, onyx, echo, fable, shimmer. '
            .'Or the exact name of one of the user\'s cloned voices.';
    }
}
