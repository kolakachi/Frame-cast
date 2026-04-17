<?php

namespace Database\Seeders;

use App\Models\CaptionPreset;
use Illuminate\Database\Seeder;

class CaptionPresetSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            [
                'name' => 'Bold Focus',
                'preset_type' => 'highlight',
                'font' => 'Poppins',
                'font_size_rule' => 'adaptive_large',
                'highlight_mode' => 'word_by_word',
                'highlight_color' => '#F8D85A',
                'animation_type' => 'pop',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 5, 'max_lines' => 2],
            ],
            [
                'name' => 'Clean Story',
                'preset_type' => 'minimal',
                'font' => 'Manrope',
                'font_size_rule' => 'adaptive_medium',
                'highlight_mode' => 'keywords',
                'highlight_color' => '#67E8F9',
                'animation_type' => 'fade',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 6, 'max_lines' => 2],
            ],
            [
                'name' => 'Classic Subtitles',
                'preset_type' => 'standard',
                'font' => 'Inter',
                'font_size_rule' => 'adaptive_medium',
                'highlight_mode' => 'none',
                'highlight_color' => '#FFFFFF',
                'animation_type' => 'none',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 7, 'max_lines' => 2],
            ],
            [
                'name' => 'Bold Impact',
                'preset_type' => 'highlight',
                'font' => 'Bebas Neue',
                'font_size_rule' => 'adaptive_large',
                'highlight_mode' => 'word_by_word',
                'highlight_color' => '#FF4444',
                'animation_type' => 'pop',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 4, 'max_lines' => 1],
            ],
            [
                'name' => 'Subtitle',
                'preset_type' => 'standard',
                'font' => 'Lato',
                'font_size_rule' => 'adaptive_medium',
                'highlight_mode' => 'line_by_line',
                'highlight_color' => '#FFFFFF',
                'animation_type' => 'fade',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 8, 'max_lines' => 2],
            ],
            [
                'name' => 'Handwritten',
                'preset_type' => 'minimal',
                'font' => 'Permanent Marker',
                'font_size_rule' => 'adaptive_medium',
                'highlight_mode' => 'word_by_word',
                'highlight_color' => '#FFFFFF',
                'animation_type' => 'none',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 5, 'max_lines' => 2],
            ],
            [
                'name' => 'Cinematic',
                'preset_type' => 'minimal',
                'font' => 'Playfair Display',
                'font_size_rule' => 'adaptive_medium',
                'highlight_mode' => 'word_by_word',
                'highlight_color' => '#FFFFFF',
                'animation_type' => 'fade',
                'safe_area_profile' => 'shorts_safe',
                'line_break_rules_json' => ['max_words_per_line' => 6, 'max_lines' => 2],
            ],
        ];

        foreach ($presets as $preset) {
            CaptionPreset::query()->updateOrCreate(
                [
                    'workspace_id' => null,
                    'name' => $preset['name'],
                ],
                $preset,
            );
        }
    }
}
