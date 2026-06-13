<?php

namespace Database\Seeders;

use App\Models\VoiceProfile;
use App\Services\Generation\TTS\GeminiVoices;
use Illuminate\Database\Seeder;

/**
 * Publishes the 30 prebuilt Gemini Flash TTS voices as global VoiceProfile
 * rows (workspace_id = null) so the data-driven /voice-profiles picker shows
 * them everywhere. Idempotent (updateOrCreate) — safe to re-run after deploy.
 *
 *   docker compose -f docker-compose.prod.yml exec api \
 *     php artisan db:seed --class=GeminiVoiceSeeder --force
 */
class GeminiVoiceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (GeminiVoices::VOICES as $name => $character) {
            VoiceProfile::query()->updateOrCreate(
                [
                    'workspace_id'       => null,
                    'provider'           => 'google',
                    'provider_voice_key' => $name,
                ],
                [
                    'name'         => $name,
                    'language'     => 'en',
                    'accent'       => null,
                    'gender_label' => $character, // delivery character shown as the picker tag
                    'voice_type'   => 'synthetic',
                    'is_cloned'    => false,
                    'status'       => 'active',
                ],
            );
        }
    }
}
