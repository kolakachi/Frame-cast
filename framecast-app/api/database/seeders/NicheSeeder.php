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
            // Faceless formats (2026-10-28): the composer serves faceless creators again. Playbooks and defaults live
            // in Niche::PLAYBOOK and Niche::DEFAULTS.
            ['name' => 'Facts & Lists', 'slug' => 'facts', 'description' => 'Surprising facts and top-5 lists that keep people watching to the end.', 'icon_emoji' => '🧠',
                'default_template_type' => 'explainer', 'default_visual_style' => 'photorealistic', 'default_caption_preset_name' => 'Beast', 'default_voice_tone' => 'punchy', 'default_music_mood' => 'upbeat'],
            ['name' => 'History', 'slug' => 'history', 'description' => 'Dramatic true stories from the past, told like a documentary.', 'icon_emoji' => '🏛️',
                'default_template_type' => 'explainer', 'default_visual_style' => 'cinematic', 'default_caption_preset_name' => 'Karaoke', 'default_voice_tone' => 'documentary', 'default_music_mood' => 'epic'],
            ['name' => 'Science & Space', 'slug' => 'science', 'description' => 'Mind-bending science explained with one great analogy.', 'icon_emoji' => '🔭',
                'default_template_type' => 'explainer', 'default_visual_style' => '3d_animated', 'default_caption_preset_name' => 'Karaoke', 'default_voice_tone' => 'curious', 'default_music_mood' => 'calm'],
            ['name' => 'Stories', 'slug' => 'story', 'description' => 'Narrated stories with a twist: Reddit-style, fables and short fiction.', 'icon_emoji' => '📖',
                'default_template_type' => 'explainer', 'default_visual_style' => 'fantasy_retro', 'default_caption_preset_name' => 'Karaoke', 'default_voice_tone' => 'storyteller', 'default_music_mood' => 'calm'],
            ['name' => 'Scary & Mystery', 'slug' => 'scary', 'description' => 'Creepy legends, unsolved mysteries and slow-burn suspense.', 'icon_emoji' => '👻',
                'default_template_type' => 'explainer', 'default_visual_style' => 'dark_fantasy', 'default_caption_preset_name' => 'Glitch', 'default_voice_tone' => 'hushed', 'default_music_mood' => 'dark'],
            ['name' => 'AI & Tech Tips', 'slug' => 'ai-tips', 'description' => 'Quick, practical tips for AI tools and apps.', 'icon_emoji' => '🤖',
                'default_template_type' => 'explainer', 'default_visual_style' => '3d_animated', 'default_caption_preset_name' => 'Box', 'default_voice_tone' => 'friendly', 'default_music_mood' => 'upbeat'],
        ];

        foreach ($niches as $niche) {
            Niche::query()->updateOrCreate(
                ['slug' => $niche['slug']],
                $niche,
            );
        }
    }
}
