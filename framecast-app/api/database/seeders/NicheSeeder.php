<?php

namespace Database\Seeders;

use App\Models\Niche;
use Illuminate\Database\Seeder;

class NicheSeeder extends Seeder
{
    public function run(): void
    {
        $niches = [
            [
                'name' => 'Horror / Dark Stories',
                'slug' => 'horror',
                'description' => 'Creepy stories, paranormal events, and dark mysteries that keep viewers watching till the end.',
                'icon_emoji' => '🕯️',
                'default_template_type' => 'listicle',
                'default_visual_style' => 'dark',
                'default_caption_preset_name' => 'Bold White',
                'default_voice_tone' => 'dramatic',
                'default_music_mood' => 'dark',
            ],
            [
                'name' => 'Finance / Money',
                'slug' => 'finance',
                'description' => 'Money tips, investing strategies, and financial freedom content for operators.',
                'icon_emoji' => '💹',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'documentary',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'authoritative',
                'default_music_mood' => 'corporate',
            ],
            [
                'name' => 'Motivation / Mindset',
                'slug' => 'motivation',
                'description' => 'High-energy motivational content, mindset shifts, and success principles.',
                'icon_emoji' => '🔥',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'cinematic',
                'default_caption_preset_name' => 'Bold White',
                'default_voice_tone' => 'energetic',
                'default_music_mood' => 'epic',
            ],
            [
                'name' => 'History / Facts',
                'slug' => 'history',
                'description' => 'Fascinating historical events, forgotten stories, and mind-blowing facts.',
                'icon_emoji' => '🏛️',
                'default_template_type' => 'listicle',
                'default_visual_style' => 'documentary',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'neutral',
                'default_music_mood' => 'calm',
            ],
            [
                'name' => 'Science / Explainer',
                'slug' => 'science',
                'description' => 'Complex scientific concepts broken down into engaging short-form videos.',
                'icon_emoji' => '🔬',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'minimalist',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'clear',
                'default_music_mood' => 'corporate',
            ],
            [
                'name' => 'Product Review',
                'slug' => 'product',
                'description' => 'Honest product reviews, comparisons, and affiliate-ready content for any niche.',
                'icon_emoji' => '📦',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'realistic',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'friendly',
                'default_music_mood' => 'upbeat',
            ],
            [
                'name' => 'True Crime',
                'slug' => 'true-crime',
                'description' => 'Real criminal cases, unsolved mysteries, and investigative storytelling.',
                'icon_emoji' => '🔍',
                'default_template_type' => 'listicle',
                'default_visual_style' => 'dark',
                'default_caption_preset_name' => 'Bold White',
                'default_voice_tone' => 'dramatic',
                'default_music_mood' => 'dark',
            ],
            [
                'name' => 'Self Improvement',
                'slug' => 'self-improvement',
                'description' => 'Daily habits, productivity systems, and personal growth content that builds loyal audiences.',
                'icon_emoji' => '🌱',
                'default_template_type' => 'explainer',
                'default_visual_style' => 'minimalist',
                'default_caption_preset_name' => 'Clean Subtitle',
                'default_voice_tone' => 'calm',
                'default_music_mood' => 'calm',
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
