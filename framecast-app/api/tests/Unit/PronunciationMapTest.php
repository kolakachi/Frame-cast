<?php

namespace Tests\Unit;

use App\Services\Ugc\PronunciationMap;
use App\Services\Ugc\UgcOneShotCompiler;
use Tests\TestCase;

class PronunciationMapTest extends TestCase
{
    public function test_parses_the_shapes_people_actually_write(): void
    {
        $notes = <<<'NOTES'
        WyvStudio: "wiv studio"
        Nguyen = win
        Say "Hermès" as "air mez"
        Xero (zero)
        NOTES;
        $map = PronunciationMap::parse($notes);
        $this->assertSame('wiv studio', $map['WyvStudio']);
        $this->assertSame('win', $map['Nguyen']);
        $this->assertSame('air mez', $map['Hermès']);
        $this->assertSame('zero', $map['Xero']);
    }

    public function test_respells_whole_words_case_insensitively(): void
    {
        $out = PronunciationMap::respell('Try WyvStudio today with wyvstudio pro.', 'WyvStudio: "wiv studio"');
        $this->assertSame('Try wiv studio today with wiv studio pro.', $out);
        // Not a substring match: WyvStudioX is left alone.
        $this->assertSame('WyvStudioX', PronunciationMap::respell('WyvStudioX', 'WyvStudio: wiv studio'));
    }

    public function test_ordinary_hyphenated_names_survive(): void
    {
        // A dash line without a quoted phonetic must NOT be parsed as a mapping.
        $this->assertSame([], PronunciationMap::parse('Jean-Luc appears in shot two'));
    }

    public function test_compiler_respells_only_the_spoken_prompt(): void
    {
        $beats = [[
            'kind' => 'on_camera', 'seconds' => 6,
            'script_text' => 'Check out WyvStudio, it is a game changer.',
            'visual_brief' => 'a kitchen', 'voice_direction' => 'warm',
        ]];
        $single = UgcOneShotCompiler::compileSingle($beats, ['pronunciations' => 'WyvStudio: "wiv studio"']);
        $this->assertStringContainsString('wiv studio', $single['prompt']);
        $this->assertStringNotContainsString('WyvStudio', $single['prompt']);
        // With no notes, the real spelling is preserved.
        $plain = UgcOneShotCompiler::compileSingle($beats, []);
        $this->assertStringContainsString('WyvStudio', $plain['prompt']);
    }
}
