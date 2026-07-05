<?php

namespace Database\Seeders;

use App\Models\Niche;
use Illuminate\Database\Seeder;

/**
 * Commercial niche lineup (2026-07) — pivoted off story/faceless niches toward
 * the branded/commercial ICP that actually pays for the tool. Per-niche
 * generation guidance lives in Niche::PLAYBOOK (keyed by slug). "Faceless /
 * documentary" is intentionally omitted — that lane is served by Custom/Other.
 */
class NicheSeeder extends Seeder
{
    public function run(): void
    {
        $niches = [
            [
                'name' => 'Product Review',
                'slug' => 'product',
                'description' => 'Honest product comparisons, affiliate-ready walkthroughs, and feature breakdowns for any category.',
                'icon_emoji' => '📦',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'realistic',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'friendly',
                'default_music_mood' => 'upbeat',
            ],
            [
                'name' => 'Product Launch / Demo',
                'slug' => 'product-launch',
                'description' => 'Announce new products, walk through features, and drive signups — built for SaaS, apps, and physical launches.',
                'icon_emoji' => '🚀',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'cinematic',
                'default_caption_preset_name' => 'Bold White',
                'default_voice_tone' => 'confident',
                'default_music_mood' => 'epic',
            ],
            [
                'name' => 'Ad Creative',
                'slug' => 'ad-creative',
                'description' => 'Hook-first short ads for Meta, TikTok, and YouTube — built for DTC brands, offers, and paid traffic campaigns.',
                'icon_emoji' => '🎯',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'cinematic',
                'default_caption_preset_name' => 'Bold White',
                'default_voice_tone' => 'energetic',
                'default_music_mood' => 'upbeat',
            ],
            [
                'name' => 'Explainer / How-To',
                'slug' => 'explainer',
                'description' => 'Break down complex ideas, tools, or processes into sharp 60-second videos that teach and convert.',
                'icon_emoji' => '💡',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'minimalist',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'clear',
                'default_music_mood' => 'corporate',
            ],
            [
                'name' => 'Brand Story',
                'slug' => 'brand-story',
                'description' => 'Tell your origin, mission, or customer transformation — for founders, coaches, and personal brands building trust.',
                'icon_emoji' => '✨',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'cinematic',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'warm',
                'default_music_mood' => 'calm',
            ],
            [
                'name' => 'Motivation / Mindset',
                'slug' => 'motivation',
                'description' => 'High-energy content for coaches, personal brands, and creators building an engaged daily audience.',
                'icon_emoji' => '🔥',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'cinematic',
                'default_caption_preset_name' => 'Bold White',
                'default_voice_tone' => 'energetic',
                'default_music_mood' => 'epic',
            ],
        ];

        foreach ($niches as $niche) {
            Niche::query()->updateOrCreate(
                ['slug' => $niche['slug']],
                $niche,
            );
        }
    }
}
