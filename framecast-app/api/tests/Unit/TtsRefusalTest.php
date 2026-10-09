<?php

namespace Tests\Unit;

use App\Services\Generation\TTS\{ChatterboxTTSAdapter, GeminiTTSAdapter, OpenAITTSAdapter, RoutingTTSAdapter, TtsRefused};
use Mockery;
use Tests\TestCase;

/** Gemini's safety filter refusing an ordinary line: retried once without the delivery direction, then a clear refusal. */
class TtsRefusalTest extends TestCase
{
    private function router(GeminiTTSAdapter $gemini): RoutingTTSAdapter
    {
        return new RoutingTTSAdapter($gemini, Mockery::mock(OpenAITTSAdapter::class), Mockery::mock(ChatterboxTTSAdapter::class));
    }

    public function test_a_refused_line_is_retried_without_its_direction_then_refused_clearly(): void
    {
        $flag = new \RuntimeException('gemini-tts failed: Prediction failed: ModelError: The input or output was flagged as sensitive. Please try again with different inputs. (E005)');
        $gemini = Mockery::mock(GeminiTTSAdapter::class);
        $gemini->shouldReceive('synthesize')->once()->withArgs(fn ($t, $l, $v, $s, $o) => $o['voice_prompt'] === 'leaning in')->andThrow($flag);
        $gemini->shouldReceive('synthesize')->once()->withArgs(fn ($t, $l, $v, $s, $o) => $o['voice_prompt'] === '')->andReturn(['audio_url' => 'x', 'provider_key' => 'replicate:gemini']);
        $this->assertSame('x', $this->router($gemini)->synthesize('Without ever showing your face?', 'en', 'Kore', 1.0, ['voice_prompt' => 'leaning in'])['audio_url']);

        $gemini = Mockery::mock(GeminiTTSAdapter::class);
        $gemini->shouldReceive('synthesize')->twice()->andThrow($flag);
        try {
            $this->router($gemini)->synthesize('Without ever showing your face?', 'en', 'Kore', 1.0, ['voice_prompt' => 'leaning in']);
            $this->fail('A refused line was not reported.');
        } catch (TtsRefused $e) {
            $this->assertStringContainsString('Without ever showing your face?', $e->getMessage());
        }

        // Any other failure passes through untouched, without a second call.
        $gemini = Mockery::mock(GeminiTTSAdapter::class);
        $gemini->shouldReceive('synthesize')->once()->andThrow(new \RuntimeException('timeout'));
        $this->expectExceptionMessage('timeout');
        $this->router($gemini)->synthesize('Hi', 'en', 'Kore', 1.0, ['voice_prompt' => 'warm']);
    }
}
