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
    public const KINDS = ['stock_video', 'stock_image', 'ai_image', 'animate_image', 'voiceover', 'cloned_voiceover', 'library_music', 'brand_kit'];

    public static function forWorkspace(int $workspaceId): array
    {
        $tools = [
            ['kind' => 'stock_video', 'what' => 'Licensed stock footage clip found by search', 'credits' => CreditService::STOCK],
            ['kind' => 'stock_image', 'what' => 'Licensed stock photo found by search', 'credits' => CreditService::STOCK],
            ['kind' => 'ai_image', 'what' => 'A new generated image or scene background', 'credits' => app(ImageAdapterFactory::class)->costFor(null)],
            ['kind' => 'animate_image', 'what' => 'A 5-second generated motion clip from a still', 'credits' => CreditService::animationCost('quick', '480p', 5)],
            ['kind' => 'voiceover', 'what' => 'Narration of approved lines in a catalogue voice, per line', 'credits' => CreditService::TTS_GEMINI],
            ['kind' => 'library_music', 'what' => 'A track from the licensed music library', 'credits' => 0],
            ['kind' => 'brand_kit', 'what' => "The workspace's brand colours, fonts and logo", 'credits' => 0],
        ];
        if (Schema::hasTable('voice_profiles') && DB::table('voice_profiles')->where('workspace_id', $workspaceId)->where('is_cloned', true)->exists()) {
            $tools[] = ['kind' => 'cloned_voiceover', 'what' => "Narration in the workspace's own cloned voice, per line", 'credits' => CreditService::TTS_CLONE];
        }
        return $tools;
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
