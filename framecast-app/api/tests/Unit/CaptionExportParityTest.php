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

        // 17px editor base * 4 export scale * Comic's 1.4 multi-word scale.
        $this->assertStringContainsString('Style: Default,Luckiest Guy,95,', $ass);
        $this->assertStringContainsString('\\fax-0.10\\fscx20\\fscy20', $ass);
        $this->assertStringContainsString('\\t(0,150,\\fscx118\\fscy118)', $ass);
    }

    private function renderer(): object
    {
        return new class
        {
            use RendersExportScenes;

            public function caption(string $size, string $animation = 'plain'): string
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
                        'line_by_line',
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
