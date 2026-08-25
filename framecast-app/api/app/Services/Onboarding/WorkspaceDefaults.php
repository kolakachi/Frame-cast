<?php

namespace App\Services\Onboarding;

use App\Models\Asset;

/**
 * Default content every new workspace should start with.
 *
 * The music library used to exist only inside `MusicTrackSeeder`, which loops
 * over workspaces that already exist and is never run by the deploy workflow.
 * The practical effect was that it ran once by hand and every workspace created
 * afterwards had an empty music picker — selecting a track appeared to do
 * nothing because there was nothing there. This service is the single source of
 * truth so both the seeder and the signup paths provision the same set.
 *
 * NOTE: the tracks below are SoundHelix demo MP3s — placeholder audio, not a
 * licensed library. Swap them for real tracks before leaning on music as a
 * feature.
 */
class WorkspaceDefaults
{
    /** @var list<array{title:string,mood:string,url:string}> */
    public const MUSIC_TRACKS = [
        ['title' => 'Ambient Corporate',   'mood' => 'corporate', 'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3'],
        ['title' => 'Upbeat Energy',       'mood' => 'upbeat',    'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-2.mp3'],
        ['title' => 'Epic Motivation',     'mood' => 'epic',      'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-3.mp3'],
        ['title' => 'Calm Focus',          'mood' => 'calm',      'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-4.mp3'],
        ['title' => 'Dark Tension',        'mood' => 'dark',      'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-5.mp3'],
        ['title' => 'Cinematic Drama',     'mood' => 'epic',      'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-6.mp3'],
        ['title' => 'Soft Background',     'mood' => 'calm',      'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-7.mp3'],
        ['title' => 'Energetic Pop',       'mood' => 'upbeat',    'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-8.mp3'],
        ['title' => 'Professional Walk',   'mood' => 'corporate', 'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-9.mp3'],
        ['title' => 'Moody Atmosphere',    'mood' => 'dark',      'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-10.mp3'],
    ];

    /**
     * Give a workspace the default music library. Idempotent — keyed on
     * (workspace, type, title), so re-running never duplicates and a workspace
     * that already has them is untouched.
     *
     * @return int number of tracks provisioned or refreshed
     */
    public function provisionMusic(int $workspaceId): int
    {
        $count = 0;

        foreach (self::MUSIC_TRACKS as $track) {
            Asset::query()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'asset_type'   => 'music',
                    'title'        => $track['title'],
                ],
                [
                    'storage_url' => $track['url'],
                    'description' => ucfirst($track['mood']).' background music track',
                    'tags'        => ['music', $track['mood']],
                    'status'      => 'active',
                ]
            );
            $count++;
        }

        return $count;
    }
}
