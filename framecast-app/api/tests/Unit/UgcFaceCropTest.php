<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcFaceCrop;
use PHPUnit\Framework\TestCase;

class UgcFaceCropTest extends TestCase
{
    public function test_the_box_is_read_only_when_it_is_one_sane_face(): void
    {
        $this->assertSame([0.4, 0.1, 0.6, 0.3], UgcFaceCrop::parse('{"face": true, "box": [0.4, 0.1, 0.6, 0.3]}'));
        $this->assertNull(UgcFaceCrop::parse('{"face": false, "box": [0.4, 0.1, 0.6, 0.3]}'));
        $this->assertNull(UgcFaceCrop::parse('{"face": true, "box": [0.4, 0.1, 1.6, 0.3]}'));
        $this->assertNull(UgcFaceCrop::parse('{"face": true, "box": [0.4, 0.1, 0.41, 0.3]}'));
        $this->assertNull(UgcFaceCrop::parse('no json'));
    }

    public function test_the_square_holds_hair_and_chin_and_stays_inside_the_photo(): void
    {
        // A 1000x2000 portrait, the face 200x240 px near the top.
        [$left, $top, $side] = UgcFaceCrop::square([0.4, 0.1, 0.6, 0.22], 1000, 2000);
        $this->assertSame(432, $side, '1.8x the face\'s larger side');
        $this->assertSame(284, $left);
        $this->assertSame(80, $top, 'centred a little above the face\'s middle');
        [, $top] = UgcFaceCrop::square([0.4, 0.0, 0.6, 0.1], 1000, 2000);
        $this->assertSame(0, $top, 'kept inside the photo at the top edge');
        [$left, , $side] = UgcFaceCrop::square([0.9, 0.5, 1.0, 0.6], 1000, 1000);
        $this->assertSame(1000 - $side, $left, 'kept inside at the right edge');
    }
}
