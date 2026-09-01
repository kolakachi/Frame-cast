<?php

namespace Tests\Unit;

use App\Models\Scene;
use App\Traits\RendersExportScenes;
use PHPUnit\Framework\TestCase;

class MotionExportParityTest extends TestCase
{
    public function test_still_image_without_saved_settings_uses_crop_and_gets_default_zoom(): void
    {
        $renderer = $this->renderer();
        $scene = new Scene(['motion_settings_json' => null]);

        $this->assertFalse($renderer->usesFit($scene, false));
        $this->assertStringStartsWith(
            'zoompan=',
            $renderer->motion($scene, ['width' => 1080, 'height' => 1920], 3.92),
        );
    }

    public function test_explicit_legacy_fit_is_preserved_and_videos_always_fit(): void
    {
        $renderer = $this->renderer();

        $this->assertTrue($renderer->usesFit(
            new Scene(['motion_settings_json' => ['fit' => 'fit']]),
            false,
        ));
        $this->assertTrue($renderer->usesFit(
            new Scene(['motion_settings_json' => ['fit' => 'crop']]),
            true,
        ));
    }

    private function renderer(): object
    {
        return new class
        {
            use RendersExportScenes;

            public function usesFit(Scene $scene, bool $isVideo): bool
            {
                return $this->shouldUseFit($scene, $isVideo);
            }

            /** @param array{width:int,height:int} $dimensions */
            public function motion(Scene $scene, array $dimensions, float $duration): ?string
            {
                return $this->buildMotionFilter($scene, $dimensions, $duration);
            }
        };
    }
}
