<?php

namespace Tests\Unit;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Ugc\UgcPlan;
use App\Services\Ugc\UgcShotPlanner;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Character fan-out answers "who says it" and was already there. This answers
 * "how it starts", which is the axis that decides whether anyone watches the
 * rest — one idea with six openings, rather than one opening on six faces.
 */
class UgcHookVariantsTest extends TestCase
{
    private function planner(string $reply): UgcShotPlanner
    {
        $ai = new class($reply) implements AIGenerationAdapter
        {
            public array $vars = [];

            public function __construct(private string $reply) {}

            public function generate(string $k, array $v, int $m = 900, float $t = 0.4, array $o = []): array
            {
                $this->vars = $v;

                return ['content' => $this->reply];
            }
        };

        return new UgcShotPlanner($ai);
    }

    private function plan(): array
    {
        return UgcPlan::normalise([
            ['kind' => 'on_camera', 'script_text' => 'I used to spend hours on this.', 'seconds' => 5,
             'visual_brief' => 'Kitchen, morning light.'],
            ['kind' => 'b_roll', 'source' => 'upload', 'script_text' => 'Now it takes minutes.', 'seconds' => 4,
             'visual_brief' => 'Screen recording.', 'anchor' => 'I used to spend hours on this.', 'anchor_role' => 'prove'],
        ], 'demo');
    }

    private function reply(): string
    {
        return json_encode(['variants' => [
            ['label' => 'blunt claim', 'script_text' => 'This saved me four hours a week.', 'headline' => 'Four hours'],
            ['label' => 'question', 'script_text' => 'How long does your editing take?', 'headline' => ''],
        ]]);
    }

    public function test_each_variant_swaps_only_the_opening(): void
    {
        $out = $this->planner($this->reply())->hookVariants($this->plan(), 'demo', 2);

        $this->assertCount(2, $out);
        $this->assertSame('This saved me four hours a week.', $out[0]['segments'][0]['script_text']);
        $this->assertSame('Now it takes minutes.', $out[0]['segments'][1]['script_text'], 'the rest of the ad is untouched');
    }

    public function test_the_new_opening_becomes_its_own_anchor(): void
    {
        $out = $this->planner($this->reply())->hookVariants($this->plan(), 'demo', 2);

        $this->assertSame('This saved me four hours a week.', $out[0]['segments'][0]['anchor']);
    }

    public function test_a_shot_proving_the_old_opening_is_flagged_stale(): void
    {
        $out = $this->planner($this->reply())->hookVariants($this->plan(), 'demo', 2);

        // The cutaway was proving "I used to spend hours on this", which this
        // variant no longer says. Anchoring exists to catch exactly this.
        $this->assertTrue($out[0]['segments'][1]['stale']);
    }

    public function test_the_duration_re_derives_from_the_new_words(): void
    {
        $out = $this->planner($this->reply())->hookVariants($this->plan(), 'demo', 2);

        foreach ($out as $variant) {
            $this->assertGreaterThan(0, $variant['segments'][0]['seconds']);
        }
    }

    public function test_labels_name_the_angle_for_someone_choosing(): void
    {
        $out = $this->planner($this->reply())->hookVariants($this->plan(), 'demo', 2);

        $this->assertSame(['blunt claim', 'question'], array_column($out, 'label'));
    }

    public function test_an_ad_with_no_spoken_opening_cannot_have_hooks_varied(): void
    {
        $silent = UgcPlan::normalise([[
            'kind' => 'reaction', 'script_text' => '', 'seconds' => 5,
            'visual_brief' => 'b', 'motion_prompt' => 'm', 'headline' => 'h',
        ]], 'reaction');

        $this->expectExceptionMessageMatches('/no spoken opening/i');
        $this->planner($this->reply())->hookVariants($silent, 'reaction', 2);
    }

    public function test_an_unusable_reply_changes_nothing(): void
    {
        $this->expectException(ValidationException::class);
        $this->planner('not json')->hookVariants($this->plan(), 'demo', 2);
    }
}
