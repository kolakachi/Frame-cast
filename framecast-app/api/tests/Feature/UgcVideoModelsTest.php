<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\Video\{OpenRouterVideoAdapter, ReplicateVeoAdapter};
use App\Services\Ugc\UgcVideoModels;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** More video models for a UGC take through OpenRouter (owner, 2026-10-21), each priced at Seedance 2.5's margin. */
class UgcVideoModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => 'or-test', 'services.ugc.openrouter_models' => ['kling3_pro']]);
    }

    public function test_each_model_makes_the_lengths_it_lists(): void
    {
        $this->assertSame([3, 15, 15], [UgcVideoModels::snap('kling3_std', 2), UgcVideoModels::snap('kling3_std', 15), UgcVideoModels::snap('kling3_std', 20)]);
        $this->assertSame([4, 6, 8, 8], [UgcVideoModels::snap('veo31_lite', 3), UgcVideoModels::snap('veo31_lite', 5), UgcVideoModels::snap('veo31_lite', 7), UgcVideoModels::snap('veo31_lite', 12)], 'Veo makes 4, 6 or 8 seconds');
        $this->assertSame([15, 8, 15], [UgcVideoModels::maxClip('wan30'), UgcVideoModels::maxClip('veo31_fast'), UgcVideoModels::maxClip('kling3_pro')], 'a plan is split into clips of at most 15 s');
    }

    public function test_prices_follow_seedance_margin(): void
    {
        // Cost a second at 720p with sound / 0.7 cents, rounded up: Kling 3.0 Pro $0.168 -> 24, Veo 3.1 Lite $0.05 -> 8.
        $this->assertSame([24, 8, 33, 22], [CreditService::oneshotPerSecond('kling3_pro'), CreditService::oneshotPerSecond('veo31_lite'),
            CreditService::oneshotPerSecond('seedance25'), CreditService::oneshotPerSecond('omni')]);
        foreach (UgcVideoModels::MODELS as $key => $m) $this->assertGreaterThan(0, $m['per_second'], $key);
    }

    public function test_customers_see_only_tested_models_and_staff_see_all(): void
    {
        $customer = new User(['role' => 'user']); $staff = new User(['role' => 'super_admin']);
        $this->assertTrue(UgcVideoModels::usable('kling3_pro', $customer), 'its test take passed');
        $this->assertFalse(UgcVideoModels::usable('wan30', $customer), 'not until its test take passes');
        $this->assertTrue(UgcVideoModels::usable('wan30', $staff), 'staff run the tests');
        $this->assertFalse(UgcVideoModels::usable('made_up', $staff));
    }

    public function test_openrouter_is_sent_the_model_its_length_size_and_first_frame_only(): void
    {
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'job-1'])]);
        $id = app(OpenRouterVideoAdapter::class)->start('kling3_pro', 'A creator holds the serum', 17, 'data:image/png;base64,AAAA', ['data:image/png;base64,BBBB'], 7, '480p', '9:16', 'https://x/read.wav');
        $this->assertSame('job-1', $id);
        Http::assertSent(function ($r) {
            $b = $r->data();
            return $b['model'] === 'kwaivgi/kling-v3.0-pro' && $b['duration'] === 15 && $b['resolution'] === '720p' && $b['aspect_ratio'] === '9:16'
                && ($b['frame_images'][0]['frame_type'] ?? '') === 'first_frame' && ! isset($b['input_references']) && $b['generate_audio'] === true;
        });
    }

    public function test_seedance_2_0_keeps_its_reference_photos(): void
    {
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'job-2'])]);
        app(OpenRouterVideoAdapter::class)->start('seedance20_fast', 'A creator', 10, null, ['data:image/png;base64,BBBB'], null, '720p', '9:16');
        Http::assertSent(fn ($r) => $r->data()['model'] === 'bytedance/seedance-2.0-fast' && count($r->data()['input_references'] ?? []) === 1);
    }

    public function test_the_take_routes_the_new_models_to_openrouter_and_refuses_a_shape_they_do_not_make(): void
    {
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'job-3'])]);
        $this->assertSame('or:job-3', app(ReplicateVeoAdapter::class)->start('A creator', 6, null, 'wan30', [], null, '720p', [], '9:16'));
        $this->expectException(\InvalidArgumentException::class);
        app(ReplicateVeoAdapter::class)->start('A creator', 6, null, 'veo31_lite', [], null, '720p', [], '1:1');
    }
}
