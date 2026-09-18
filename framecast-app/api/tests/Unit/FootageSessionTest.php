<?php

namespace Tests\Unit;

use App\Models\FootageSession;
use App\Services\Ugc\UgcFootageReader;
use PHPUnit\Framework\TestCase;

/**
 * The correction overlay is the analysis screen's contract: the read is never
 * overwritten, and what planning sees is the read with the user's fixes on top.
 */
class FootageSessionTest extends TestCase
{
    private function session(array $read, array $fixes = []): FootageSession
    {
        $s = new FootageSession;
        $s->setRawAttributes([
            'read_json' => json_encode($read),
            'corrections_json' => json_encode($fixes),
        ]);

        return $s;
    }

    private function read(): array
    {
        return [
            'duration' => 20,
            'speakers' => [['id' => 's1', 'label' => 'Guest', 'on_camera' => true]],
            'passages' => [
                ['id' => 'p1', 'title' => 'Hook', 'kind' => 'observed', 'transcript' => 'Hello there', 'note' => 'heard'],
                ['id' => 'p2', 'title' => 'CTA', 'kind' => 'unclear', 'transcript' => '(cropped)', 'note' => 'text cut off'],
            ],
        ];
    }

    public function test_a_corrected_transcript_replaces_the_read_and_stops_being_a_guess(): void
    {
        $out = $this->session($this->read(), [
            'passages' => ['p2' => ['transcript' => '14-day guarantee, link below']],
        ])->correctedRead();

        $this->assertSame('14-day guarantee, link below', $out['passages'][1]['transcript']);
        // The user said so — it is no longer 'unclear'.
        $this->assertSame('observed', $out['passages'][1]['kind']);
    }

    public function test_an_uncorrected_passage_comes_through_untouched(): void
    {
        $out = $this->session($this->read())->correctedRead();

        $this->assertSame('Hello there', $out['passages'][0]['transcript']);
        $this->assertSame('observed', $out['passages'][0]['kind']);
        $this->assertFalse($out['passages'][0]['important']);
    }

    public function test_dropping_a_passage_marks_it_rather_than_deleting_it(): void
    {
        $out = $this->session($this->read(), [
            'passages' => ['p1' => ['drop' => true]],
        ])->correctedRead();

        $this->assertCount(2, $out['passages']);
        $this->assertTrue($out['passages'][0]['dropped']);
    }

    public function test_a_blank_correction_does_not_erase_the_read(): void
    {
        $out = $this->session($this->read(), [
            'passages' => ['p1' => ['transcript' => '   ']],
        ])->correctedRead();

        $this->assertSame('Hello there', $out['passages'][0]['transcript']);
    }

    public function test_a_renamed_speaker_keeps_their_other_facts(): void
    {
        $out = $this->session($this->read(), [
            'speakers' => ['s1' => ['label' => 'Amara']],
        ])->correctedRead();

        $this->assertSame('Amara', $out['speakers'][0]['label']);
        $this->assertTrue($out['speakers'][0]['on_camera']);
    }

    public function test_reader_kinds_are_the_three_the_analysis_screen_knows(): void
    {
        $this->assertSame(['observed', 'inferred', 'unclear'], UgcFootageReader::KINDS);
    }
}
