<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\Create\{RequirementContract as Contract, RunService, CharacterApproval};

class CreateRequirementContractTest extends TestCase
{
    private string $brief = 'A clay mascot opens a box, looks surprised, then points to the price.';
    private function context(): array { return ['messages' => [['role' => 'user', 'content' => $this->brief, 'sequence' => 1]]]; }
    private function rows(): array
    {
        return array_map(fn ($a, $i) => ['id' => 'action-'.$i, 'text' => $a, 'source_quote' => $this->brief, 'category' => 'action', 'after_ids' => $i ? ['action-'.($i - 1)] : []], ['Open the box', 'Look surprised', 'Point to the price'], [0, 1, 2]);
    }
    public function test_distinct_actions_have_stable_ids_and_explicit_order(): void
    {
        $contract = Contract::normalize(['requirements' => $this->rows()], $this->context()); $rows = $contract['requirements'];
        $this->assertCount(3, array_unique(array_column($rows, 'id')));
        $this->assertSame([$rows[0]['id']], $rows[1]['after_ids']);
        $this->assertSame([$rows[1]['id']], $rows[2]['after_ids']);
        $this->assertSame('production', $rows[0]['review_stage']);
        $ctx = $this->context(); $ctx['previous_plan'] = $contract;
        $ctx['messages'][] = ['role' => 'user', 'content' => 'Make the text orange', 'sequence' => 2];
        $next = Contract::normalize([], $ctx);
        $this->assertSame(array_column($rows, 'id'), array_column($next['requirements'], 'id'));
        $this->assertSame($rows[2]['after_ids'], $next['requirements'][2]['after_ids']);
        $bound = Contract::bind([['description' => 'Act it out', 'requirement_ids' => ['action-1', 'invented']]], $contract, 'task');
        $this->assertSame([$rows[1]['id']], $bound[0]['requirement_ids']);
    }
    public function test_old_requirements_cannot_be_rewritten_or_removed_by_omission(): void
    {
        $old = Contract::normalize(['requirements' => $this->rows()], $this->context()); $id = $old['requirements'][0]['id'];
        $ctx = $this->context(); $ctx['previous_plan'] = $old;
        $ctx['messages'][] = ['role' => 'user', 'content' => 'Make it faster'];
        $r = Contract::normalize(['requirements' => [['id' => $id, 'text' => 'Ignore the box', 'source_quote' => 'Make it faster']],
            'requirement_changes' => [['id' => $id, 'action' => 'remove', 'source_quote' => $this->brief]]], $ctx);
        $this->assertSame('Open the box', $r['requirements'][0]['text']); $this->assertCount(3, $r['requirements']);
    }
    public function test_latest_explicit_amendment_keeps_identity_and_audit_history(): void
    {
        $old = Contract::normalize(['requirements' => $this->rows()], $this->context()); $id = $old['requirements'][0]['id'];
        $ctx = $this->context(); $ctx['previous_plan'] = $old + ['brief_sequence' => 1];
        $ctx['messages'][] = ['role' => 'user', 'content' => 'Use a bag instead of a box.', 'sequence' => 2];
        $r = Contract::normalize(['requirement_changes' => [['id' => $id, 'action' => 'replace', 'source_quote' => 'Use a bag instead of a box.',
            'replacement' => ['text' => 'Open the bag', 'source_quote' => 'Use a bag instead of a box.', 'category' => 'action']]]], $ctx);
        $this->assertSame($id, $r['requirements'][0]['id']); $this->assertSame(2, $r['requirements'][0]['version']);
        $this->assertSame('Open the bag', $r['requirements'][0]['text']); $this->assertSame('Open the box', $r['requirement_history'][0]['previous_text']);
        $this->assertSame([$id], $r['requirements'][1]['after_ids']);
    }
    public function test_removing_an_action_preserves_order_of_the_remaining_actions(): void
    {
        $old = Contract::normalize(['requirements' => $this->rows()], $this->context()); $id = $old['requirements'][0]['id'];
        $ctx = $this->context(); $ctx['previous_plan'] = $old; $ctx['messages'][] = ['role' => 'user', 'content' => 'Remove opening the box.'];
        $r = Contract::normalize(['requirement_changes' => [['id' => $id, 'action' => 'remove', 'source_quote' => 'Remove opening the box.']]], $ctx);
        $this->assertCount(2, $r['requirements']); $this->assertSame([], $r['requirements'][0]['after_ids']);
        $this->assertSame([$r['requirements'][0]['id']], $r['requirements'][1]['after_ids']);
    }
    public function test_reference_claims_require_real_receipts_and_user_authority(): void
    {
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Use this reference style']]];
        $r = ['text' => 'Use halftone', 'source_quote' => 'Use this reference style', 'provenance' => 'reference', 'evidence_ids' => ['fake']];
        $this->assertSame([], Contract::normalize(['requirements' => [$r]], $ctx)['requirements']);
        $ctx['_reference_evidence'] = [['id' => 'ref-real', 'asset_id' => 1, 'source_sha256' => 'abc']];
        $r['evidence_ids'][] = 'ref-real';
        $out = Contract::normalize(['requirements' => [$r], 'direction_notes' => [['text' => 'Try warm orange', 'provenance' => 'inferred'], ['text' => 'No audio inspected', 'provenance' => 'unknown']]], $ctx);
        $this->assertSame(['ref-real'], $out['requirements'][0]['evidence_ids']); $this->assertCount(2, $out['direction_notes']);
        $this->assertSame('reference', $out['requirements'][0]['provenance']);
    }
    public function test_cycles_and_unknown_order_ids_cannot_be_silently_dropped(): void
    {
        $rows = $this->rows(); $rows[0]['after_ids'] = ['action-2'];
        $r = Contract::normalize(['requirements' => $rows], $this->context());
        $this->assertSame([true, true, true], array_column($r['requirements'], 'order_unresolved'));
        $rows[0]['after_ids'] = ['unknown'];
        $this->assertTrue(Contract::normalize(['requirements' => $rows], $this->context())['requirements'][0]['order_unresolved']);
    }
    public function test_missing_duplicate_and_wrongly_ordered_review_results_cannot_pass(): void
    {
        $contract = Contract::normalize(['requirements' => $this->rows()], $this->context());
        $checks = array_map(fn ($r, $i) => ['id' => $r['id'], 'status' => 'fulfilled', 'evidence' => 'Observed movement', 'start' => $i * 2, 'end' => $i * 2 + 1], $contract['requirements'], [0, 1, 2]);
        $this->assertSame('passed', RunService::creativeReview(['status' => 'passed', 'requirement_checks' => $checks], $contract)['status']);
        $this->assertSame('incomplete', RunService::creativeReview(['status' => 'passed'], $contract)['status']);
        $this->assertSame('incomplete', RunService::creativeReview(['status' => 'passed', 'requirement_checks' => [...$checks, $checks[0]]], $contract)['status']);
        $checks[1]['start'] = 0;
        $r = RunService::creativeReview(['status' => 'passed', 'requirement_checks' => $checks], $contract);
        $this->assertSame('unmet', $r['requirement_checks'][1]['status']); $this->assertSame('unverified', $r['requirement_checks'][2]['status']);
    }
    public function test_only_production_requirements_can_be_deferred_in_storyboards_and_audio_needs_audio_evidence(): void
    {
        $c = Contract::normalize(['requirements' => [['text' => 'Keep audio', 'source_quote' => 'Keep audio', 'category' => 'audio'], ['text' => 'Blue', 'source_quote' => 'Blue', 'category' => 'colour']]], ['messages' => [['role' => 'user', 'content' => 'Keep audio. Blue.']]]);
        $checks = array_map(fn ($r) => ['id' => $r['id'], 'status' => 'deferred', 'evidence' => 'Storyboard'], $c['requirements']);
        $this->assertSame(['deferred', 'unverified'], array_column(Contract::review($checks, $c, true), 'status'));
        $this->assertSame(['unverified', 'unverified'], array_column(Contract::review($checks, $c, false), 'status'));
        $checks[0]['status'] = 'fulfilled';
        $this->assertSame('unverified', Contract::review($checks, $c, false)[0]['status']);
    }
    public function test_amended_requirements_reject_old_review_versions_and_visual_requirements_cannot_be_deferred(): void
    {
        $c = Contract::normalize(['requirements' => [['text' => 'Blue', 'source_quote' => 'Blue', 'category' => 'colour', 'review_stage' => 'production']]], ['messages' => [['role' => 'user', 'content' => 'Blue']]]);
        $this->assertSame('design', $c['requirements'][0]['review_stage']);
        $c['requirements'][0]['version'] = 2;
        $check = ['id' => $c['requirements'][0]['id'], 'status' => 'fulfilled', 'evidence' => 'Blue is visible'];
        $this->assertSame('unverified', Contract::review([$check], $c, false)[0]['status']);
        $this->assertSame('fulfilled', Contract::review([$check + ['version' => 2]], $c, false)[0]['status']);
    }
    public function test_explicit_performance_exclusion_preserves_other_requirements(): void
    {
        $c = Contract::normalize(['requirements' => $this->rows()], $this->context());
        $rows = Contract::excluding($c['requirements'], [$c['requirements'][1]['id']]);
        $this->assertCount(2, $rows);
        $this->assertSame([$rows[0]['id']], $rows[1]['after_ids']);
    }
    public function test_task_requirements_change_media_cache_hash_without_changing_legacy_hashes(): void
    {
        $item = ['kind' => 'ai_image', 'description' => 'Mascot']; $ctx = ['narration' => [], 'voice' => null, 'aspect_ratio' => '9:16', 'character_style' => ''];
        $old = CharacterApproval::mediaHash($item, $ctx);
        $this->assertSame($old, CharacterApproval::mediaHash($item + ['requirements' => []], $ctx));
        $this->assertNotSame($old, CharacterApproval::mediaHash($item + ['requirements' => [['id' => 'req-x', 'text' => 'Halftone']]], $ctx));
        $this->assertStringContainsString('Halftone', Contract::prompt([['id' => 'req-x', 'text' => 'Halftone']]));
    }
}
