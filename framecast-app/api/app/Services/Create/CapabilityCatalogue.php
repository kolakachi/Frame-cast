<?php
namespace App\Services\Create;

use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the planner may propose beyond composing supplied media: WyvStudio's
 * own tools, with credits computed here. The planner only names a kind; the
 * price shown to the user always comes from this catalogue, never the model.
 */
class CapabilityCatalogue
{
    public const KINDS = ['stock_video', 'stock_image', 'ai_image', 'animate_image', 'voiceover', 'cloned_voiceover', 'music', 'sfx', 'character_poses', 'brand_kit',
        'transcript', 'stabilize', 'remove_silence', 'clean_audio', 'loudness', 'speed', 'crop', 'grade', 'trim'];

    public static function forWorkspace(int $workspaceId): array
    {
        $tools = [
            ['kind' => 'stock_video', 'what' => 'Licensed stock footage clip found by search', 'credits' => CreditService::STOCK],
            ['kind' => 'stock_image', 'what' => 'Licensed stock photo found by search', 'credits' => CreditService::STOCK],
            ['kind' => 'ai_image', 'what' => 'A new generated image or scene background', 'credits' => app(ImageAdapterFactory::class)->costFor(null)],
            ['kind' => 'animate_image', 'what' => 'A 5-second generated motion clip from a still', 'credits' => CreditService::animationCost('quick', '480p', 5)],
            ['kind' => 'voiceover', 'what' => 'Narration of approved lines in a catalogue voice, per line', 'credits' => CreditService::TTS_GEMINI],
            // Generated audio on Replicate, priced at the usual peg (cost / $0.004):
            // ElevenLabs Music is $0.0083 per second of output; Stable Audio 2.5 is $0.20 a file.
            ['kind' => 'music', 'what' => 'An original instrumental music bed made for this video, sized to its length', 'credits' => self::musicCredits(15)],
            ['kind' => 'character_poses', 'what' => 'One consistent character (a saved character, your own mascot image, or a new original one) in up to 5 poses, cut out on transparent backgrounds', 'credits' => self::POSE_CREDITS],
            ['kind' => 'sfx', 'what' => 'A set of up to 6 short sound effects (clicks, whooshes, pops) for on-screen beats', 'credits' => self::SFX_CREDITS],
            // library_music is withheld: the workspace library holds placeholder
            // tracks, not licensed music (2026-10-01). Restore once real tracks exist.
            ['kind' => 'brand_kit', 'what' => "The workspace's brand colours, fonts and logo", 'credits' => 0],
            // Free edits to the user's own footage, run in the render sandbox.
            ['kind' => 'transcript', 'what' => 'Word-timed transcript of speech, so text and visuals land on spoken words', 'credits' => 0],
            ['kind' => 'stabilize', 'what' => 'Steady shaky handheld footage', 'credits' => 0],
            ['kind' => 'remove_silence', 'what' => 'Cut pauses and dead air from speech', 'credits' => 0],
            ['kind' => 'clean_audio', 'what' => 'Reduce background noise in a voice recording', 'credits' => 0],
            ['kind' => 'loudness', 'what' => 'Level audio to platform loudness', 'credits' => 0],
            ['kind' => 'speed', 'what' => 'Speed up or slow down a clip, pitch kept', 'credits' => 0],
            ['kind' => 'crop', 'what' => 'Reframe a clip or photo to another ratio around a focus point', 'credits' => 0],
            ['kind' => 'grade', 'what' => 'Colour look: warm, cool, punchy, muted, mono or film', 'credits' => 0],
            ['kind' => 'trim', 'what' => 'Keep only chosen moments of a clip', 'credits' => 0],
        ];
        if (Schema::hasTable('voice_profiles') && DB::table('voice_profiles')->where('workspace_id', $workspaceId)->where('is_cloned', true)->exists()) {
            $tools[] = ['kind' => 'cloned_voiceover', 'what' => "Narration in the workspace's own cloned voice, per line", 'credits' => CreditService::TTS_CLONE];
        }
        return $tools;
    }

    public const SFX_CREDITS = 50;
    /** Nano Banana Pro at 35 credits an image: a base character plus up to 5 poses. Cut-outs are negligible. */
    public const POSE_CREDITS = 210;

    /** One second of padding so the bed covers the whole video. */
    public static function musicCredits(int $seconds): int
    {
        return (int) ceil((max(5, $seconds) + 1) * 0.0083 / 0.004);
    }

    public static function brandKits(int $workspaceId): array
    {
        if (! Schema::hasTable('brand_kits')) return [];
        return DB::table('brand_kits')->where('workspace_id', $workspaceId)->limit(5)->pluck('name')->filter()->values()->all();
    }

    public static function credits(string $kind, int $workspaceId): ?int
    {
        foreach (self::forWorkspace($workspaceId) as $tool) if ($tool['kind'] === $kind) return (int) $tool['credits'];
        return null;
    }
}
