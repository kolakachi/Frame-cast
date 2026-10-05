<?php

namespace Tests\Unit;

use App\Services\Create\RunService;
use Tests\TestCase;

/** The final checks travel with the version: a blocked verdict stays blocked, and only known fields are kept. */
class FinalChecksTest extends TestCase
{
    public function test_a_blocked_version_is_reported_as_blocked_not_softened(): void
    {
        $this->assertSame('blocked', RunService::creativeReview(['status' => 'blocked', 'findings' => ['At 3.0 s: Must appear: the candle']])['status']);
        $this->assertSame('issues', RunService::creativeReview(['status' => 'ready', 'findings' => ['x']])['status']);
    }

    public function test_final_checks_keep_known_fields_bounded(): void
    {
        $c = RunService::finalChecks(['status' => 'blocked', 'checks' => [['id' => 'words', 'label' => 'Every approved word is spoken', 'status' => 'fail', 'blocking' => true,
            'message' => 'Missing: "nine dollar"', 'times' => [1.234, 'x', 9], 'secret' => 'dropped']]]);
        $this->assertSame(['id' => 'words', 'label' => 'Every approved word is spoken', 'status' => 'fail', 'blocking' => true, 'message' => 'Missing: "nine dollar"', 'times' => [1.2, 9.0]], $c['checks'][0]);
        $this->assertNull(RunService::finalChecks(['status' => 'great']), 'an unknown verdict is not stored as if it were checked');
    }
}
