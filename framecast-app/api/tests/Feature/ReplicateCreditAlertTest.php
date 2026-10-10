<?php

namespace Tests\Feature;

use App\Services\Generation\Video\ReplicateVeoAdapter;
use App\Services\Vendors\VendorAlerts;
use Illuminate\Support\Facades\{Cache, Http, Log};
use Tests\TestCase;

/** 2026-10-10: Replicate ran out of credit and every UGC video failed with nobody told. */
class ReplicateCreditAlertTest extends TestCase
{
    public function test_running_out_of_replicate_credit_marks_it_down_and_new_videos_are_refused_with_a_clear_message(): void
    {
        Cache::flush();
        Log::spy();
        config(['services.replicate.api_token' => 'test']);
        Http::fake(['api.replicate.com/*' => Http::response(['title' => 'Insufficient credit', 'detail' => 'You have insufficient credit to run this model.'], 402)]);

        try {
            app(ReplicateVeoAdapter::class)->start('a prompt', 6, null, 'omni');
            $this->fail('a refused submit throws');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Insufficient credit', $e->getMessage());
        }

        $this->assertSame('vendor_credit', VendorAlerts::down('replicate')['kind'] ?? null);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Vendor alert')->once();

        try {
            VendorAlerts::assertUp(['replicate']);
            $this->fail('new work is held while the account is down');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringContainsString('temporarily unavailable on our side', $e->getMessage());
            $this->assertStringNotContainsString('Replicate', $e->getMessage(), 'users never see the vendor name');
        }
    }
}
