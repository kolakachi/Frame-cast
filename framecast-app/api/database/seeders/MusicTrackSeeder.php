<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class MusicTrackSeeder extends Seeder
{
    public function run(): void
    {
        $tracks = [
            [
                'title' => 'Ambient Corporate',
                'mood' => 'corporate',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3',
            ],
            [
                'title' => 'Upbeat Energy',
                'mood' => 'upbeat',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-2.mp3',
            ],
            [
                'title' => 'Epic Motivation',
                'mood' => 'epic',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-3.mp3',
            ],
            [
                'title' => 'Calm Focus',
                'mood' => 'calm',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-4.mp3',
            ],
            [
                'title' => 'Dark Tension',
                'mood' => 'dark',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-5.mp3',
            ],
            [
                'title' => 'Cinematic Drama',
                'mood' => 'epic',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-6.mp3',
            ],
            [
                'title' => 'Soft Background',
                'mood' => 'calm',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-7.mp3',
            ],
            [
                'title' => 'Energetic Pop',
                'mood' => 'upbeat',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-8.mp3',
            ],
            [
                'title' => 'Professional Walk',
                'mood' => 'corporate',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-9.mp3',
            ],
            [
                'title' => 'Moody Atmosphere',
                'mood' => 'dark',
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-10.mp3',
            ],
        ];

        $workspaces = Workspace::query()->pluck('id');

        if ($workspaces->isEmpty()) {
            $this->command->info('MusicTrackSeeder: no workspaces found — skipping. Re-run after first user registers.');

            return;
        }

        foreach ($workspaces as $workspaceId) {
            foreach ($tracks as $track) {
                Asset::query()->updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'asset_type' => 'music',
                        'title' => $track['title'],
                    ],
                    [
                        'storage_url' => $track['url'],
                        'description' => ucfirst($track['mood']).' background music track',
                        'tags' => ['music', $track['mood']],
                        'status' => 'active',
                        'usage_count' => 0,
                    ]
                );
            }
        }
    }
}
