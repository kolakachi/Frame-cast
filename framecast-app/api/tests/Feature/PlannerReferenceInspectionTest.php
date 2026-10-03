<?php
namespace Tests\Feature;

use App\Services\Create\{InputSnapshotService, PlanService, ConversationService};
use App\Services\Create\Planning\{AnthropicPlanner, PlannerReferenceInspector};
use Illuminate\Support\Facades\{Http, Storage, Log};
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlannerReferenceInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'inspection_test', 'database.connections.inspection_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Http::preventStrayRequests();
    }
    private function context(): array
    {
        return ['_workspace_id' => 7, 'files' => [['asset_id' => 21, 'purpose' => 'reference', 'asset_type' => 'video', 'title' => 'Reference']],
            'settings' => ['duration_seconds' => 15], 'messages' => [['role' => 'user', 'content' => 'Preserve the blink from my reference.']]];
    }
    private function reply(array $content, string $id): array
    {
        return ['id' => $id, 'content' => $content, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20,
            'cache_read_input_tokens' => 10, 'cache_creation_input_tokens' => 5]];
    }
    private function tool(string $id = 'tool1', int $page = 1): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => 'inspect_reference',
            'input' => ['asset_id' => 21, 'params' => ['mode' => 'sequence', 'start' => 0, 'end' => 1, 'every_frame' => true, 'page' => $page]]];
    }
    private function evidence(): array
    {
        return ['id' => 'ref-proof', 'asset_id' => 21, 'source_sha256' => str_repeat('a', 64), 'coverage' => ['page' => 1, 'pages' => 4, 'frames_shown' => 8]];
    }
    public function test_planner_sees_inspection_before_plan_and_records_all_calls(): void
    {
        Http::preventStrayRequests();
        $inspector = \Mockery::mock(PlannerReferenceInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn(['evidence' => $this->evidence(), 'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'YQ==']]]);
        $inspector->shouldReceive('close')->once(); $this->app->instance(PlannerReferenceInspector::class, $inspector);
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->reply([$this->tool()], 'msg1'))
            ->push($this->reply([['type' => 'text', 'text' => '{"summary":"A blink plan","scenes":[]}']], 'msg2'))]);
        $result = (new AnthropicPlanner('test', 'fake'))->plan($this->context());
        $this->assertSame([$this->evidence()], $result['reference_evidence']);
        $this->assertSame(2, $result['usage']['call_count']);
        $this->assertSame(200, $result['usage']['input_tokens']);
        $this->assertSame(40, $result['usage']['output_tokens']);
        $this->assertSame(20, $result['usage']['cache_read_tokens']);
        $this->assertSame('inspected', $result['usage']['inspection_attempts'][0]['status']);
        $requests = Http::recorded();
        $this->assertSame('any', $requests[0][0]['tool_choice']['type']);
        $second = $requests[1][0]['messages'];
        $this->assertSame('tool1', $second[2]['content'][0]['tool_use_id']);
        $this->assertStringContainsString('ref-proof', $second[2]['content'][0]['content'][0]['text']);
        $this->assertSame('image', $second[2]['content'][0]['content'][1]['type']);
        $this->assertStringNotContainsString('_workspace_id', json_encode($second));
    }
    public function test_requests_are_bounded_and_unavailable_inspection_is_explicit(): void
    {
        Http::preventStrayRequests();
        $inspector = \Mockery::mock(PlannerReferenceInspector::class);
        $inspector->shouldReceive('inspect')->times(4)->andThrow(new \RuntimeException('private storage path must not leak'));
        $inspector->shouldReceive('close')->once(); $this->app->instance(PlannerReferenceInspector::class, $inspector);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->reply([$this->tool('a'), $this->tool('b')], 'msg1'))
            ->push($this->reply([$this->tool('c'), $this->tool('d'), $this->tool('e')], 'msg2'))
            ->push($this->reply([['type' => 'text', 'text' => '{"summary":"Inspection unavailable"}']], 'msg3'))]);
        $result = (new AnthropicPlanner('test', 'fake'))->plan($this->context());
        $this->assertCount(3, Http::recorded());
        $this->assertSame([], $result['reference_evidence']);
        $this->assertSame('inspection_limit', $result['usage']['inspection_attempts'][4]['reason']);
        $last = Http::recorded()[2][0];
        $this->assertSame('none', $last['tool_choice']['type']);
        $this->assertStringNotContainsString('private storage path', json_encode($last->data()));
        $this->assertStringContainsString('"is_error":true', json_encode($last['messages']));
    }
    public function test_failed_later_call_keeps_receipts_and_cleans_up_without_retry(): void
    {
        Http::preventStrayRequests();
        $inspector = \Mockery::mock(PlannerReferenceInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn(['evidence' => $this->evidence(), 'image' => ['type' => 'image']]);
        $inspector->shouldReceive('close')->once(); $this->app->instance(PlannerReferenceInspector::class, $inspector);
        Log::shouldReceive('warning')->once()->with('create.planner.failed', \Mockery::on(fn ($x) => $x['calls'][0]['message_id'] === 'msg1' && $x['possible_unreceipted_call']));
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->reply([$this->tool()], 'msg1'))->push([], 503)]);
        try { (new AnthropicPlanner('test', 'fake'))->plan($this->context()); $this->fail('Must fail'); }
        catch (\RuntimeException $e) { $this->assertSame('Planner request failed.', $e->getMessage()); }
        $this->assertCount(2, Http::recorded());
    }
    public function test_untrusted_evidence_cannot_survive_normalization_or_changed_source_quote(): void
    {
        $ctx = $this->context(); $ctx['_reference_evidence'] = [$this->evidence()];
        $raw = ['summary' => 'Blink', 'reference_evidence' => [['id' => 'invented']],
            'reference_observations' => [['asset_id' => 21, 'observed' => 'Eyelids close', 'preserve' => 'Blink', 'evidence_ids' => ['ref-proof', 'invented']]],
            'requirements' => [['text' => 'Preserve the blink', 'source_quote' => 'Preserve the blink', 'evidence_ids' => ['ref-proof', 'invented']]]];
        $plan = (new PlanService(app(ConversationService::class)))->normalize($raw, $ctx, 7);
        $this->assertSame([$this->evidence()], $plan['reference_evidence']);
        $this->assertSame(['ref-proof'], $plan['reference_observations'][0]['evidence_ids']);
        $this->assertSame(['ref-proof'], $plan['requirements'][0]['evidence_ids']);
        $quote = PlanService::quotePlan($plan, 'plan-id');
        $this->assertSame($plan['reference_evidence'], $quote['reference_evidence']);
        PlannerReferenceInspector::verifyEvidence($quote, [['asset_id' => 21, 'purpose' => 'reference', 'sha256' => str_repeat('a', 64)]]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        PlannerReferenceInspector::verifyEvidence($quote, [['asset_id' => 21, 'purpose' => 'reference', 'sha256' => str_repeat('b', 64)]]);
    }
    public function test_inspector_rejects_unlisted_and_source_assets_before_reading_storage(): void
    {
        $snapshot = \Mockery::mock(InputSnapshotService::class); $snapshot->shouldNotReceive('capture');
        $this->app->instance(InputSnapshotService::class, $snapshot);
        $inspector = new PlannerReferenceInspector();
        foreach ([22, '../21', 21] as $id) {
            $ctx = $this->context(); if ($id === 21) $ctx['files'][0]['purpose'] = 'source';
            try { $inspector->inspect($ctx, ['asset_id' => $id, 'params' => ['mode' => 'frames', 'times' => [0]]]); $this->fail('Must reject'); }
            catch (\RuntimeException $e) { $this->assertSame('Unknown reference attachment.', $e->getMessage()); }
        }
        $inspector->close();
    }
    public function test_real_host_inspection_pages_are_cached_and_temporary_media_is_removed(): void
    {
        Storage::fake('local'); $disk = Storage::disk('local');
        $disk->makeDirectory('fixtures'); $path = $disk->path('fixtures/ref.mp4');
        (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=160x90:rate=12', '-t', '2', '-pix_fmt', 'yuv420p', $path]))->setTimeout(20)->mustRun();
        $snapshot = \Mockery::mock(InputSnapshotService::class);
        $snapshot->shouldReceive('capture')->once()->with(7, \Mockery::on(fn ($a) => $a[0]->asset_id === 21 && $a[0]->purpose === 'reference'))
            ->andReturn([['asset_id' => 21, 'purpose' => 'reference', 'name' => 'ref.mp4', 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path), 'mime_type' => 'video/mp4', 'asset_type' => 'video', 'storage_path' => 'fixtures/ref.mp4']]);
        $snapshot->shouldReceive('discard')->once(); $this->app->instance(InputSnapshotService::class, $snapshot);
        $inspector = new PlannerReferenceInspector();
        try {
            $one = $inspector->inspect($this->context(), $this->tool()['input']);
            $two = $inspector->inspect($this->context(), $this->tool('tool2', 2)['input']);
            $this->assertSame(12, $one['evidence']['coverage']['decoded_frames']);
            $this->assertFalse($one['evidence']['cache']['hit']); $this->assertTrue($two['evidence']['cache']['hit']);
            $this->assertSame(2, $two['evidence']['coverage']['page']);
            $this->assertSame(hash_file('sha256', $path), $two['evidence']['source_sha256']);
            $this->assertNotSame($one['evidence']['id'], $two['evidence']['id']);
            $this->assertNotEmpty(base64_decode($two['image']['source']['data']));
            $this->assertStringNotContainsString($path, json_encode($two['evidence']));
        } finally { $inspector->close(); }
        $this->assertSame([], $disk->allFiles('create/planner-inspections'));
    }
    public function test_malformed_plan_retry_keeps_prior_inspection_and_unknown_usage_is_not_zero(): void
    {
        $inspector = \Mockery::mock(PlannerReferenceInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn(['evidence' => $this->evidence(), 'image' => ['type' => 'image']]);
        $inspector->shouldReceive('close')->once(); $this->app->instance(PlannerReferenceInspector::class, $inspector);
        $unknown = $this->reply([['type' => 'text', 'text' => 'truncated']], 'msg2');
        $unknown['usage'] = [];
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->reply([$this->tool()], 'msg1'))->push($unknown)
            ->push($this->reply([['type' => 'text', 'text' => '{"summary":"Recovered plan"}']], 'msg3'))]);
        $result = (new AnthropicPlanner('test', 'fake'))->plan($this->context());
        $this->assertSame(3, $result['usage']['call_count']);
        $this->assertNull($result['usage']['input_tokens']);
        $last = Http::recorded()[2][0];
        $this->assertSame('medium', $last['output_config']['effort']);
        $this->assertSame('none', $last['tool_choice']['type']);
        $this->assertStringContainsString('ref-proof', json_encode($last['messages']));
    }
    public function test_expired_planning_deadline_does_not_start_a_provider_call(): void
    {
        Log::shouldReceive('warning')->once()->with('create.planner.failed', \Mockery::on(fn ($x) => $x['calls'] === [] && ! $x['possible_unreceipted_call']));
        try { (new AnthropicPlanner('test', 'fake'))->plan($this->context() + ['_planner_deadline' => microtime(true) - 1]); $this->fail('Must stop'); }
        catch (\RuntimeException $e) { $this->assertSame('Planner time budget exhausted.', $e->getMessage()); }
        Http::assertNothingSent();
    }
    public function test_api_inspector_bundle_matches_worker_implementation(): void
    {
        foreach (['reference-inspection.mjs', 'reference-sequence.mjs'] as $file) {
            $this->assertSame(hash_file('sha256', base_path('../hyperframes-worker/agent/'.$file)), hash_file('sha256', resource_path('create-reference-inspection/'.$file)));
        }
    }
}
