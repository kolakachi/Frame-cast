<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcOneShotCompiler;
use PHPUnit\Framework\TestCase;

class UgcOneShotCompilerTest extends TestCase
{
    private function beats(): array
    {
        return [
            ['script_text' => 'Line one.', 'seconds' => 5.4, 'visual_brief' => 'close selfie framing', 'voice_direction' => 'doubtful'],
            ['script_text' => 'Line two.', 'seconds' => 5, 'visual_brief' => 'she holds up the bottle'],
            ['script_text' => 'Line three.', 'seconds' => 3.1, 'visual_brief' => 'closer framing, delighted'],
        ];
    }

    public function test_beats_pack_into_veo_sized_chunks(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        $this->assertCount(3, $chunks); // 5.4 | 5 | 3.1 — none pair under 8s
        foreach ($chunks as $c) {
            $this->assertGreaterThanOrEqual(UgcOneShotCompiler::MIN_SEGMENT_SECONDS, $c['seconds']);
            $this->assertLessThanOrEqual(UgcOneShotCompiler::MAX_SEGMENT_SECONDS, $c['seconds']);
        }
    }

    public function test_short_beats_share_a_generation_with_a_cut(): void
    {
        $chunks = UgcOneShotCompiler::compile([
            ['script_text' => 'A.', 'seconds' => 3, 'visual_brief' => 'wide framing'],
            ['script_text' => 'B.', 'seconds' => 4, 'visual_brief' => 'macro of the label'],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Cut to macro of the label', $chunks[0]['prompt']);
    }

    public function test_a_continuation_chunk_keeps_its_beats_framing(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        // The bug this guards: chunk 1's action ("holds up the bottle") was
        // dropped because only cuts and the first chunk carried framing.
        $this->assertStringContainsString('holds up the bottle', $chunks[1]['prompt']);
        $this->assertStringContainsString('continues without a break', $chunks[1]['prompt']);
        $this->assertStringNotContainsString('continues without a break', $chunks[0]['prompt']);
    }

    public function test_dialogue_is_quoted_verbatim_and_collected(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats());

        $this->assertStringContainsString('"Line one."', $chunks[0]['prompt']);
        $this->assertSame('Line one.', $chunks[0]['dialogue']);
        $this->assertStringContainsString('doubtful', $chunks[0]['prompt']);
    }

    public function test_style_reaches_the_preamble_of_every_chunk(): void
    {
        $chunks = UgcOneShotCompiler::compile($this->beats(), [
            'presenter' => 'a man in a grey hoodie', 'product' => 'Fernwell Sleep+',
        ]);

        foreach ($chunks as $c) {
            $this->assertStringContainsStringIgnoringCase('a man in a grey hoodie', $c['prompt']);
            $this->assertStringContainsString('Fernwell Sleep+', $c['prompt']);
        }
    }

    public function test_empty_input_compiles_to_nothing(): void
    {
        $this->assertSame([], UgcOneShotCompiler::compile([]));
    }
}
