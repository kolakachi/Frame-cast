<?php

namespace Tests\Unit;

use App\Services\Create\ShotRoute;
use App\Services\Generation\Video\ReplicateVeoAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** What the adapter sends each engine respects that engine's input rules (ShotRoute::INPUTS), checked on the wire. */
class VideoAdapterInputsTest extends TestCase
{
    public function test_each_engine_gets_a_start_frame_and_references_only_where_it_takes_both(): void
    {
        config(['services.replicate.api_token' => 'test']);
        $sent = [];
        Http::fake(function ($request) use (&$sent) { $sent[] = $request->data()['input']; return Http::response(['id' => 'p1'], 201); });
        $adapter = app(ReplicateVeoAdapter::class);
        foreach (ShotRoute::INPUTS as $engine => $rule) {
            $sent = [];
            $adapter->start('A woman reacts to her phone.', 8, 'data:image/png;base64,AAAA', $engine, ['https://example.com/cast.png'], null, '720p');
            $input = $sent[0];
            $both = isset($input['image']) && ! empty($input['reference_images']);
            $this->assertSame($rule['both'], $both, $engine.': start frame with references '.($rule['both'] ? 'allowed' : 'refused by the provider'));
            $this->assertTrue(isset($input['image']), $engine.': the start frame wins when both are given');
        }
    }
}
