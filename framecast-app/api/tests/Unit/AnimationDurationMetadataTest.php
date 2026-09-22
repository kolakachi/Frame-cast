<?php

namespace Tests\Unit;

use App\Services\Generation\Video\ReplicateI2VAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnimationDurationMetadataTest extends TestCase
{
    public function test_metadata_uses_the_model_request_duration_not_the_callers_duration(): void
    {
        config(['services.replicate.api_token' => 'test',
            'services.replicate.i2v_veo_fast_model' => 'test/veo',
            'services.replicate.i2v_veo_fast_version' => null]);
        Http::preventStrayRequests();
        Http::fake([
            'api.replicate.com/v1/models/test/veo/predictions' => Http::response(['id' => 'test-id']),
            'api.replicate.com/v1/predictions/test-id' => Http::response([
                'status' => 'succeeded', 'output' => 'https://example.test/clip.mp4',
            ]),
        ]);
        $result = (new ReplicateI2VAdapter)->animate('https://example.test/image.png', 'Move gently', 'veo_fast', 5);
        $this->assertSame(4, $result['duration_seconds']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['input']['duration'] === 4);
    }
}
