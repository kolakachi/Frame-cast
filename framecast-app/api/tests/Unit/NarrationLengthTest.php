<?php

namespace Tests\Unit;

use App\Services\Generation\TTS\OpenAITTSAdapter;
use Tests\TestCase;

/** OpenAI-voice narration is stored at its measured length, not a word-count estimate (2026-11-17). */
class NarrationLengthTest extends TestCase
{
    public function test_an_mp3_is_measured(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nl-').'.mp3';
        exec('ffmpeg -loglevel error -y -f lavfi -i sine=f=440:d=3.3 -ar 24000 -ac 1 '.escapeshellarg($tmp));
        $this->assertEqualsWithDelta(3.3, OpenAITTSAdapter::measure((string) file_get_contents($tmp)), 0.08);
        @unlink($tmp);
    }

    public function test_unreadable_audio_gives_no_length(): void
    {
        $this->assertNull(OpenAITTSAdapter::measure('not audio'));
    }
}
