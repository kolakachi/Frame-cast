<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\ProviderFailed;
use App\Services\Generation\Video\OpenRouterVideoAdapter;
use App\Services\Generation\Video\ReplicateVeoAdapter;
use App\Services\Ugc\UgcOneShotCompiler;
use App\Services\Ugc\UgcOneShotPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Seedance 2.5 for UGC through OpenRouter (owner, 2026-10-18): the adapter, the switch, its cost, skits in one take. */
class UgcSeedanceOpenRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.api_key' => 'or-test', 'services.openrouter.url' => 'https://openrouter.ai/api/v1', 'services.ugc.seedance_openrouter' => true]);
    }

    public function test_a_take_is_sent_with_its_references_and_voice_and_the_model_is_kept_for_its_cost(): void
    {
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['id' => 'gen-1', 'status' => 'pending'])]);
        $id = app(OpenRouterVideoAdapter::class)->start('seedance25', 'A skit.', 44, null, array_fill(0, 11, 'data:image/png;base64,AA'), 7, '720p', '9:16', 'https://media.example/read.wav');
        $this->assertSame('gen-1', $id);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $images = array_filter($body['input_references'], fn ($r) => $r['type'] === 'image_url');
            return $request->hasHeader('Authorization', 'Bearer or-test') && $body['model'] === 'bytedance/seedance-2.5'
                && $body['duration'] === 30 && $body['seed'] === 7 && $body['generate_audio'] === true && count($images) === 9
                && end($body['input_references']) === ['type' => 'audio_url', 'audio_url' => ['url' => 'https://media.example/read.wav']]
                && ! isset($body['frame_images']);
        });
        $this->assertSame('bytedance/seedance-2.5', Cache::get('openrouter-video-model:gen-1'));
    }

    public function test_a_presenter_seedance_will_not_render_is_its_own_refusal(): void
    {
        Http::fake(['openrouter.ai/api/v1/videos' => Http::response(['error' => ['message' => 'HTTP 400: {"error":{"code":"InputImageSensitiveContentDetected.PrivacyInformation","message":"The request failed because the input image may contain real person."}}', 'code' => 400]], 400)]);
        try {
            app(OpenRouterVideoAdapter::class)->start('seedance25', 'Hi.', 6, null, ['data:image/png;base64,AA'], null, '720p', '9:16');
            $this->fail('declined');
        } catch (ProviderFailed $e) {
            $this->assertSame(OpenRouterVideoAdapter::FACE_DECLINED, $e->getMessage());
            $this->assertStringContainsString('declined this segment', $e->getMessage(), 'the chat shows it as a declined video');
        }
    }

    public function test_a_finished_job_gives_its_signed_link_and_a_flagged_one_is_declined(): void
    {
        Http::fake(['openrouter.ai/api/v1/videos/gen-2' => Http::response(['id' => 'gen-2', 'status' => 'completed', 'urls' => ['https://storage.example/v.mp4'], 'usage' => ['cost' => 1.17]]),
            'openrouter.ai/api/v1/videos/gen-3' => Http::response(['id' => 'gen-3', 'status' => 'failed', 'error' => 'OutputVideoSensitiveContentDetected'])]);
        $this->assertSame('https://storage.example/v.mp4', app(OpenRouterVideoAdapter::class)->pollUntilDone('gen-2', 5));
        $this->expectExceptionMessage('declined this segment');
        app(OpenRouterVideoAdapter::class)->pollUntilDone('gen-3', 5);
    }

    public function test_seedance_goes_through_openrouter_only_while_the_trial_is_on(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['id' => 'gen-4']), 'api.replicate.com/*' => Http::response(['id' => 'rep-1'])]);
        $veo = app(ReplicateVeoAdapter::class);
        $this->assertSame('or:gen-4', $veo->start('Hi.', 6, null, 'seedance25'));
        $this->assertSame('rep-1', $veo->start('Hi.', 6, null, 'omni'), 'only Seedance moves');
        config(['services.ugc.seedance_openrouter' => false]);
        $this->assertSame('rep-1', $veo->start('Hi.', 6, null, 'seedance25'));
    }

    public function test_an_openrouter_video_is_costed_once_at_what_it_reports(): void
    {
        Cache::put('openrouter-video-model:gen-5', 'bytedance/seedance-2.5');
        $recorded = [];
        $usage = Mockery::mock(\App\Services\ApiUsageService::class);
        $usage->shouldReceive('record')->andReturnUsing(function ($row) use (&$recorded) { $recorded[] = $row; return null; });
        $this->app->instance(\App\Services\ApiUsageService::class, $usage);
        Http::fake(['openrouter.ai/api/v1/videos/gen-5' => Http::response(['id' => 'gen-5', 'status' => 'completed', 'urls' => ['https://s/v.mp4'], 'usage' => ['cost' => 1.16523]])]);
        app(OpenRouterVideoAdapter::class)->pollUntilDone('gen-5', 5);
        app(OpenRouterVideoAdapter::class)->pollUntilDone('gen-5', 5);
        $this->assertCount(1, $recorded);
        $this->assertSame(['openrouter', 'video', 'bytedance/seedance-2.5', 'succeeded', 1.16523],
            [$recorded[0]['provider'], $recorded[0]['service'], $recorded[0]['model'], $recorded[0]['status'], $recorded[0]['estimated_cost_usd']]);
    }

    public function test_a_skit_of_up_to_30_seconds_is_one_seedance_take_unless_omni_is_asked_for(): void
    {
        $credits = Mockery::mock(CreditService::class)->shouldIgnoreMissing();
        $credits->shouldReceive('planTier')->andReturn('creator');
        $user = (new User)->forceFill(['id' => 1, 'workspace_id' => 1]);
        $segments = [['kind' => 'on_camera', 'script_text' => 'Did you change your skin routine?', 'seconds' => 4, 'visual_brief' => 'Kitchen', 'speaker' => 1],
            ['kind' => 'on_camera', 'script_text' => 'One serum. That is all.', 'seconds' => 4, 'visual_brief' => 'Kitchen', 'speaker' => 2]];
        $v = ['ugc_type' => 'skit', 'cast_character_ids' => [5, 6], 'format' => 'story', 'segments' => $segments, 'engine' => 'seedance25'];
        $this->assertSame('seedance25', UgcOneShotPricing::price($user, $v, $segments, $credits)['engine']);
        $this->assertSame('omni', UgcOneShotPricing::price($user, ['engine' => 'omni'] + $v, $segments, $credits)['engine'], 'the fallback');
        config(['services.ugc.seedance_openrouter' => false]);
        $this->assertSame('omni', UgcOneShotPricing::price($user, $v, $segments, $credits)['engine']);
    }

    public function test_a_one_take_skit_names_who_is_on_camera_and_who_says_each_line(): void
    {
        $single = UgcOneShotCompiler::compileSingle([
            ['kind' => 'on_camera', 'script_text' => 'Did you change your routine?', 'seconds' => 4, 'visual_brief' => 'medium shot at the counter', 'speaker' => 1],
            ['kind' => 'on_camera', 'script_text' => 'One serum.', 'seconds' => 3, 'visual_brief' => 'close on her face', 'speaker' => 2],
        ], ['ugc_type' => 'skit', 'speaker_names' => [1 => 'Layla', 2 => 'Grace Kim'], 'speakers' => [1 => 'Layla — late twenties', 2 => 'Grace Kim — early forties']]);
        $this->assertStringContainsString('@Layla (Layla — late twenties), sits frame-left', $single['prompt']);
        $this->assertStringContainsString('Cut to @GraceKim on camera, close on her face.', $single['prompt']);
        $this->assertStringContainsString('@GraceKim says: "One serum."', $single['prompt']);
        $this->assertSame('Did you change your routine? One serum.', $single['dialogue']);
        $dressed = UgcOneShotCompiler::compileSingle([
            ['kind' => 'on_camera', 'script_text' => 'Hi.', 'seconds' => 3, 'visual_brief' => 'at the counter', 'speaker' => 1],
            ['kind' => 'on_camera', 'script_text' => 'Hello.', 'seconds' => 3, 'visual_brief' => 'at the counter', 'speaker' => 2],
        ], ['ugc_type' => 'skit', 'wardrobe' => 'a burgundy henley', 'speaker_names' => [1 => 'Layla', 2 => 'Nadia'],
            'speaker_wardrobes' => [1 => 'a burgundy henley', 2 => 'a light blue button-up shirt']]);
        $this->assertSame(1, substr_count($dressed['prompt'], 'burgundy henley'), 'only Layla wears it (canary 327 put both in it)');
        $this->assertStringContainsString('@Nadia, wearing a light blue button-up shirt', $dressed['prompt']);
        $this->assertStringContainsString('they never dress alike', $dressed['prompt']);
    }
}
