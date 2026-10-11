<?php

namespace Tests\Unit;

use App\Services\ApiUsageService;
use App\Services\Usage\UsageContext;
use App\Services\Usage\VendorUsageRecorder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Real vendor cost recorded for every paid call (owner, 2026-10-11). */
class VendorUsageRecorderTest extends TestCase
{
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $usage = Mockery::mock(ApiUsageService::class);
        $usage->shouldReceive('record')->andReturnUsing(function (array $d) { $this->rows[] = $d; return null; });
        $this->app->instance(ApiUsageService::class, $usage);
    }

    private function replicate(array $prediction): void
    {
        VendorUsageRecorder::observe(new Request('GET', 'https://api.replicate.com/v1/predictions/'.$prediction['id']),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($prediction)));
    }

    #[Test]
    public function a_finished_video_is_priced_from_its_measured_seconds_once_with_the_workspace(): void
    {
        UsageContext::set(28, 280);
        $p = ['id' => 'p1', 'model' => 'google/gemini-omni-1.1', 'status' => 'succeeded', 'input' => [], 'metrics' => ['video_output_duration_seconds' => 10.0, 'predict_time' => 80]];
        $this->replicate(['status' => 'processing'] + $p);
        $this->replicate($p);
        $this->replicate($p);
        $this->assertCount(1, $this->rows, 'polled three times, recorded once, only when finished');
        $this->assertSame([28, 280, 'video', 1.5, 10], [$this->rows[0]['workspace_id'], $this->rows[0]['project_id'], $this->rows[0]['service'], $this->rows[0]['estimated_cost_usd'], $this->rows[0]['units']]);
    }

    #[Test]
    public function prices_follow_the_model_and_its_inputs(): void
    {
        $this->assertSame(0.6936, VendorUsageRecorder::replicateUsd('bytedance/seedance-2.5', ['input' => ['resolution' => '720p'], 'metrics' => ['video_output_duration_seconds' => 3]])[0]);
        $this->assertSame(0.4, VendorUsageRecorder::replicateUsd('google/veo-3.1-fast', ['input' => ['duration' => 4, 'generate_audio' => false], 'metrics' => []])[0], 'no sound is cheaper');
        $this->assertSame(0.45, VendorUsageRecorder::replicateUsd('kwaivgi/kling-v2.1', ['input' => ['mode' => 'pro', 'duration' => 5], 'metrics' => []])[0]);
        $this->assertSame(0.078, VendorUsageRecorder::replicateUsd('google/nano-banana', ['output' => ['a', 'b'], 'metrics' => []])[0]);
        $this->assertSame(0.33, VendorUsageRecorder::replicateUsd('minimax/hailuo-2.3-fast', ['input' => ['resolution' => '1080p'], 'metrics' => []])[0]);
        $this->assertSame([0.0145, 20.0, 'predict_time_estimate'], VendorUsageRecorder::replicateUsd('luma/modify-video', ['metrics' => ['predict_time' => 20]]));
    }

    #[Test]
    public function a_failed_prediction_and_self_recorded_models_cost_nothing_here(): void
    {
        $this->replicate(['id' => 'p2', 'model' => 'google/veo-3.1', 'status' => 'failed', 'error' => 'E006 flagged', 'metrics' => []]);
        $this->replicate(['id' => 'p3', 'model' => 'anthropic/claude-sonnet-5', 'status' => 'succeeded', 'metrics' => []]);
        $this->assertCount(1, $this->rows);
        $this->assertSame(['failed', 0.0], [$this->rows[0]['status'], $this->rows[0]['estimated_cost_usd']]);
    }

    #[Test]
    public function a_claude_reply_is_priced_from_its_tokens(): void
    {
        UsageContext::set(5);
        VendorUsageRecorder::observe(new Request('POST', 'https://api.anthropic.com/v1/messages'), new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['id' => 'msg_1', 'model' => 'claude-haiku-4-5-20251001', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 10000]])));
        $this->assertCount(1, $this->rows);
        // Haiku 4.5: $1/M in, $5/M out, $0.10/M cache read.
        $this->assertSame([5, 'anthropic', 0.003, 11000, 200], [$this->rows[0]['workspace_id'], $this->rows[0]['provider'], $this->rows[0]['estimated_cost_usd'], $this->rows[0]['prompt_tokens'], $this->rows[0]['completion_tokens']]);
        $this->assertSame(0.5, VendorUsageRecorder::claudeRates('claude-haiku-5-5', 200000)['input'], 'long prompts on Haiku 5.5 cost more');
    }

    #[Test]
    public function other_hosts_and_downloads_are_ignored(): void
    {
        VendorUsageRecorder::observe(new Request('GET', 'https://replicate.delivery/x/out.mp4'), new Response(200, ['Content-Type' => 'video/mp4'], 'bytes'));
        VendorUsageRecorder::observe(new Request('POST', 'https://api.openai.com/v1/chat/completions'), new Response(200, ['Content-Type' => 'application/json'], '{"usage":{}}'));
        $this->assertSame([], $this->rows);
    }
}
