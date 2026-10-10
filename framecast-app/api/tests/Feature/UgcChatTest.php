<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Ugc\UgcController;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Bus, DB, Schema};
use Mockery;
use Tests\TestCase;

/** UGC Ads as a conversation (2026-10-10): ask only what is unclear, pitch one video with its price, "Make it" hands off once. */
class UgcChatTest extends TestCase
{
    private array $replies = [];
    private array $handed = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'ugc_chat_test', 'database.connections.ugc_chat_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('ugc_chat_test');
        Bus::fake();
        foreach (['characters' => ['workspace_id', 'name', 'description', 'status', 'is_stock', 'reference_asset_id', 'gender'],
            'assets' => ['workspace_id', 'asset_type', 'title', 'storage_url', 'mime_type', 'duration_seconds']] as $table => $cols) {
            Schema::create($table, function (Blueprint $t) use ($cols) { $t->id(); foreach ($cols as $c) $t->text($c)->nullable(); $t->timestamps(); });
        }
        (require database_path('migrations/2026_10_10_160000_create_ugc_chats.php'))->up();
        DB::table('assets')->insert([['id' => 10, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'yara.png', 'storage_url' => 'x/yara.png', 'mime_type' => 'image/png'],
            ['id' => 20, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'serum.jpg', 'storage_url' => 'x/serum.jpg', 'mime_type' => 'image/jpeg']]);
        DB::table('characters')->insert(['id' => 5, 'workspace_id' => null, 'name' => 'Yara', 'status' => 'active', 'is_stock' => true, 'reference_asset_id' => 10, 'gender' => 'female']);

        $credits = Mockery::mock(CreditService::class)->shouldIgnoreMissing();
        $credits->shouldReceive('limitFor')->andReturn(true);
        $credits->shouldReceive('planTier')->andReturn('creator');
        $this->app->instance(CreditService::class, $credits);
        $ai = Mockery::mock(AIGenerationAdapter::class);
        $ai->shouldReceive('generate')->andReturnUsing(fn () => ['content' => json_encode(array_shift($this->replies))]);
        $this->app->instance(AIGenerationAdapter::class, $ai);

        $segments = [['kind' => 'on_camera', 'script_text' => 'This serum fixed my dull skin in a week.', 'seconds' => 15, 'visual_brief' => 'Bathroom mirror selfie',
            'voice_direction' => 'Warm.', 'anchor' => 'This serum fixed my dull skin in a week.', 'anchor_role' => 'establish', 'source' => null, 'speed' => 1.0, 'headline' => null, 'motion_prompt' => '']];
        $ugc = Mockery::mock(UgcController::class)->makePartial();
        $ok = fn (array $data, int $status = 200) => new JsonResponse(['data' => $data, 'meta' => []], $status);
        $ugc->shouldReceive('plan')->andReturnUsing(fn () => $ok(['format' => 'direct_camera', 'script' => $segments[0]['script_text'], 'segments' => $segments,
            'presenter' => 'A woman in her late twenties', 'presenter_character_id' => 5, 'credits_per_character' => 99]));
        $ugc->shouldReceive('readLink')->andReturnUsing(fn () => $ok(['product' => 'Dewbloom serum', 'context' => 'Vitamin C, 30 ml', 'must_include' => '']));
        $ugc->shouldReceive('generateOneShot')->andReturnUsing(function ($request) use ($ok) {
            $this->handed[] = $request->all();
            return $ok(['takes' => [['id' => 300 + count($this->handed), 'status' => 'generating']], 'credits_quoted' => (int) $request->input('credits'), 'run_id' => 'run-'.count($this->handed)], 201);
        });
        $ugc->shouldReceive('takes')->andReturnUsing(fn () => $ok(['takes' => [['id' => 301, 'status' => 'generating', 'credits' => 330]]]));
        $this->app->instance(UgcController::class, $ugc);
    }

    private function user(int $workspace = 1): User
    {
        return (new User)->forceFill(['id' => $workspace, 'workspace_id' => $workspace]);
    }

    public function test_it_asks_when_unclear_then_pitches_one_priced_video_and_makes_it_once(): void
    {
        $this->withoutMiddleware();
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->assertCreated()->json('data.id');

        $this->replies[] = ['action' => 'ask', 'question' => 'Who says it on camera?', 'chips' => ['You', 'One of our actors', 'Let us pick']];
        $reply = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'A UGC ad for my serum',
            'link' => 'https://dewbloom.co', 'attachments' => [['asset_id' => 20]]])->assertOk();
        $facts = collect($reply->json('data.chat.messages'))->firstWhere('kind', 'facts');
        $this->assertSame(['source' => 'page', 'text' => 'Dewbloom serum · Vitamin C, 30 ml'], $facts['payload']['facts'][0], 'what we read from the page is shown back');
        $reply = $reply->json('data.reply');
        $this->assertSame(['question', ['You', 'One of our actors', 'Let us pick']], [$reply['kind'], $reply['payload']['chips']]);
        $state = json_decode(DB::table('ugc_chats')->where('id', $chat)->value('state_json'), true);
        $this->assertSame('Dewbloom serum', $state['link']['facts']['product']);
        $this->assertSame('product', $state['attachments'][0]['role'], 'a photo is a product reference by default');

        $this->replies[] = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Dewbloom review', 'summary' => 'Yara holds the 30 ml bottle at its real size and tells you what changed.',
            'script' => 'This serum fixed my dull skin in a week.', 'product' => 'Dewbloom serum', 'context' => 'Dull skin', 'duration_seconds' => 15];
        $pitch = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Let us pick',
            'presenters' => [['character_id' => 5, 'voice' => ['pace' => 'faster', 'delivery' => 'excited']]]])->assertOk()->json('data.reply');
        $this->assertSame('pitch', $pitch['kind']);
        $this->assertCount(1, $pitch['payload']['takes'], 'one video unless the user asks for more');
        $this->assertSame(['one_shot', 'omni'], [$pitch['payload']['takes'][0]['mode'], $pitch['payload']['takes'][0]['engine']]);
        $this->assertSame(15 * 22, $pitch['payload']['credits'], 'priced by the pipeline: 15 s of Omni');
        $this->assertArrayNotHasKey('request', $pitch['payload']['takes'][0], 'the internal request is never shown');

        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc'])->assertStatus(422);
        $made = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc', 'consent' => true])
            ->assertOk()->json('data');
        $this->assertSame('making', $made['reply']['kind']);
        $this->assertCount(1, $this->handed);
        $this->assertSame(5, (int) $this->handed[0]['character_id']);
        $this->assertSame(330, (int) $this->handed[0]['credits']);
        $this->assertStringContainsString('Excited', $this->handed[0]['segments'][0]['voice_direction'], "the presenter's voice reaches the take");
        $this->assertSame(1.12, (float) $this->handed[0]['segments'][0]['speed']);
        $this->assertSame([20], $this->handed[0]['product_asset_ids'], 'product photos are references');
        $this->assertSame('generating', end($made['chat']['messages'])['payload']['runs'][0]['takes'][0]['status']);

        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc', 'consent' => true])->assertStatus(409);
        $this->actingAs($this->user(2))->getJson("/api/v1/ugc/chats/{$chat}")->assertNotFound();
    }

    public function test_rewrite_returns_a_sharper_script_and_is_free(): void
    {
        $this->withoutMiddleware();
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        $this->replies[] = ['script' => 'I almost skipped this serum. Glad I did not.'];
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/rewrite", ['script' => 'I almost did not buy this serum but I am glad I did.'])
            ->assertOk()->assertJsonPath('data.script', 'I almost skipped this serum. Glad I did not.');
        $this->assertSame(0, DB::table('ugc_chat_messages')->count(), 'a rewrite is not a turn');
    }
}
