<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\CaptionPreset;
use App\Models\Channel;
use App\Models\Character;
use App\Models\Niche;
use App\Models\User;
use App\Services\Generation\Image\ImageStyleDescriptors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a quote may point at: the workspace's brand kits, channels, niches,
 * caption presets, characters and library, plus the static option keys.
 * Read-only, workspace-scoped, compact — an assistant reads these to choose
 * for the user, and the quote echoes what it chose.
 */
class LookupController extends DeveloperController
{
    public const PLATFORM_TARGETS = ['tiktok', 'youtube', 'youtube_shorts', 'instagram_reels', 'instagram_post', 'facebook'];
    public const LANGUAGES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ar', 'hi', 'ja', 'zh'];
    public const SOURCE_TYPES = ['prompt', 'script', 'url', 'product_description', 'images'];
    public const VISUAL_MODES = ['stock', 'ai_images', 'ai_video', 'waveform'];
    public const LIBRARY_TYPES = ['image', 'music', 'video'];

    /** @return list<string> */
    public static function visualStyles(): array
    {
        return array_keys(ImageStyleDescriptors::META);
    }

    public function options(): JsonResponse
    {
        $styles = [];
        foreach (ImageStyleDescriptors::META as $key => $meta) {
            $styles[] = ['key' => $key, 'label' => $meta['label'] ?? $key];
        }

        return response()->json(['data' => [
            'source_types' => [
                ['key' => 'prompt', 'label' => 'Prompt: WyvStudio writes the script from a brief'],
                ['key' => 'script', 'label' => 'Script: narration text used as-is'],
                ['key' => 'url', 'label' => 'URL or article text: script written from a web page'],
                ['key' => 'product_description', 'label' => 'Product description: script written to sell it'],
                ['key' => 'images', 'label' => 'Images: 1–15 library images become the scenes (with a prompt)'],
            ],
            'visual_modes' => [
                ['key' => 'stock', 'label' => 'Licensed stock footage (cheapest)'],
                ['key' => 'ai_images', 'label' => 'AI stills per scene'],
                ['key' => 'ai_video', 'label' => 'AI stills animated into motion clips (needs animate_tier)'],
                ['key' => 'waveform', 'label' => 'Audiogram: waveform over a background (audio-led)'],
            ],
            'visual_styles' => $styles,
            'animate_tiers' => CapabilitiesController::ANIMATE_TIERS,
            'animation_pacing' => ['short', 'long'],
            'aspect_ratios' => CapabilitiesController::ASPECT_RATIOS,
            'platform_targets' => self::PLATFORM_TARGETS,
            'languages' => self::LANGUAGES,
            'tone_examples' => ['friendly', 'authoritative', 'playful', 'calm', 'urgent', 'inspiring'],
            'audiogram' => ['style' => 'free text, e.g. bars or wave', 'color' => 'hex', 'bg' => 'hex or keyword'],
            'library_types' => self::LIBRARY_TYPES,
        ], 'meta' => []]);
    }

    public function brandKits(Request $request): JsonResponse
    {
        $rows = BrandKit::query()->where('workspace_id', $this->ws($request))->orderBy('name')->get()->map(fn (BrandKit $b) => [
            'id' => $b->getKey(), 'name' => $b->name,
            'colors' => ['primary' => $b->primary_color, 'secondary' => $b->secondary_color, 'accent' => $b->accent_color],
            'fonts' => ['primary' => $b->font_primary, 'secondary' => $b->font_secondary],
            'default_caption_style' => $b->default_caption_style,
            'default_voice_profile_id' => $b->default_voice_profile_id,
        ])->values();

        return $this->list('brand_kits', $rows);
    }

    public function channels(Request $request): JsonResponse
    {
        $rows = Channel::query()->where('workspace_id', $this->ws($request))->where('status', 'active')->orderBy('name')->get()->map(fn (Channel $c) => [
            'id' => $c->getKey(), 'name' => $c->name, 'description' => $c->description,
            'default_language' => $c->default_language, 'platform_targets' => $c->platform_targets,
            'brand_kit_id' => $c->brand_kit_id, 'default_voice_profile_id' => $c->default_voice_profile_id,
            'default_caption_preset_id' => $c->default_caption_preset_id,
        ])->values();

        return $this->list('channels', $rows);
    }

    public function niches(): JsonResponse
    {
        $rows = Niche::query()->orderBy('name')->get()->map(fn (Niche $n) => [
            'id' => $n->getKey(), 'name' => $n->name, 'slug' => $n->slug, 'description' => $n->description,
            'defaults' => ['visual_style' => $n->default_visual_style, 'tone' => $n->default_voice_tone, 'music_mood' => $n->default_music_mood, 'template_type' => $n->default_template_type],
        ])->values();

        return $this->list('niches', $rows);
    }

    public function captionPresets(Request $request): JsonResponse
    {
        $rows = CaptionPreset::query()->where('workspace_id', $this->ws($request))->orderBy('name')->get()->map(fn (CaptionPreset $p) => [
            'id' => $p->getKey(), 'name' => $p->name, 'preset_type' => $p->preset_type, 'font' => $p->font,
            'highlight_mode' => $p->highlight_mode, 'highlight_color' => $p->highlight_color, 'caption_color' => $p->caption_color,
            'position' => $p->caption_position, 'animation' => $p->animation_type,
        ])->values();

        return $this->list('caption_presets', $rows);
    }

    public function characters(Request $request): JsonResponse
    {
        $rows = Character::query()->where('workspace_id', $this->ws($request))->where('status', 'active')->orderBy('name')->get()->map(fn (Character $c) => [
            'id' => $c->getKey(), 'name' => $c->name, 'description' => $c->description,
            'gender' => $c->gender, 'age_group' => $c->age_group, 'situations' => $c->situations,
            'consistency_method' => $c->consistency_method,
        ])->values();

        return $this->list('characters', $rows);
    }

    public function library(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'type' => ['required', 'in:'.implode(',', self::LIBRARY_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $page = Asset::query()->where('workspace_id', $this->ws($request))->where('asset_type', $input['type'])
            ->when(! empty($input['q']), fn ($q) => $q->where('title', 'ilike', '%'.$input['q'].'%'))
            ->orderByDesc('id')->paginate(50, ['*'], 'page', (int) ($input['page'] ?? 1));
        $rows = collect($page->items())->map(fn (Asset $a) => [
            'id' => $a->getKey(), 'title' => $a->title, 'type' => $a->asset_type,
            'duration_seconds' => $a->duration_seconds !== null ? round((float) $a->duration_seconds, 1) : null,
            'mime_type' => $a->mime_type, 'created_at' => $a->created_at?->toIso8601String(),
        ])->values();

        return response()->json(['data' => ['assets' => $rows], 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    private function ws(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return (int) $user->workspace_id;
    }

    private function list(string $key, $rows): JsonResponse
    {
        return response()->json(['data' => [$key => $rows], 'meta' => ['count' => count($rows)]]);
    }
}
