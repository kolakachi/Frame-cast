<?php

namespace Database\Seeders;

use App\Models\VoiceProfile;
use Illuminate\Database\Seeder;

class VoiceProfileSeeder extends Seeder
{
    public function run(): void
    {
        $voices = [
            ['name' => 'Alloy', 'provider_voice_key' => 'alloy', 'gender_label' => 'neutral'],
            ['name' => 'Ash', 'provider_voice_key' => 'ash', 'gender_label' => 'neutral'],
            ['name' => 'Ballad', 'provider_voice_key' => 'ballad', 'gender_label' => 'neutral'],
            ['name' => 'Coral', 'provider_voice_key' => 'coral', 'gender_label' => 'neutral'],
            ['name' => 'Echo', 'provider_voice_key' => 'echo', 'gender_label' => 'neutral'],
            ['name' => 'Fable', 'provider_voice_key' => 'fable', 'gender_label' => 'neutral'],
            ['name' => 'Nova', 'provider_voice_key' => 'nova', 'gender_label' => 'neutral'],
            ['name' => 'Onyx', 'provider_voice_key' => 'onyx', 'gender_label' => 'neutral'],
            ['name' => 'Sage', 'provider_voice_key' => 'sage', 'gender_label' => 'neutral'],
            ['name' => 'Shimmer', 'provider_voice_key' => 'shimmer', 'gender_label' => 'neutral'],
        ];

        foreach ($voices as $voice) {
            VoiceProfile::query()->updateOrCreate(
                [
                    'workspace_id' => null,
                    'provider' => 'openai',
                    'provider_voice_key' => $voice['provider_voice_key'],
                ],
                [
                    'name' => $voice['name'],
                    'language' => 'en',
                    'accent' => 'neutral',
                    'gender_label' => $voice['gender_label'],
                    'voice_type' => 'tts',
                    'is_cloned' => false,
                    'status' => 'active',
                ],
            );
        }
    }
}
