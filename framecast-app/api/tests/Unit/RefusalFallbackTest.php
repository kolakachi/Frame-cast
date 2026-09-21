<?php

namespace Tests\Unit;

use App\Services\Generation\AI\OpenAIGenerationAdapter;
use App\Services\Generation\AI\ReplicateClaudeAdapter;
use App\Services\Generation\AI\RoutingTextAdapter;
use Tests\TestCase;

class RefusalFallbackTest extends TestCase
{
    private const REFUSAL = "I can't write this one — it reads as personalized roleplay content.";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.replicate.api_token' => 'test-token']);
        config(['services.ai.premium_templates' => ['script_from_prompt']]);
    }

    private function route(string $premiumSays, string $cheapSays): array
    {
        $premium = $this->createMock(ReplicateClaudeAdapter::class);
        $premium->method('generate')->willReturn(['content' => $premiumSays]);

        $cheap = $this->createMock(OpenAIGenerationAdapter::class);
        $cheap->expects($this->never())->method('generate')->willReturn(['content' => $cheapSays]);

        return (new RoutingTextAdapter($cheap, $premium))->generate('script_from_prompt', []);
    }

    public function test_a_decline_is_returned_without_trying_another_model(): void
    {
        $result = $this->route(self::REFUSAL, 'Three things likable people never do.');

        $this->assertSame(self::REFUSAL, $result['content']);
    }

    public function test_two_declines_give_up_rather_than_shopping_for_a_yes(): void
    {
        // The refusal comes back unchanged so GenerateScriptJob's guard can
        // fail the project. There is no third attempt.
        $result = $this->route(self::REFUSAL, "I'm not comfortable producing this either.");

        $this->assertTrue(\App\Support\ScriptText::looksLikeRefusal($result['content']));
    }

    public function test_a_good_premium_answer_is_never_second_guessed(): void
    {
        $result = $this->route('Here is a genuinely good script.', 'The cheap one.');

        $this->assertSame('Here is a genuinely good script.', $result['content']);
    }

    public function test_structured_json_templates_are_untouched(): void
    {
        // Scene plans come back as JSON; the anchored pattern must never treat
        // one as a decline and silently reroute it to the cheap tier.
        $json = json_encode(['segments' => [['line' => "I can't write this off as luck."]]]);

        $this->assertSame($json, $this->route($json, '{}')['content']);
    }
    public function test_transport_failure_can_use_the_fallback(): void
    {
        $premium = $this->createMock(ReplicateClaudeAdapter::class);
        $premium->method('generate')->willThrowException(new \RuntimeException('timeout'));
        $cheap = $this->createMock(OpenAIGenerationAdapter::class);
        $cheap->expects($this->once())->method('generate')->willReturn(['content' => 'A supported script.']);
        $result = (new RoutingTextAdapter($cheap, $premium))->generate('script_from_prompt', []);
        $this->assertSame('A supported script.', $result['content']);
    }

}
