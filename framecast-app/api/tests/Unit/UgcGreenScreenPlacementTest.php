<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcComposition;
use Tests\TestCase;

/**
 * Green screen (2026-10-14): where the presenter sits over the background is read from the brief and adjustable on the
 * pitch; the background fills the 9:16 frame instead of sitting between black bars.
 */
class UgcGreenScreenPlacementTest extends TestCase
{
    public function test_the_placement_is_read_from_plain_words(): void
    {
        $this->assertSame(['placement' => 'bottom_right', 'size' => 'medium'], UgcComposition::placementFrom('A 15-second ad in front of our homepage.'));
        $this->assertSame(['placement' => 'bottom_left', 'size' => 'small'], UgcComposition::placementFrom('Put me small on the left please'));
        $this->assertSame('bubble_right', UgcComposition::placementFrom('me in a little circle in the corner')['placement']);
        $this->assertSame('side_left', UgcComposition::placementFrom('I take half the screen, on the left')['placement']);
        $this->assertSame('bottom_center', UgcComposition::placementFrom('stand in the middle')['placement']);
        $this->assertSame('large', UgcComposition::placementFrom('make me bigger')['size']);
    }

    public function test_every_placement_overlays_where_it_says(): void
    {
        $at = fn (string $p, string $s = 'medium') => UgcComposition::graph('green_screen', 12, '0x00FF00', $p, $s, 0.45);
        $this->assertStringContainsString('scale=432:768', $at('bottom_right'), 'medium is 60% of the frame, as before');
        $this->assertStringContainsString('overlay=W-w:H-h', $at('bottom_right'));
        $this->assertStringContainsString('overlay=0:H-h', $at('bottom_left'));
        $this->assertStringContainsString('overlay=(W-w)/2:H-h', $at('bottom_center'));
        $this->assertStringContainsString('scale=288:512', $at('bottom_right', 'small'));
        $this->assertStringContainsString('scale=540:960', $at('bottom_right', 'large'));
        $this->assertStringContainsString('scale=576:1024', $at('side_left', 'small'), 'a side placement is 80%, whatever the size');
        $this->assertStringContainsString('overlay=W-w-28:H-h-170', $at('bubble_right'));
        $this->assertStringContainsString('hypot(X-150,Y-150)', $at('bubble_left'));
    }

    public function test_the_background_fills_the_frame_or_sits_over_a_blurred_copy(): void
    {
        $tall = UgcComposition::graph('green_screen', 12, '0x00FF00', 'bottom_right', 'medium', 384 / 848);
        $this->assertStringContainsString('force_original_aspect_ratio=increase,crop=720:1280', $tall, 'a phone recording is cropped to 9:16');
        $this->assertStringNotContainsString('color=black', $tall, 'never black bars');
        $wide = UgcComposition::graph('green_screen', 12, '0x00FF00', 'bottom_right', 'medium', 16 / 9);
        $this->assertStringContainsString('boxblur', $wide, 'a wide page sits whole over a blurred copy of itself');
    }

    public function test_the_key_is_the_backdrop_colour_actually_filmed(): void
    {
        // Take 303: green was asked for, lime #6AE027 was filmed. A presenter-shaped box sits in the middle.
        $clip = tempnam(sys_get_temp_dir(), 'key-').'.mp4';
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=0x6AE027:s=360x640:d=2:r=12',
            '-vf', 'drawbox=x=110:y=120:w=140:h=520:color=0x3a3a44:t=fill', '-c:v', 'libx264', '-pix_fmt', 'yuv444p', '-crf', '0', $clip]);
        $key = UgcComposition::backdropColour($clip);
        @unlink($clip);
        [$r, $g, $b] = sscanf(substr((string) $key, 2), '%02X%02X%02X');
        $this->assertEqualsWithDelta(0x6A, $r, 6); $this->assertEqualsWithDelta(0xE0, $g, 6); $this->assertEqualsWithDelta(0x27, $b, 6);
        $graph = UgcComposition::graph('green_screen', 4, (string) $key);
        $this->assertStringContainsString('chromakey='.$key.':0.14:0.05,despill=type=green:mix=0.25', $graph, 'keyed on what was filmed, the green tint lifted from the edges');
        $this->assertStringNotContainsString('despill', UgcComposition::graph('green_screen', 4, '0xFF00FF'), 'no despill for a magenta key');
    }

    public function test_an_unknown_position_is_refused(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        UgcComposition::resolve((new \App\Models\User)->forceFill(['workspace_id' => 1]), ['layout' => 'green_screen', 'background_asset_id' => 1, 'placement' => 'top_hat']);
    }
}
