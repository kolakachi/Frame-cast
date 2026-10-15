<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcOneShotPricing;
use App\Services\Ugc\UgcVoices;
use Tests\TestCase;

class UgcVoicesTest extends TestCase
{
    public function test_each_speaker_keeps_one_voice_and_two_speakers_never_share_one(): void
    {
        $segments = [['script_text' => 'Hi', 'speaker' => 1, 'voice_direction' => 'Excited', 'speed' => 1.1], ['script_text' => 'Hey', 'speaker' => 2, 'voice_direction' => 'Excited']];
        $chunks = UgcVoices::assign([
            ['dialogue' => 'Hi', 'speaker' => 1], ['dialogue' => 'Hey', 'speaker' => 2], ['dialogue' => 'Again', 'speaker' => 1], ['dialogue' => ''],
        ], $segments, [1 => null, 2 => null], 'a woman');
        $this->assertSame($chunks[0]['voice'], $chunks[2]['voice']);
        $this->assertNotSame($chunks[0]['voice']['id'], $chunks[1]['voice']['id']);
        $this->assertSame(1.1, $chunks[0]['voice']['speed']);
        $this->assertArrayNotHasKey('voice', $chunks[3], 'nothing to read');
    }

    public function test_the_voice_follows_the_presenter_and_the_delivery(): void
    {
        $this->assertSame('male', UgcVoices::gender(null, 'a bearded man in his 30s'));
        $this->assertSame('female', UgcVoices::gender(null, ''));
        $this->assertSame('deadpan', UgcVoices::delivery('Dry, deadpan'));
        $this->assertContains(UgcVoices::pick('male', 'excited', 4), ['Puck', 'Sadachbia']);
    }

    public function test_asmr_is_never_read_or_charged_for_lip_sync(): void
    {
        $this->assertArrayNotHasKey('voice', UgcVoices::assign([['dialogue' => 'listen', 'requires_audio' => true]], [['script_text' => 'listen']], [0 => null])[0]);
        $segments = [['script_text' => 'Hi there', 'seconds' => 4.2], ['script_text' => '', 'seconds' => 3]];
        $this->assertSame(5 * 12, UgcOneShotPricing::lipsyncCredits('omni', $segments));
        $this->assertSame(0, UgcOneShotPricing::lipsyncCredits('omni', $segments, 'asmr'));
        $this->assertSame(0, UgcOneShotPricing::lipsyncCredits('seedance25', $segments));
        config(['services.ugc.omni_lipsync' => false]);
        $this->assertSame(0, UgcOneShotPricing::lipsyncCredits('omni', $segments));
    }
}
