<?php

namespace Tests\Unit;

use App\Services\Create\Planning\PlanPrompt;
use Tests\TestCase;

class PlanTeachingTest extends TestCase
{
    private function scene(string $label, float $a, float $b, array $reads, ?string $arc = null): array
    {
        return ['label' => $label, 'start' => $a, 'end' => $b, 'reads' => $reads, 'arc' => $arc];
    }

    public function test_two_beats_with_the_same_job_are_sent_back(): void
    {
        $plan = ['scenes' => [$this->scene('One', 0, 5, ['Paste a link']), $this->scene('Two', 5, 10, ['Paste a link']), $this->scene('Three', 10, 15, ['Get a video'])]];
        $p = PlanPrompt::problems($plan, ['settings' => ['duration_seconds' => 15]]);
        $this->assertCount(1, array_filter($p, fn ($x) => str_contains($x, 'same reads')));
    }

    public function test_a_teaching_video_runs_from_its_hook_to_its_recap(): void
    {
        $ctx = ['settings' => ['duration_seconds' => 15]];
        $good = ['creative_intent' => ['format' => 'educational'], 'scenes' => [
            $this->scene('Q', 0, 4, ['Why do some videos stop the scroll?'], 'hook'), $this->scene('How', 4, 9, ['The first second shows motion'], 'mechanism'),
            $this->scene('Name', 9, 12, ['That is the hook'], 'discovery'), $this->scene('Back', 12, 15, ['Your video now opens on motion'], 'recap')]];
        $this->assertSame([], array_values(array_filter(PlanPrompt::problems($good, $ctx), fn ($x) => str_contains($x, 'teaching'))));
        $bad = $good; $bad['scenes'][3]['arc'] = 'consequence';
        $this->assertCount(1, array_filter(PlanPrompt::problems($bad, $ctx), fn ($x) => str_contains($x, 'teaching video')));
        $ad = $bad; $ad['creative_intent']['format'] = 'motion_graphics';
        $this->assertSame([], array_values(array_filter(PlanPrompt::problems($ad, $ctx), fn ($x) => str_contains($x, 'teaching'))), 'ads keep their own structure');
    }

    public function test_too_many_requirements_go_back_to_the_planner_to_merge_not_to_the_user(): void
    {
        $req = fn ($n) => array_map(fn ($k) => ['id' => 'r'.$k, 'text' => 'Ask '.$k, 'source_quote' => 'ask '.$k], range(1, $n));
        $base = ['scenes' => [['label' => 'A', 'start' => 0, 'end' => 15, 'reads' => ['x']]]];
        $ctx = ['settings' => ['duration_seconds' => 15]];
        $this->assertSame([], array_values(array_filter(PlanPrompt::problems($base + ['requirements' => $req(30)], $ctx), fn ($p) => str_contains($p, 'requirements'))));
        $this->assertCount(1, array_filter(PlanPrompt::problems($base + ['requirements' => $req(40)], $ctx), fn ($p) => str_contains($p, 'Merge related')));
    }
}
