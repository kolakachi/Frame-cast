<?php
namespace Tests\Unit;

use App\Services\Generation\AI\{AIGenerationAdapter, ContentReview, OpenAIGenerationAdapter, PromptTemplateRegistry};
use App\Services\ApiUsageService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai.review_retry_delay_ms' => 0]);
    }

    public function test_only_explicit_well_formed_pass_can_authorize(): void
    {
        foreach (['{}', 'yes', '{"decision":"pass","issues":["Wrong topic"]}', '{"decision":"pass"}', '{"decision":"pass","issues":[]}'] as $text) {
            $ai = $this->createMock(AIGenerationAdapter::class);
            $ai->method('generate')->willReturn(['content' => $text]);
            $result = (new ContentReview)->review($ai, 'Source', 'Candidate', []);
            $this->assertSame($text === '{"decision":"pass","issues":[]}' ? 'pass' : 'unavailable', $result['decision']);
        }
    }

    public function test_placeholder_cannot_pass_even_if_it_looks_like_valid_json(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->method('generate')->willReturn(['content' => '{"decision":"pass","issues":[]}', 'provider_key' => 'local_fallback']);
        $this->assertSame('unavailable', (new ContentReview)->review($ai, 'Source', 'Candidate', [])['decision']);
    }

    public function test_openai_refusal_metadata_survives_empty_content(): void
    {
        config(['services.openai.api_key' => 'test']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => null, 'refusal' => 'Cannot comply.'], 'finish_reason' => 'stop']]], 200)]);
        $adapter = new OpenAIGenerationAdapter(new PromptTemplateRegistry, $this->createMock(ApiUsageService::class));
        $result = $adapter->generate('script_from_prompt', []);
        $this->assertSame('Cannot comply.', $result['refusal']);
        $this->assertTrue(ContentReview::refused($result));
        $this->assertSame('', $result['content']);
    }
    public function test_transient_failures_retry_the_same_payload_up_to_three_calls(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $payloads = [];
        $ai->expects($this->exactly(3))->method('generate')->willReturnCallback(function ($template, $variables) use (&$payloads) {
            $payloads[] = $variables;
            if (count($payloads) === 1) throw new \RuntimeException('timeout');
            return ['content' => count($payloads) === 2 ? 'bad json' : '{"decision":"pass","issues":[]}'];
        });
        $this->assertSame('pass', (new ContentReview)->review($ai, 'Source', 'Candidate', [])['decision']);
        $this->assertSame($payloads[0], $payloads[1]);
        $this->assertSame($payloads[0], $payloads[2]);
    }

    public function test_unavailability_is_bounded_and_logs_do_not_expose_content(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->exactly(3))->method('generate')->willThrowException(new \RuntimeException('PRIVATE SOURCE'));
        $result = (new ContentReview)->review($ai, 'PRIVATE SOURCE', 'PRIVATE DRAFT', ['stage' => 'script'], ['usage_context' => ['project_id' => 12]]);
        $this->assertSame('unavailable', $result['decision']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->times(3)->withArgs(function ($event, $fields) {
            return $event === 'generation.validation' && $fields['project_id'] === 12
                && $fields['reason_code'] === 'review_transport_error' && ! str_contains(json_encode($fields), 'PRIVATE');
        });
    }

    public function test_content_decisions_are_not_retried_and_are_logged(): void
    {
        foreach (['repair', 'clarify', 'unsupported'] as $decision) {
            $ai = $this->createMock(AIGenerationAdapter::class);
            $ai->expects($this->once())->method('generate')->willReturn(['content' => json_encode(['decision' => $decision, 'issues' => ['Mismatch']])]);
            $this->assertSame($decision, (new ContentReview)->review($ai, 'Source', 'Candidate', [])['decision']);
        }
    }

    public function test_expired_budget_does_not_call_provider(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->never())->method('generate');
        $this->assertSame('unavailable', (new ContentReview)->review($ai, 'Source', 'Candidate', [], ['deadline' => microtime(true) - 1])['decision']);
    }

    public function test_reviewer_refusal_is_unavailability_not_unsupported(): void
    {
        $ai = $this->createMock(AIGenerationAdapter::class);
        $ai->expects($this->once())->method('generate')->willReturn(['content' => '', 'refusal' => 'Declined']);
        $this->assertSame('unavailable', (new ContentReview)->review($ai, 'Source', 'Candidate', [])['decision']);
    }

}
