<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Developer\V1\UgcChatController;
use App\Http\Controllers\Api\V1\Ugc\UgcChatController as AppChat;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class DeveloperUgcChatTest extends TestCase
{
    private array $made = [];

    private function request(array $body): Request
    {
        $r = Request::create('/api/developer/v1/ugc/chats', 'POST', $body);
        $user = new User(['workspace_id' => 3]);
        $r->setUserResolver(fn () => $user);
        return $r;
    }

    private const PITCH = ['id' => 12, 'role' => 'assistant', 'kind' => 'pitch', 'content' => 'Ada films a POV moment at her desk.', 'payload' => [
        'type' => 'pov_hook', 'title' => 'Desk POV', 'aspect' => '9:16', 'credits' => 265,
        'plan' => ['script' => '', 'segments' => [['seconds' => 8]]],
        'takes' => [['name' => 'Ada', 'engine' => 'seedance25', 'credits' => 265, 'mode' => 'one_shot', 'request' => ['secret' => 'x'],
            'engines' => [['key' => 'omni', 'engine' => 'omni', 'credits' => 176, 'available' => true, 'note' => '']]]],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $app = Mockery::mock(AppChat::class);
        $app->shouldReceive('store')->andReturn(new JsonResponse(['data' => ['id' => 'c0ffee00-0000-4000-8000-000000000001']], 201));
        $app->shouldReceive('message')->andReturn(new JsonResponse(['data' => ['reply' => self::PITCH, 'chat' => []]]));
        $app->shouldReceive('make')->andReturnUsing(function (Request $r) {
            $this->made[] = $r->all();
            return new JsonResponse(['data' => ['reply' => ['id' => 13, 'kind' => 'making', 'content' => 'Making it now.', 'payload' => []],
                'chat' => ['messages' => [['payload' => ['runs' => [['name' => 'Ada', 'takes' => [['id' => 900, 'status' => 'generating']]]]]]]]]]);
        });
        $this->app->instance(AppChat::class, $app);
    }

    public function test_a_brief_returns_the_pitch_an_assistant_can_show_without_internals(): void
    {
        $data = app(UgcChatController::class)->start($this->request(['text' => 'A POV ad', 'type' => 'pov_hook']))->getData(true)['data'];
        $this->assertSame('c0ffee00-0000-4000-8000-000000000001', $data['chat_id']);
        $this->assertSame(['pov_hook', 265, 1, true], [$data['reply']['pitch']['type'], $data['reply']['pitch']['credits'], $data['reply']['pitch']['shots'], $data['reply']['pitch']['music_bed_available']]);
        $this->assertSame('omni', $data['reply']['pitch']['videos'][0]['other_models'][0]['model']);
        $this->assertStringNotContainsString('secret', json_encode($data), 'the take request stays inside');
    }

    public function test_make_needs_consent_and_the_same_key_always_makes_the_same_request(): void
    {
        $id = 'c0ffee00-0000-4000-8000-000000000001';
        try {
            app(UgcChatController::class)->make($this->request(['message_id' => 12]), $id);
            $this->fail('made without consent');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }
        $this->assertSame([], $this->made);
        $first = app(UgcChatController::class)->make($this->request(['message_id' => 12, 'consent' => true, 'music' => true]), $id);
        app(UgcChatController::class)->make($this->request(['message_id' => 12, 'consent' => true, 'music' => true]), $id);
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame($this->made[0]['request_id'], $this->made[1]['request_id'], 'a retried call makes one video');
        $this->assertTrue($this->made[0]['music']);
        $this->assertSame([['video_id' => 900, 'presenter' => 'Ada', 'status' => 'generating']], $first->getData(true)['data']['videos']);
    }
}
