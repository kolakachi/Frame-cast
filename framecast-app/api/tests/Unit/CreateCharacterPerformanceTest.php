<?php
namespace Tests\Unit;

use App\Services\Create\{CharacterPerformance, CharacterApproval};
use Tests\TestCase;

class CreateCharacterPerformanceTest extends TestCase
{
    private function requirement(array $extra = []): array
    {
        return [...['id' => 'perf-test', 'kind' => 'facial', 'action' => 'Blink and smile', 'source_quote' => 'Blink and smile', 'start' => 0, 'end' => 5, 'route' => 'generated_video', 'tool' => 'animate_image'], ...$extra];
    }

    public function test_source_grounding_and_short_followups_preserve_performance(): void
    {
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Blink and smile']], 'settings' => ['duration_seconds' => 15]];
        $first = CharacterPerformance::normalize([$this->requirement(), $this->requirement(['source_quote' => 'invented instruction'])], $ctx);
        $this->assertCount(1, $first);
        $next = CharacterPerformance::normalize([], [...$ctx, 'previous_plan' => ['character_performance' => $first]]);
        $this->assertSame($first, $next);
        $invalid = CharacterPerformance::normalize([$this->requirement(['end' => 30])], $ctx);
        $this->assertNull($invalid[0]['end'], 'do not silently shorten a requested action');
        $separate = CharacterPerformance::normalize([$this->requirement(['action' => 'Blink']), $this->requirement(['action' => 'Smile'])], $ctx);
        $this->assertCount(2, $separate, 'one quoted sentence may contain several required actions');
    }

    public function test_pose_images_and_unprepared_rigs_do_not_satisfy_motion(): void
    {
        $media = [['kind' => 'character_poses'], ['kind' => 'character_variants']];
        $plan = ['character_performance' => [$this->requirement()]];
        $this->assertStringContainsString('still poses', CharacterPerformance::issues($plan, [], $media)[0]['message']);
        $plan['character_performance'][0]['route'] = 'prepared_rig';
        $this->assertStringContainsString('layered artwork', CharacterPerformance::issues($plan, [], $media)[0]['message']);
        $this->assertSame([], CharacterPerformance::issues([], [], $media), 'unrelated legacy/non-character plans are unchanged');
    }

    public function test_replaced_requirement_does_not_inherit_the_old_performance(): void
    {
        $id = 'req-'.str_repeat('a', 20);
        $old = $this->requirement(['requirement_ids' => [$id]]);
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Blink and smile. Frown instead.']],
            'previous_plan' => ['character_performance' => [$old], 'requirements' => [['id' => $id, 'version' => 1]]],
            '_requirement_contract' => ['requirements' => [['id' => $id, 'version' => 2, 'text' => 'Frown', 'source_quote' => 'Frown instead.']]]];
        $this->assertSame([], CharacterPerformance::normalize([], $ctx));
        $updated = CharacterPerformance::normalize([$this->requirement(['action' => 'Frown', 'source_quote' => 'Frown instead.', 'requirement_ids' => [$id]])], $ctx);
        $this->assertCount(1, $updated);
        $this->assertSame('Frown', $updated[0]['action']);
    }

    public function test_motion_needs_correct_subject_tool_duration_and_script(): void
    {
        $plan = ['character_performance' => [$this->requirement()]];
        $media = [['kind' => 'character_poses'], ['kind' => 'animate_image', 'subject' => 'source']];
        $this->assertNotEmpty(CharacterPerformance::issues($plan, [], $media));
        $media[1]['subject'] = 'approved_character';
        $this->assertSame([], CharacterPerformance::issues($plan, [], $media));
        $plan['character_performance'][0]['end'] = 10;
        $this->assertStringContainsString('5 seconds', CharacterPerformance::issues($plan, [], $media)[0]['message']);
        $plan['character_performance'] = [$this->requirement(['kind' => 'speech'])];
        $this->assertStringContainsString('talking-video', CharacterPerformance::issues($plan, [], $media)[0]['message']);
        $plan['character_performance'] = [$this->requirement(['kind' => 'body', 'tool' => 'talking_take'])];
        $this->assertStringContainsString('walking', CharacterPerformance::issues($plan, [], [['kind' => 'talking_take']])[0]['message']);
        $plan['character_performance'] = [$this->requirement(['kind' => 'speech', 'tool' => 'talking_take'])];
        $this->assertStringContainsString('script', CharacterPerformance::issues($plan, [], [['kind' => 'talking_take']])[0]['message']);
    }

    public function test_character_motion_cache_identity_includes_approved_master(): void
    {
        $ctx = ['narration' => [], 'voice' => null, 'aspect_ratio' => '16:9', 'character_style' => 'halftone'];
        $item = ['kind' => 'animate_image', 'description' => 'Blink', 'subject' => 'approved_character', 'master_sha256' => str_repeat('a', 64)];
        $this->assertNotSame(CharacterApproval::mediaHash($item, $ctx), CharacterApproval::mediaHash([...$item, 'master_sha256' => str_repeat('b', 64)], $ctx));
        $this->assertNotSame(CharacterApproval::mediaHash($item, $ctx), CharacterApproval::mediaHash([...$item, 'subject' => 'source'], $ctx));
    }

    public function test_saved_review_keeps_bounded_character_evidence_without_upgrading_unverified_checks(): void
    {
        $review = \App\Services\Create\RunService::creativeReview(['status' => 'passed', 'performance_checks' => [
            ['id' => 'perf-blink', 'status' => 'unverified', 'evidence' => str_repeat('x', 500), 'private' => 'discard'],
            ['id' => 'not-a-requirement', 'status' => 'pass', 'evidence' => 'discard'],
        ]]);
        $this->assertSame('issues', $review['status'], 'unverified evidence is something to check, never a pass');
        $this->assertCount(1, $review['performance_checks']);
        $this->assertSame(['id', 'status', 'evidence', 'source'], array_keys($review['performance_checks'][0]));
        $this->assertSame(400, strlen($review['performance_checks'][0]['evidence']));
    }
}
