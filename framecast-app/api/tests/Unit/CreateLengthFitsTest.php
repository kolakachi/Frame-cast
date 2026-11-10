<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Create\WorkerController;
use PHPUnit\Framework\TestCase;

/** The delivered length follows the worker's render rule (LENGTH-TARGET): the approved length, or up to 30% longer. */
class CreateLengthFitsTest extends TestCase
{
    public function test_a_video_a_little_longer_for_its_speech_is_delivered(): void
    {
        $this->assertTrue(WorkerController::lengthFits(16.4, 15), 'ws 79: a 16.3 s page rendered to 16.4 s and was refused on every retry');
        $this->assertTrue(WorkerController::lengthFits(15.2, 15));
        $this->assertTrue(WorkerController::lengthFits(14.8, 15));
        $this->assertTrue(WorkerController::lengthFits(19.8, 15), '30% longer, with the encoder\'s rounding');
        $this->assertTrue(WorkerController::lengthFits(20.5, 15), 'longer still: delivered at its real length (owner, 2026-11-10)');
        $this->assertTrue(WorkerController::lengthFits(12, 15), 'shorter: delivered at its real length too');
        $this->assertFalse(WorkerController::lengthFits(0, 15), 'no video at all');
    }
}
