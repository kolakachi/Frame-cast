<?php

namespace Tests\Feature;

use App\Services\Create\PlanMediaExecutor;
use App\Services\Generation\ProviderFailed;
use App\Services\Vendors\VendorError;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A provider that answers "no" or "failed" is a known outcome: nothing made, nothing charged, no credits held (2026-10-10). */
class ProviderFailureTest extends TestCase
{
    private function replicate(int $seconds = 240): string
    {
        $m = (new \ReflectionClass(PlanMediaExecutor::class))->getMethod('replicate');
        $m->setAccessible(true);
        return $m->invoke(app(PlanMediaExecutor::class), 'elevenlabs/music', ['prompt' => 'calm'], $seconds);
    }

    public function test_replicate_refusals_and_failed_jobs_are_known_failures_and_our_account_problems_are_recognised(): void
    {
        config(['services.replicate.api_token' => 't']);
        Http::fake(['https://api.replicate.com/v1/models/elevenlabs/music/predictions' => Http::response(['title' => 'Insufficient credit', 'detail' => 'You have insufficient credit to run this model.'], 402)]);
        try { $this->replicate(); $this->fail('A refused request was not reported.'); }
        catch (ProviderFailed $e) { $this->assertSame('vendor_credit', VendorError::classify($e->getMessage())); }

        Http::fake(['https://api.replicate.com/v1/models/elevenlabs/music/predictions' => Http::response(['id' => 'p1', 'status' => 'failed', 'error' => 'invalid prompt'])]);
        $this->expectException(ProviderFailed::class);
        $this->replicate();
    }

    public function test_a_job_past_its_deadline_is_cancelled_and_then_counts_as_failed(): void
    {
        config(['services.replicate.api_token' => 't']);
        Http::fake([
            'https://api.replicate.com/v1/models/elevenlabs/music/predictions' => Http::response(['id' => 'p2', 'status' => 'processing']),
            'https://api.replicate.com/v1/predictions/p2/cancel' => Http::response(['id' => 'p2', 'status' => 'canceled']),
        ]);
        try { $this->replicate(0); $this->fail('A cancelled job was not reported.'); }
        catch (ProviderFailed $e) { $this->assertStringContainsString('did not finish', $e->getMessage()); }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/predictions/p2/cancel'));
    }

    public function test_our_adapters_refusal_wording_and_status_in_the_message_are_classified(): void
    {
        $this->assertSame('content_refused', VendorError::classify("This model's content filter declined the image — it is strict"));
        $this->assertSame('content_refused', VendorError::classify('The video model declined this segment — its moderation flags some content'));
        $this->assertSame('vendor_config', VendorError::classify('nano-banana failed to start (401): {"detail":"Unauthenticated"}'));
        $this->assertSame('busy', VendorError::classify('gemini-tts failed to start (429): {}'));
        $this->assertSame('other', VendorError::classify('The model returned no file.'));
        // A number in a quoted line or file name is not a status, and a failed poll does not say nothing was made.
        $this->assertSame('content_refused', VendorError::classify('The voice engine\'s safety filter refused to read this line: "Call (402) 555-0199 today". Reword it and try again.'));
        $this->assertSame('other', VendorError::classify('Name the video to cut out by its file name: photo (403).png'));
        $this->assertSame('other', VendorError::classify('nano-banana poll failed (503).'));
        $this->assertSame('vendor_credit', VendorError::classify('The music model could not start (402): You have insufficient credit to run this model.'));
    }
}
