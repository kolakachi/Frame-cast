<?php

namespace Tests\Unit;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Ugc\UgcPlan;
use App\Services\Ugc\UgcShotPlanner;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Repair only re-directs. A repair that quietly edited the script, the
 * duration or the footage source would be a rewrite wearing a repair's
 * clothes — the user reviewed those, and they are not ours to change.
 */
class UgcReanchorTest extends TestCase
{
    private function planner(string $reply): UgcShotPlanner
    {
        $ai = new class($reply) implements AIGenerationAdapter
        {
            public int $calls = 0;

            public function __construct(private string $reply) {}

            public function generate(string $key, array $vars, int $max = 900, float $temp = 0.4, array $opts = []): array
            {
                $this->calls++;

                return ['content' => $this->reply];
            }
        };

        return new UgcShotPlanner($ai);
    }

    /** A plan whose cutaway proves a claim the script no longer makes. */
    private function stranded(): array
    {
        return UgcPlan::normalise([
            ['kind' => 'on_camera', 'script_text' => 'I used to spend hours on this.', 'seconds' => 5,
             'visual_brief' => 'Kitchen, morning light.', 'source' => null],
            ['kind' => 'b_roll', 'source' => 'upload', 'script_text' => 'Here it is running.', 'seconds' => 4,
             'visual_brief' => 'Screen recording of the timer.',
             'anchor' => 'It cut my editing time in half.', 'anchor_role' => 'prove'],
        ], 'demo');
    }

    public function test_a_plan_with_nothing_stale_never_calls_the_model(): void
    {
        $planner = $this->planner('{"shots":[]}');
        $fresh = UgcPlan::normalise([
            ['kind' => 'on_camera', 'script_text' => 'Just one line.', 'seconds' => 5, 'visual_brief' => 'b'],
        ], 'direct_camera');

        $out = $planner->reanchor($fresh, 'direct_camera');

        $this->assertSame(0, UgcPlan::staleCount($out));
    }

    public function test_a_stranded_shot_is_re_directed_and_stops_being_stale(): void
    {
        $planner = $this->planner(json_encode(['shots' => [[
            'index' => 1,
            'anchor' => 'Here it is running.',
            'visual_brief' => 'Screen recording of the app mid-render.',
            'headline' => 'Watch it go',
        ]]]));

        $out = $planner->reanchor($this->stranded(), 'demo');

        $this->assertSame('Here it is running.', $out[1]['anchor']);
        $this->assertSame('Screen recording of the app mid-render.', $out[1]['visual_brief']);
        $this->assertSame('Watch it go', $out[1]['headline']);
        $this->assertSame(0, UgcPlan::staleCount($out), 'a repair that leaves a shot stale has not repaired it');
    }

    public function test_the_role_the_director_chose_survives_the_repair(): void
    {
        $planner = $this->planner(json_encode(['shots' => [[
            'index' => 1, 'anchor' => 'Here it is running.', 'visual_brief' => 'New brief.',
        ]]]));

        $out = $planner->reanchor($this->stranded(), 'demo');

        $this->assertSame('prove', $out[1]['anchor_role']);
    }

    public function test_the_repair_cannot_edit_the_script_duration_or_source(): void
    {
        // A model that tries to take the whole shot over.
        $planner = $this->planner(json_encode(['shots' => [[
            'index' => 1, 'anchor' => 'Here it is running.', 'visual_brief' => 'New brief.',
            'script_text' => 'Completely different words.', 'seconds' => 55, 'source' => 'generate',
            'kind' => 'on_camera',
        ]]]));

        $before = $this->stranded();
        $out = $planner->reanchor($before, 'demo');

        $this->assertSame('Here it is running.', $out[1]['script_text']);
        $this->assertSame($before[1]['seconds'], $out[1]['seconds']);
        $this->assertSame('upload', $out[1]['source']);
        $this->assertSame('b_roll', $out[1]['kind']);
    }

    public function test_a_shot_that_was_never_stale_is_left_alone(): void
    {
        $planner = $this->planner(json_encode(['shots' => [[
            'index' => 0, 'visual_brief' => 'The model reaching for a shot nobody asked about.',
        ]]]));

        $out = $planner->reanchor($this->stranded(), 'demo');

        $this->assertSame('Kitchen, morning light.', $out[0]['visual_brief']);
    }

    public function test_an_unusable_reply_changes_nothing_and_says_so(): void
    {
        $planner = $this->planner('not json at all');

        $this->expectException(ValidationException::class);
        $planner->reanchor($this->stranded(), 'demo');
    }
}
