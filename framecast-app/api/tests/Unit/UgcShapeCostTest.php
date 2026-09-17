<?php

namespace Tests\Unit;

use App\Services\CreditService;
use App\Services\Ugc\UgcPlan;
use Tests\TestCase;

/**
 * Seconds on camera are ~96% of a long take and the only cost that grows with
 * length. A customer choosing the expensive shape should be told what it costs
 * at the point of choosing, not discover it on the invoice.
 */
class UgcShapeCostTest extends TestCase
{
    private function words(int $n): string
    {
        return implode(' ', array_fill(0, $n, 'word'));
    }

    private function talking(float $seconds): array
    {
        return ['kind' => 'on_camera', 'script_text' => $this->words((int) ceil($seconds * 2.6)),
                'seconds' => $seconds, 'visual_brief' => 'Kitchen, morning light.'];
    }

    private function cutaway(float $seconds, string $source = 'stock'): array
    {
        return ['kind' => 'b_roll', 'source' => $source, 'script_text' => $this->words((int) ceil($seconds * 2.6)),
                'seconds' => $seconds, 'visual_brief' => 'The product on a desk.'];
    }

    public function test_on_camera_seconds_ignore_everything_that_is_not_a_face(): void
    {
        $out = UgcPlan::normalise([$this->talking(5), $this->cutaway(6), $this->talking(4)], 'demo');

        // Against the normalised durations, not the ones asked for: normalise()
        // floors a shot at its own word count, so the two differ slightly.
        $expected = $out[0]['seconds'] + $out[2]['seconds'];
        $this->assertSame($expected, UgcPlan::onCameraSeconds($out));
        $this->assertLessThan(array_sum(array_column($out, 'seconds')), UgcPlan::onCameraSeconds($out));
    }

    public function test_a_long_talking_take_is_told_what_it_costs_and_what_it_would_save(): void
    {
        $out = UgcPlan::normalise([$this->talking(30)], 'direct_camera');
        $warnings = implode(' ', UgcPlan::warnings($out));

        $this->assertStringContainsString('30 seconds on camera', $warnings);
        $this->assertStringContainsString((string) CreditService::spokespersonCost(30), $warnings);
        $saving = CreditService::spokespersonCost(30) - CreditService::spokespersonCost(UgcPlan::HOOK_SECONDS);
        $this->assertStringContainsString((string) $saving, $warnings);
    }

    public function test_a_hook_plus_cutaways_is_not_nagged(): void
    {
        $out = UgcPlan::normalise([$this->talking(5), $this->cutaway(8), $this->cutaway(8), $this->cutaway(9)], 'demo');
        $warnings = implode(' ', UgcPlan::warnings($out));

        $this->assertStringNotContainsString('seconds on camera', $warnings);
    }

    public function test_the_shape_is_what_costs_money_not_the_runtime(): void
    {
        $long = UgcPlan::normalise([$this->talking(30)], 'direct_camera');
        $hook = UgcPlan::normalise(
            [$this->talking(5), $this->cutaway(6), $this->cutaway(6), $this->cutaway(6), $this->cutaway(7)],
            'demo',
        );

        // Same ~30 seconds of finished ad, either way.
        $this->assertEqualsWithDelta(30, array_sum(array_column($long, 'seconds')), 1.0);
        $this->assertEqualsWithDelta(30, array_sum(array_column($hook, 'seconds')), 1.0);

        $this->assertLessThan(
            UgcPlan::quote($long) * 0.45,
            UgcPlan::quote($hook),
            'a hook plus cutaways should cost well under half a continuous take',
        );
    }

    public function test_a_silent_reaction_has_no_on_camera_seconds_to_warn_about(): void
    {
        $out = UgcPlan::normalise([[
            'kind' => 'reaction', 'script_text' => '', 'seconds' => 10,
            'visual_brief' => 'b', 'motion_prompt' => 'm', 'headline' => 'h',
        ]], 'reaction');

        $this->assertSame(0.0, UgcPlan::onCameraSeconds($out));
        $this->assertStringNotContainsString('seconds on camera', implode(' ', UgcPlan::warnings($out)));
    }
}
