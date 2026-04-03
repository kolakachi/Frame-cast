<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Explainer',
                'template_type' => 'explainer',
                'description' => 'Hook-first educational short with paced narration and clear CTA close.',
                'scene_structure_json' => [
                    ['type' => 'hook', 'duration_seconds' => 4],
                    ['type' => 'narration', 'duration_seconds' => 9],
                    ['type' => 'narration', 'duration_seconds' => 9],
                    ['type' => 'narration', 'duration_seconds' => 8],
                    ['type' => 'text_card', 'duration_seconds' => 3],
                ],
                'caption_style_json' => [
                    'preset_hint' => 'Bold Focus',
                    'highlight_mode' => 'word_by_word',
                ],
                'voice_style_json' => [
                    'pace' => 'medium',
                    'energy' => 'confident',
                ],
                'color_font_rules_json' => [
                    'font_primary' => 'Poppins',
                    'contrast' => 'high',
                ],
                'transition_rules_json' => [
                    'default' => 'cut',
                    'scene_boundary' => 'quick_swipe',
                ],
                'timing_rules_json' => [
                    'target_total_seconds' => 33,
                    'max_total_seconds' => 45,
                ],
                'supported_formats' => ['9:16', '1:1', '16:9'],
                'supported_languages' => ['en'],
                'status' => 'active',
            ],
            [
                'name' => 'Listicle',
                'template_type' => 'listicle',
                'description' => 'Numbered format optimized for quick scanning and retention hooks.',
                'scene_structure_json' => [
                    ['type' => 'hook', 'duration_seconds' => 4],
                    ['type' => 'narration', 'duration_seconds' => 7, 'label' => 'Point 1'],
                    ['type' => 'narration', 'duration_seconds' => 7, 'label' => 'Point 2'],
                    ['type' => 'narration', 'duration_seconds' => 7, 'label' => 'Point 3'],
                    ['type' => 'text_card', 'duration_seconds' => 3],
                ],
                'caption_style_json' => [
                    'preset_hint' => 'Clean Story',
                    'highlight_mode' => 'keywords',
                ],
                'voice_style_json' => [
                    'pace' => 'medium_fast',
                    'energy' => 'dynamic',
                ],
                'color_font_rules_json' => [
                    'font_primary' => 'Manrope',
                    'contrast' => 'high',
                ],
                'transition_rules_json' => [
                    'default' => 'cut',
                    'scene_boundary' => 'flash',
                ],
                'timing_rules_json' => [
                    'target_total_seconds' => 28,
                    'max_total_seconds' => 40,
                ],
                'supported_formats' => ['9:16', '1:1'],
                'supported_languages' => ['en'],
                'status' => 'active',
            ],
        ];

        foreach ($templates as $template) {
            Template::query()->updateOrCreate(
                [
                    'workspace_id' => null,
                    'name' => $template['name'],
                ],
                $template,
            );
        }
    }
}
