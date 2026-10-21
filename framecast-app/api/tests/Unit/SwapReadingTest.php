<?php

namespace Tests\Unit;

use App\Services\Create\PartSwap;
use App\Services\Create\Planning\PlanPrompt;
use Tests\TestCase;

/** A swapped text gets the reading time its slot had: too long goes back to the planner for a creative fit (2026-10-21). */
class SwapReadingTest extends TestCase
{
    private function context(string $said): array
    {
        return ['messages' => [['role' => 'user', 'content' => $said]],
            'current_variables' => [['id' => 'reason_2', 'type' => 'string', 'label' => 'Reason 2', 'current' => 'Small batch', 'max_chars' => 17, 'read_chars' => PartSwap::readChars('Small batch')]]];
    }

    public function test_the_budget_is_the_current_words_letters(): void
    {
        $this->assertSame(10, PartSwap::readChars('Small batch'), 'letters, not spaces');
        $this->assertSame(6, PartSwap::readChars('Go now'), 'at least six');
        $this->assertNull(PartSwap::readChars('fieldwell.co'), 'one word is left to max_chars');
        $this->assertNull(PartSwap::readChars('$45'));
    }

    public function test_a_long_swap_is_sent_back_for_a_creative_fit(): void
    {
        $plan = ['swap' => ['values' => ['reason_2' => 'Hand-dipped pairs']]];
        $note = PlanPrompt::swapTooLong($plan, $this->context('Use hand-dipped pairs as the second reason'));
        $this->assertStringContainsString('creative shorter version', (string) $note);
        $this->assertSame([$note], PlanPrompt::problems($plan, $this->context('Use hand-dipped pairs as the second reason')));
        $this->assertNull(PlanPrompt::swapTooLong(['swap' => ['values' => ['reason_2' => 'Hand dipped']]], $this->context('x')), 'fits: 10 letters');
    }

    public function test_words_the_user_quoted_are_kept(): void
    {
        $plan = ['swap' => ['values' => ['reason_2' => 'Hand-dipped pairs']]];
        $this->assertNull(PlanPrompt::swapTooLong($plan, $this->context('The second reason must say "Hand-dipped pairs"')));
    }
}
