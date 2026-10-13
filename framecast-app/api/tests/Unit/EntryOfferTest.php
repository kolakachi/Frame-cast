<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\KelviqService;
use App\Services\WorkspaceUsageService;
use Tests\TestCase;

/**
 * The sales.wyvstudio.com entry offer (owner, 2026-10-13): $69 once for 4,000 credits with the lifetime Creator's
 * access. Its own tier, so it is never watermarked, never ranked below a Starter it replaces, and never mistaken for
 * the $199 Creator's 12,000-credit bucket when a pack is swapped.
 */
class EntryOfferTest extends TestCase
{
    public function test_entry_has_the_lifetime_creators_access_and_no_watermark(): void
    {
        $this->assertSame(CreditService::PLAN_LIMITS['lifetime_creator'], CreditService::PLAN_LIMITS['lifetime_entry']);
        $this->assertSame(0, CreditService::PLAN_CREDITS['lifetime_entry']);
        $plan = WorkspaceUsageService::plans()['lifetime_entry'];
        $this->assertFalse($plan['watermark']);
        $this->assertSame(3, $plan['channel_limit']);
        $this->assertSame(WorkspaceUsageService::plans()['lifetime_creator']['voice_cloning_limit'], $plan['voice_cloning_limit']);
        $this->assertTrue(CreditService::limitsFor((new Workspace)->forceFill(['plan_tier' => 'lifetime_entry']))['create']);
    }

    public function test_entry_ranks_with_creator_and_keeps_its_own_bucket(): void
    {
        $rank = new \ReflectionMethod(KelviqService::class, 'tierRank');
        $bucket = new \ReflectionMethod(KelviqService::class, 'lifetimeBucket');
        $this->assertSame(2, $rank->invoke(null, 'lifetime_entry'));
        $this->assertSame($rank->invoke(null, 'appsumo_creator'), $rank->invoke(null, 'lifetime_entry'));
        $this->assertGreaterThan($rank->invoke(null, 'appsumo_starter'), $rank->invoke(null, 'lifetime_entry'));
        $this->assertSame(4000, $bucket->invoke(null, 'lifetime_entry'));
        $this->assertSame(12000, $bucket->invoke(null, 'lifetime_creator'));
    }

    public function test_entry_is_sold_through_the_one_time_checkout(): void
    {
        $ids = array_keys(array_filter((array) config('billing.kelviq.lifetime_plans'), fn ($m) => ($m['tier'] ?? null) === 'lifetime_entry'));
        $this->assertCount(1, $ids);
        $this->assertSame(4000, config('billing.kelviq.lifetime_plans')[$ids[0]]['credits']);
        $this->assertArrayHasKey('lifetime_entry', config('billing.kelviq.plan_labels'));
    }
}
