<?php

namespace Tests\Unit;

use App\Traits\RendersExportScenes;
use PHPUnit\Framework\TestCase;

class CaptionExportParityTest extends TestCase
{
    public function test_export_font_sizes_match_the_480px_editor_preview_scale(): void
    {
        $renderer = $this->renderer();

        foreach (['small' => 52, 'medium' => 68, 'large' => 92, 'xlarge' => 120] as $size => $expected) {
            $ass = $renderer->caption($size);

            $this->assertStringContainsString(
                "Style: Default,Luckiest Guy,{$expected},",
                $ass,
                "The {$size} export caption should be the editor size scaled from 480px to 1920px."
            );
        }
    }

    public function test_comic_line_mode_keeps_preview_scale_and_active_word_motion(): void
    {
        $ass = $this->renderer()->caption('medium', 'comic');

        // 17px editor base * 4 export scale * Comic's optical 1.75 scale,
        // converted from CSS pixels to the font's ASS line-box units.
        $this->assertStringContainsString('Style: Default,Luckiest Guy,146,', $ass);
        $this->assertStringContainsString('\\frz14\\fscx35\\fscy35', $ass);
        $this->assertStringContainsString('\\t(0,150,\\fscx107\\fscy107\\frz3)', $ass);
    }

    public function test_one_word_glitch_uses_positioned_rgb_layers_and_primary_text_colour(): void
    {
        $ass = $this->renderer()->caption('medium', 'glitch', 'keywords');

        $this->assertStringNotContainsString('\\move(', $ass);
        $this->assertStringContainsString('Style: Default,Luckiest Guy,158,', $ass);
        $this->assertStringContainsString('\\1c&HFFFF00&', $ass); // cyan ghost in ASS BGR
        $this->assertStringContainsString('\\1c&HFF00FF&', $ass); // magenta ghost
        $this->assertStringContainsString('\\1c&HFFFFFF&\\alpha&H99&', $ass);
        // CSS steps(2) is exported as held 0%, midpoint, 35%, midpoint,
        // 70%, midpoint states. No continuous ASS transform may reverse the
        // lean while Chrome is still holding its previous step.
        $this->assertStringContainsString('\\alpha&H99&\\fax0.213\\shad0', $ass);
        $this->assertStringContainsString('\\alpha&H4D&\\fax0.035\\shad0', $ass);
        $this->assertStringContainsString('\\alpha&H00&\\fax-0.141\\shad0', $ass);
        $this->assertStringContainsString('\\alpha&H00&\\fax-0.070\\shad0', $ass);
        $this->assertStringContainsString('\\alpha&H80&\\bord0\\shad0\\fax0.000', $ass);
        $this->assertStringNotContainsString('\\t(', $ass);
        $this->assertMatchesRegularExpression('/Style: Default,[^\\n]+,1,0\\.0,\\d+\\.\\d+,2,/', $ass);
    }

    public function test_line_mode_glitch_uses_rgb_layers_and_highlight_text_colour(): void
    {
        $ass = $this->renderer()->caption('medium', 'glitch', 'line_by_line');

        $this->assertStringContainsString('\\1c&HFFFF00&', $ass);
        $this->assertStringContainsString('\\1c&HFF00FF&', $ass);
        $this->assertStringContainsString('\\1c&H000000&\\alpha&H99&', $ass);
        $this->assertStringContainsString('Style: Default,Luckiest Guy,116,', $ass);
    }

    private function renderer(): object
    {
        return new class
        {
            use RendersExportScenes;

            public function caption(string $size, string $animation = 'plain', string $highlightMode = 'line_by_line'): string
            {
                $path = tempnam(sys_get_temp_dir(), 'caption-parity-');

                try {
                    $this->buildASSCaption(
                        'Ever notice how your',
                        'impact',
                        'bottom_third',
                        'Luckiest Guy',
                        2.0,
                        ['width' => 1080, 'height' => 1920],
                        $path,
                        $highlightMode,
                        [
                            ['text' => 'Ever', 'start' => 0.0, 'end' => 0.4],
                            ['text' => 'notice', 'start' => 0.4, 'end' => 0.9],
                            ['text' => 'how', 'start' => 0.9, 'end' => 1.3],
                            ['text' => 'your', 'start' => 1.3, 'end' => 1.8],
                        ],
                        '#ffffff',
                        $size,
                        '#000000',
                        ['animation' => $animation, 'highlight_style' => 'color'],
                    );

                    return (string) file_get_contents($path);
                } finally {
                    @unlink($path);
                }
            }
        };
    }
}
