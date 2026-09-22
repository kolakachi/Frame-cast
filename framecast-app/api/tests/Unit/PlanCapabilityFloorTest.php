<?php

namespace Tests\Unit;

use App\Services\CreditService;
use Tests\TestCase;

/**
 * The $9 UGC test pass is the smallest paid thing we sell. A customer on a
 * lifetime Starter reported being unable to use a character with a reference
 * photo — a capability the $9 pass had and their paid tier did not. Any boolean
 * capability the pass grants has to be granted by every larger paid tier too,
 * or support tickets are the only way we find out.
 */
class PlanCapabilityFloorTest extends TestCase
{
    /** Everything sold above the test pass. `free` is deliberately excluded. */
    private const PAID_TIERS = [
        'starter', 'creator', 'pro', 'agency', 'enterprise', 'studio', 'scale',
        'appsumo_starter', 'appsumo_creator', 'appsumo_agency',
        'lifetime_starter', 'lifetime_creator', 'lifetime_agency',
    ];

    public function test_no_paid_tier_offers_less_than_the_nine_dollar_pass(): void
    {
        $pass = CreditService::PLAN_LIMITS['ugc_pass'];

        foreach (self::PAID_TIERS as $tier) {
            $limits = CreditService::PLAN_LIMITS[$tier] ?? null;
            $this->assertIsArray($limits, "{$tier} is missing from PLAN_LIMITS");

            foreach ($pass as $capability => $granted) {
                if ($granted !== true) {
                    continue; // only boolean capabilities the pass actually grants
                }

                $this->assertTrue(
                    $limits[$capability] ?? false,
                    "{$tier} does not grant '{$capability}', but the \$9 ugc_pass does.",
                );
            }
        }
    }

    public function test_starter_tiers_can_use_their_own_characters(): void
    {
        // The specific gap the customer hit — CharacterController lets them
        // create one, UgcController::limitFor decides whether they may use it.
        foreach (['starter', 'appsumo_starter', 'lifetime_starter'] as $tier) {
            $this->assertTrue(
                CreditService::PLAN_LIMITS[$tier]['custom_characters'],
                "{$tier} should be able to use a character with a reference photo",
            );
        }
    }

    public function test_free_still_cannot(): void
    {
        $this->assertFalse(CreditService::PLAN_LIMITS['free']['custom_characters']);
        $this->assertSame(0, CreditService::PLAN_LIMITS['free']['max_characters']);
    }
}
