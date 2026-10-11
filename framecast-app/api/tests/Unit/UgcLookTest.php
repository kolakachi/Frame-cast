<?php

namespace Tests\Unit;

use App\Jobs\GenerateOneShotUgcJob;
use App\Services\Ugc\UgcFaceCheck;
use App\Services\Ugc\UgcSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** UGC look (owner, 2026-10-11): light kept across joins, the presenter checked against their photo, places as references. */
class UgcLookTest extends TestCase
{
    #[Test]
    public function a_continued_clip_is_brought_to_where_the_last_one_ended_within_fifteen_percent(): void
    {
        // 118 on 2026-10-10: the same close-up came back 11% darker after a join.
        $this->assertSame([1.1097, 1.1053, 1.1148], GenerateOneShotUgcJob::joinGains([172, 159, 141], [1.0, 1.0, 1.0], [155, 143.85, 126.48]));
        $this->assertSame([1.15, 0.85, 1.0], GenerateOneShotUgcJob::joinGains([200, 100, 120], [1.0, 1.0, 1.0], [100, 200, 120]), 'never more than 15%');
        $this->assertSame([1.0, 1.0, 1.0], GenerateOneShotUgcJob::joinGains([30, 30, 30], [1.0, 1.0, 1.0], [5, 4, 3]), 'a near-black frame is left alone');
        $this->assertSame([1.1, 1.0, 1.0], GenerateOneShotUgcJob::joinGains([100, 100, 100], [1.1, 1.0, 1.0], [100, 100, 100]), 'the previous clip\'s own correction carries on');
    }

    #[Test]
    public function the_face_check_reads_a_score_only_when_a_face_was_seen(): void
    {
        $this->assertSame(88, UgcFaceCheck::parse('{"face": true, "score": 88}'));
        $this->assertSame(100, UgcFaceCheck::parse('Sure: {"face": true, "score": 140}'));
        $this->assertNull(UgcFaceCheck::parse('{"face": false, "score": 10}'), 'hands only: nothing to judge');
        $this->assertNull(UgcFaceCheck::parse('I cannot tell.'));
    }

    #[Test]
    public function a_setting_is_a_kind_of_place_the_model_may_vary(): void
    {
        $this->assertSame('this kind of place: a beach', UgcSettings::direction('beach'));
        $note = UgcSettings::note('beach', true);
        $this->assertStringContainsString('choose your own spot, angle, time of day and details', $note);
        $this->assertStringContainsString('never swimwear', $note);
        $this->assertStringNotContainsString('reference image', UgcSettings::note('beach', false), 'no photo went: the prompt does not mention one');
        $this->assertStringContainsString('never swimwear', UgcSettings::note('beach', false), 'the staging rule stays');
        $this->assertSame('', UgcSettings::direction('moon'));
        foreach (UgcSettings::all() as $key => $s) {
            $this->assertNotSame('', $s['place'] ?? '', $key);
            $this->assertNotSame('', $s['plate'] ?? '', $key);
        }
    }
}
