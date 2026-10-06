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
    public const KINDS = ['stock_video', 'stock_image', 'ai_image', 'animate_image', 'voiceover', 'cloned_voiceover', 'music', 'sfx', 'character_poses', 'talking_shot', 'talking_take', 'brand_kit',
        'reference_sheet', 'generated_shot', 'ugc_take',
        'transcript', 'stabilize', 'remove_silence', 'clean_audio', 'loudness', 'speed', 'crop', 'grade', 'trim'];

    public static function forWorkspace(int $workspaceId): array
    {
        $tools = [
            ['kind' => 'stock_video', 'what' => 'Licensed stock footage clip found by search', 'credits' => CreditService::STOCK],
            ['kind' => 'stock_image', 'what' => 'Licensed stock photo found by search', 'credits' => CreditService::STOCK],
            ['kind' => 'ai_image', 'what' => 'A new generated image or scene background', 'credits' => app(ImageAdapterFactory::class)->costFor(null)],
            // 851-labs/background-remover on Replicate: a fraction of a cent per image, sold at the smallest useful price.
            ['kind' => 'cutout', 'what' => 'Cut an image this run has out of its background (a transparent PNG): the description starts with its file name', 'credits' => self::CUTOUT_CREDITS],
            ['kind' => 'animate_image', 'what' => 'A 5-second generated motion clip from a still', 'credits' => CreditService::animationCost('quick', '480p', 5)],
            // Generated video. Priced per item from its engine and length (ShotRoute); the figure here is a typical item.
            ['kind' => 'reference_sheet', 'what' => 'The cast and world sheet for generated shots: one still per subject (a character, a place, a product, up to 4) in the video\'s look, approved before any clip is made. 35 credits a subject', 'credits' => 2 * self::CHARACTER_MASTER_CREDITS],
            ['kind' => 'generated_shot', 'what' => 'One generated video shot from the sheet or the user\'s avatar as references (Seedance 2.5 33/s, Omni 22/s, Veo 3.1 58/s) or from a first frame (Seedance Lite, Kling, Veo Fast, about 30-100 a clip), with its own ambient sound or a spoken line. Text and UI are never generated, always composed over it', 'credits' => 5 * ShotRoute::perSecond('seedance25')],
            ['kind' => 'ugc_take', 'what' => 'A presenter (the user\'s avatar or a sheet character) speaking the script to camera with native speech, made in segments and joined into one continuous take (Omni 22/s, Veo 3.1 58/s on Premium); replaces the voiceover', 'credits' => 12 * ShotRoute::perSecond('omni')],
            ['kind' => 'voiceover', 'what' => 'Narration of approved lines in a catalogue voice, per line', 'credits' => CreditService::TTS_GEMINI],
            // Generated audio on Replicate, priced at the usual peg (cost / $0.004):
            // ElevenLabs Music is $0.0083 per second of output; Stable Audio 2.5 is $0.20 a file.
            ['kind' => 'music', 'what' => 'An original instrumental music bed made for this video, sized to its length', 'credits' => self::musicCredits(15)],
            ['kind' => 'character_poses', 'what' => 'One character preview in the requested style for approval; additional poses are quoted only after approval', 'credits' => self::CHARACTER_MASTER_CREDITS],
            ['kind' => 'talking_shot', 'what' => 'The character performing the first approved script line with native speech (4 s); explicitly selected cloned voice uses audio-driven lip-sync', 'credits' => TalkingPresenter::route('talking_shot', null)['credits']],
            ['kind' => 'talking_take', 'what' => 'The character performing the full approved script with native speech (up to 15 s), without a separate voiceover. Explicit cloned voice uses audio-driven lip-sync', 'credits' => TalkingPresenter::route('talking_take', null)['credits']],
            ['kind' => 'sfx', 'what' => 'A set of up to 6 unusual sound effects (a cash register, a door, an animal). Not for whooshes, pops, clicks, thuds, chimes or typing: the motion kit lays those under its moves for free', 'credits' => self::SFX_CREDITS],
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
    public const CUTOUT_CREDITS = 2;
    /** Nano Banana Pro at 35 credits an image: a base character plus up to 5 poses. Cut-outs are negligible. */
    public const POSE_CREDITS = 210;
    public const CHARACTER_MASTER_CREDITS = 35;
    public const CHARACTER_VARIANT_CREDITS = 35;

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

    /** Actual colours, scoped to this workspace; names alone cannot guide a palette. */
    public static function brandPalettes(int $workspaceId): array
    {
        if (! Schema::hasTable('brand_kits')) return [];
        return DB::table('brand_kits')->where('workspace_id', $workspaceId)->limit(5)->get()->map(function ($kit) {
            $colours = [];
            foreach (['primary_color', 'secondary_color', 'accent_color'] as $key) {
                $hex = $kit->{$key} ?? null;
                if (is_string($hex) && preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $hex)) $colours[$key] = $hex;
            }
            return ['id' => $kit->id, 'name' => $kit->name, 'colours' => $colours];
        })->all();
    }

    public static function credits(string $kind, int $workspaceId): ?int
    {
        foreach (self::forWorkspace($workspaceId) as $tool) if ($tool['kind'] === $kind) return (int) $tool['credits'];
        return null;
    }
}
