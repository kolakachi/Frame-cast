<?php

namespace Tests\Unit;

use App\Services\Media\MediaTranscriptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class TranscriptionFailureTest extends TestCase
{
    private function audio(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'tr').'.mp3';
        file_put_contents($f, 'not really audio');
        return $f;
    }

    public function test_a_failed_answer_is_asked_once_more_before_falling_back(): void
    {
        config(['services.openai.api_key' => 'k']);
        Sleep::fake();
        Http::fake(['api.openai.com/*' => Http::sequence()->push(['error' => ['message' => 'server error']], 500)->push(['text' => 'Hello there', 'words' => []], 200)]);
        $r = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($this->audio(), 'audio/mpeg');
        $this->assertSame(['openai', 'Hello there'], [$r['provider_key'], $r['transcript']]);
        Http::assertSentCount(2);
    }

    public function test_an_upload_without_an_extension_is_sent_with_one(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'php');
        exec('ffmpeg -v error -y -f lavfi -i sine=frequency=440:duration=1 -f wav '.escapeshellarg($f));
        $this->assertSame(basename($f).'.wav', MediaTranscriptionService::uploadName($f));
        $this->assertSame('voice.mp3', MediaTranscriptionService::uploadName('/tmp/voice.mp3'));
        config(['services.openai.api_key' => 'k']);
        Http::fake(['api.openai.com/*' => Http::response(['text' => 'tone', 'words' => []], 200)]);
        app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($f, 'audio/wav');
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'filename="'.basename($f).'.wav"'));
    }

    public function test_our_account_out_of_quota_is_not_retried_and_is_classified(): void
    {
        config(['services.openai.api_key' => 'k']);
        Sleep::fake();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'You exceeded your current quota, please check your plan and billing details.', 'type' => 'insufficient_quota']], 429)]);
        $r = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($this->audio(), 'audio/mpeg');
        $this->assertSame('local_fallback', $r['provider_key']);
        Http::assertSentCount(1);
        $this->assertSame('vendor_credit', \App\Services\Vendors\VendorAlerts::down('openai')['kind'] ?? null, 'new work needing it is held and the team alerted');
    }
}
