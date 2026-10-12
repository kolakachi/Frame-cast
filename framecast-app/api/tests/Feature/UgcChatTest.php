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
    private string $takeStatus = 'generating';
    private string $takeFormat = 'one_shot';
    private array $sceneRetries = [];
    private array $deleted = [];
    private array $prompts = [];
    private array $planned = [];
    private array $linkPhotos = [];
    private ?array $planOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'ugc_chat_test', 'database.connections.ugc_chat_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('ugc_chat_test');
        Bus::fake();
        config(['services.ugc.chat_open_to_all' => true]);
        // These tests exercise each type's production route; the gate that holds them back from customers has its own test.
        config(['services.ugc.types_live' => array_keys(\App\Services\Ugc\UgcTypes::DEFINITIONS)]);
        foreach (['scenes' => ['project_id', 'scene_order', 'image_generation_settings_json'], 'characters' => ['workspace_id', 'name', 'description', 'status', 'is_stock', 'reference_asset_id', 'gender'],
            'assets' => ['workspace_id', 'asset_type', 'title', 'storage_url', 'mime_type', 'duration_seconds', 'status', 'tags']] as $table => $cols) {
            Schema::create($table, function (Blueprint $t) use ($cols) { $t->id(); foreach ($cols as $c) $t->text($c)->nullable(); $t->timestamps(); });
        }
        (require database_path('migrations/2026_10_10_160000_create_ugc_chats.php'))->up();
        DB::table('assets')->insert([['id' => 10, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'yara.png', 'storage_url' => 'x/yara.png', 'mime_type' => 'image/png'],
            ['id' => 20, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'serum.jpg', 'storage_url' => 'x/serum.jpg', 'mime_type' => 'image/jpeg'],
            ['id' => 60, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'dewbloom-front.jpg', 'storage_url' => 'x/front.jpg', 'mime_type' => 'image/jpeg']]);
        // What each product photo shows (VISION): the real reader calls a vision model, so the test fixes its answers.
        $sheets = [20 => ['kind' => 'product', 'what' => 'a face serum', 'form' => 'squeeze tube', 'size' => 'about the length of a hand', 'look' => 'white with a green flip cap', 'label' => 'Dewbloom Vitamin C'],
            60 => ['kind' => 'product', 'what' => 'a face serum', 'form' => 'squeeze tube', 'size' => 'palm-sized', 'look' => 'white, green cap, seen from the front', 'label' => 'Dewbloom']];
        $vision = Mockery::mock(\App\Services\Ugc\ProductVision::class);
        $vision->shouldReceive('sheets')->andReturnUsing(fn (array $ids) => array_intersect_key($sheets, array_flip($ids)));
        $vision->shouldReceive('look')->andReturnUsing(fn (array $ids) => ($first = collect($ids)->map(fn ($i) => $sheets[$i] ?? null)->filter()->first()) ? \App\Services\Ugc\ProductVision::line($first) : '');
        $this->app->instance(\App\Services\Ugc\ProductVision::class, $vision);
        $finder = Mockery::mock(\App\Services\Ugc\ProductImageFinder::class);
        $finder->shouldReceive('import')->andReturnUsing(fn () => $this->linkPhotos);
        $this->app->instance(\App\Services\Ugc\ProductImageFinder::class, $finder);
        DB::table('characters')->insert(['id' => 5, 'workspace_id' => null, 'name' => 'Yara', 'status' => 'active', 'is_stock' => true, 'reference_asset_id' => 10, 'gender' => 'female']);

        $credits = Mockery::mock(CreditService::class)->shouldIgnoreMissing();
        $credits->shouldReceive('limitFor')->andReturn(true);
        $credits->shouldReceive('planTier')->andReturn('creator');
        $this->app->instance(CreditService::class, $credits);
        $ai = Mockery::mock(AIGenerationAdapter::class);
        $ai->shouldReceive('generate')->andReturnUsing(function ($key, $vars) { $this->prompts[] = [$key, $vars]; return ['content' => json_encode(array_shift($this->replies))]; });
        $this->app->instance(AIGenerationAdapter::class, $ai);

        $segments = [['kind' => 'on_camera', 'script_text' => 'This serum fixed my dull skin in a week.', 'seconds' => 15, 'visual_brief' => 'Bathroom mirror selfie',
            'voice_direction' => 'Warm.', 'anchor' => 'This serum fixed my dull skin in a week.', 'anchor_role' => 'establish', 'source' => null, 'speed' => 1.0, 'headline' => null, 'motion_prompt' => '']];
        $ugc = Mockery::mock(UgcController::class)->makePartial();
        $ok = fn (array $data, int $status = 200) => new JsonResponse(['data' => $data, 'meta' => []], $status);
        $ugc->shouldReceive('plan')->andReturnUsing(fn ($request) => ($this->planned[] = $request->all()) ? $ok($this->planOverride ?? ['format' => 'direct_camera', 'script' => $segments[0]['script_text'], 'segments' => $segments,
            'presenter' => 'A woman in her late twenties', 'presenter_character_id' => 5, 'credits_per_character' => 99]) : null);
        $ugc->shouldReceive('readLink')->andReturnUsing(fn () => $ok(['product' => 'Dewbloom serum', 'context' => 'Vitamin C, 30 ml', 'must_include' => '']));
        $ugc->shouldReceive('generateOneShot')->andReturnUsing(function ($request) use ($ok) {
            $this->handed[] = $request->all();
            return $ok(['takes' => [['id' => 300 + count($this->handed), 'status' => 'generating']], 'credits_quoted' => (int) $request->input('credits'), 'run_id' => 'run-'.count($this->handed)], 201);
        });
        $ugc->shouldReceive('takes')->andReturnUsing(fn ($request) => $ok(['takes' => [['id' => 301, 'status' => $this->takeStatus, 'credits' => 330, 'format' => $this->takeFormat,
            'working' => false, 'scene_rows' => [['id' => 41, 'visual' => 'done'], ['id' => 42, 'visual' => 'failed', 'error' => 'x']]]]]));
        $ugc->shouldReceive('sceneRetry')->andReturnUsing(function ($request, $sceneId) use ($ok) { $this->sceneRetries[] = $sceneId; return $ok(['retried' => ['visual']]); });
        $ugc->shouldReceive('generate')->andReturnUsing(function ($request) use ($ok) { $this->handed[] = $request->all(); return $ok(['takes' => [['id' => 400]], 'run_id' => 'run-c'], 201); });
        $this->app->instance(UgcController::class, $ugc);
        $projects = Mockery::mock(\App\Http\Controllers\Api\V1\Project\ProjectController::class);
        $projects->shouldReceive('destroy')->andReturnUsing(function ($request, $projectId) use ($ok) { $this->deleted[] = $projectId; return $ok(['deleted' => true]); });
        $this->app->instance(\App\Http\Controllers\Api\V1\Project\ProjectController::class, $projects);
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
        $chatMessages = $reply->json('data.chat.messages');
        $facts = collect($chatMessages)->firstWhere('kind', 'facts');
        $this->assertSame(['page', 'Dewbloom serum · Vitamin C, 30 ml'], [$facts['payload']['facts'][0]['source'], $facts['payload']['facts'][0]['text']], 'what we read from the page is shown back');
        $reply = $reply->json('data.reply');
        $this->assertSame(['question', ['You', 'One of our actors', 'Let us pick']], [$reply['kind'], $reply['payload']['chips']]);
        $state = json_decode(DB::table('ugc_chats')->where('id', $chat)->value('state_json'), true);
        $this->assertSame('Dewbloom serum', $state['link']['facts']['product']);
        $this->assertSame('product', $state['attachments'][0]['role'], 'a photo is a product reference by default');
        $sent = collect($chatMessages)->firstWhere('role', 'user')['payload']['attachments'][0];
        $this->assertSame(['serum.jpg', 'image'], [$sent['title'], $sent['type']], 'what was sent shows as pills, not "1 file"');

        $this->replies[] = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Dewbloom review', 'summary' => 'Yara holds the 30 ml bottle at its real size and tells you what changed.',
            'script' => 'This serum fixed my dull skin in a week.', 'product' => 'Dewbloom serum', 'context' => 'Dull skin', 'duration_seconds' => 15];
        $pitch = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Let us pick',
            'presenters' => [['character_id' => 5, 'voice' => ['pace' => 'faster', 'delivery' => 'excited']]]])->assertOk()->json('data.reply');
        $this->assertSame('pitch', $pitch['kind']);
        $this->assertCount(1, $pitch['payload']['takes'], 'one video unless the user asks for more');
        $this->assertSame(['one_shot', 'omni'], [$pitch['payload']['takes'][0]['mode'], $pitch['payload']['takes'][0]['engine']]);
        // Billed for the seconds generated (#4): this 9-word line is made as 8 s of Omni, not the 15 s planned.
        $this->assertSame(8 * 22, $pitch['payload']['credits'], 'priced by the pipeline: the seconds Omni actually makes');
        $this->assertArrayNotHasKey('request', $pitch['payload']['takes'][0], 'the internal request is never shown');

        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc'])->assertStatus(422);
        $made = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc', 'consent' => true])
            ->assertOk()->json('data');
        $this->assertSame('making', $made['reply']['kind']);
        $this->assertCount(1, $this->handed);
        $this->assertSame(5, (int) $this->handed[0]['character_id']);
        $this->assertSame(176, (int) $this->handed[0]['credits']);
        $this->assertStringContainsString('Excited', $this->handed[0]['segments'][0]['voice_direction'], "the presenter's voice reaches the take");
        $this->assertSame(1.12, (float) $this->handed[0]['segments'][0]['speed']);
        $this->assertSame([20], $this->handed[0]['product_asset_ids'], 'product photos are references');
        $this->assertSame('generating', end($made['chat']['messages'])['payload']['runs'][0]['takes'][0]['status']);

        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc', 'consent' => true])->assertStatus(409);
        $this->actingAs($this->user(2))->getJson("/api/v1/ugc/chats/{$chat}")->assertNotFound();

        // Try again: only a failed video, made again from the same pitch; the failed take is removed.
        $makingId = $made['reply']['id'];
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/retry", ['message_id' => $makingId, 'run' => 0])->assertStatus(409);
        $this->takeStatus = 'needs_attention';
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/retry", ['message_id' => $makingId, 'run' => 0])->assertOk();
        $this->assertCount(2, $this->handed);
        $this->assertNotSame($this->handed[0]['request_id'], $this->handed[1]['request_id']);
        $this->assertSame([301], $this->deleted, 'the failed take is removed');
        $run = json_decode(DB::table('ugc_chat_messages')->where('id', $makingId)->value('payload_json'), true)['runs'][0];
        $this->assertSame(['run-2', 1], [$run['run_id'], $run['attempt']]);
    }

    public function test_the_director_sees_what_the_product_photos_show_and_a_link_brings_its_photos(): void
    {
        $this->withoutMiddleware();
        $this->linkPhotos = [60];
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->assertCreated()->json('data.id');
        $this->replies[] = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Dewbloom review', 'summary' => 'Yara holds the tube.',
            'script' => 'This serum fixed my dull skin in a week.', 'product' => 'Dewbloom serum', 'context' => 'Dull skin', 'duration_seconds' => 15];
        $res = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'A UGC ad for my serum',
            'link' => 'https://dewbloom.co/products/serum', 'attachments' => [['asset_id' => 20]],
            'presenters' => [['character_id' => 5, 'voice' => null]]])->assertOk()->json('data');

        // The user's own photo first, then the page's; each described, never just a file name.
        $state = $res['chat']['state'];
        $this->assertSame([20, 60], array_column($state['attachments'], 'asset_id'));
        $this->assertTrue($state['attachments'][1]['from_link']);
        $materials = collect($this->prompts)->firstWhere(0, 'ugc_chat_turn')[1]['materials'];
        $this->assertStringContainsString('serum.jpg showing a face serum (squeeze tube); white with a green flip cap; about the length of a hand; the label is printed with "Dewbloom Vitamin C"', $materials);
        $this->assertStringContainsString('from their product page: dewbloom-front.jpg showing', $materials);
        $this->assertStringContainsString('The product, exactly as its photos show: a face serum (squeeze tube)', $this->planned[0]['context'], 'the plan describes the product from its photo');
        $facts = collect($res['chat']['messages'])->firstWhere('kind', 'facts')['payload']['facts'][0];
        $this->assertSame(60, $facts['images'][0]['asset_id'], 'the page photos are shown under what we read there');

        // A page photo that is not theirs to use is taken out, and the video is made without it.
        $pitch = collect($res['chat']['messages'])->firstWhere('kind', 'pitch');
        $after = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/attachments/remove", ['asset_id' => 60])->assertOk()->json('data.chat.state');
        $this->assertSame([20], array_column($after['attachments'], 'asset_id'));
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/make", ['message_id' => $pitch['id'], 'request_id' => '6f1c1e7a-1111-4a2b-9c3d-123456789abc', 'consent' => true])->assertOk();
        $this->assertSame([20], $this->handed[0]['product_asset_ids']);
        $this->assertSame(20, (int) $this->handed[0]['product_asset_id']);
        $this->actingAs($this->user(2))->postJson("/api/v1/ugc/chats/{$chat}/attachments/remove", ['asset_id' => 20])->assertNotFound();
    }

    public function test_the_director_picks_a_setting_with_a_photo_and_the_outfit_suits_it(): void
    {
        $this->withoutMiddleware();
        DB::table('assets')->insert([['id' => 30, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'UGC setting: beach', 'storage_url' => 'x/beach.png', 'mime_type' => 'image/png', 'status' => 'active', 'tags' => '["ugc_setting"]'],
            ['id' => 31, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'UGC setting: car', 'storage_url' => 'x/car.png', 'mime_type' => 'image/png', 'status' => 'active', 'tags' => '["ugc_setting"]']]);
        // A user's own upload with the same title never stands in for ours (review, 2026-10-11).
        DB::table('assets')->insert(['id' => 32, 'workspace_id' => 2, 'asset_type' => 'image', 'title' => 'UGC setting: kitchen', 'storage_url' => 'x/k.png', 'mime_type' => 'image/png', 'status' => 'active', 'tags' => '["ugc_setting"]']);
        DB::table('assets')->insert(['id' => 33, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'UGC setting: gym', 'storage_url' => 'x/g.png', 'mime_type' => 'image/png', 'status' => 'active', 'tags' => '[]']);
        $this->assertSame(['car', 'beach'], array_keys(\App\Services\Ugc\UgcSettings::available()), 'only settings whose photo exists are offered');
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->assertCreated()->json('data.id');
        $pitch = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Sunscreen', 'summary' => 'Yara on the beach.',
            'script' => 'This serum fixed my dull skin in a week.', 'product' => 'Dewbloom serum', 'context' => 'Sun', 'duration_seconds' => 15];

        $this->replies[] = $pitch + ['setting' => 'beach'];
        $made = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'At the beach', 'presenters' => [['character_id' => 5]]])
            ->assertOk()->json('data.reply.payload.takes.0');
        $this->assertSame('beach', $made['setting']);
        $this->assertContains($made['wardrobe'], config('ugc_settings.beach.wardrobe'), 'beach clothes, never swimwear');

        // A setting with no photo yet is never sent; one with a photo is picked instead.
        $this->replies[] = $pitch + ['setting' => 'boutique'];
        $made = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Another', 'presenters' => [['character_id' => 5]]])
            ->assertOk()->json('data.reply.payload.takes.0');
        $this->assertSame('car', $made['setting'], 'the beach was just used, so the other setting with a photo');
    }

    public function test_a_background_set_on_the_presenter_wins_and_the_list_offers_only_places_with_a_photo(): void
    {
        $this->withoutMiddleware();
        DB::table('assets')->insert([['id' => 30, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'UGC setting: beach', 'storage_url' => 'x/beach.png', 'mime_type' => 'image/png', 'status' => 'active', 'tags' => '["ugc_setting"]'],
            ['id' => 31, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'UGC setting: car', 'storage_url' => 'x/car.png', 'mime_type' => 'image/png', 'status' => 'active', 'tags' => '["ugc_setting"]']]);
        $listed = $this->actingAs($this->user())->getJson('/api/v1/ugc/settings')->assertOk()->json('data.settings');
        $this->assertSame(['car', 'beach'], array_column($listed, 'key'));
        $this->assertSame('Car', $listed[0]['label']);
        $this->assertStringContainsString('/assets/31', $listed[0]['image_url']);

        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->assertCreated()->json('data.id');
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Go', 'presenters' => [['character_id' => 5, 'setting' => 'moon']]])->assertStatus(422);
        $this->replies[] = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Serum', 'summary' => 'Yara on the beach.',
            'script' => 'This serum fixed my dull skin in a week.', 'product' => 'Dewbloom serum', 'context' => 'Sun', 'duration_seconds' => 15, 'setting' => 'beach'];
        $take = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Go', 'presenters' => [['character_id' => 5, 'setting' => 'car']]])
            ->assertOk()->json('data.reply.payload.takes.0');
        $this->assertSame('car', $take['setting'], 'the place the user set beats the director\'s');
        $this->assertSame('car', json_decode(DB::table('ugc_chats')->where('id', $chat)->value('state_json'), true)['presenters'][0]['setting'], 'kept with the chat');
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

    public function test_a_founder_story_needs_the_users_own_face(): void
    {
        $this->withoutMiddleware();
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        $this->replies[] = ['action' => 'pitch', 'type' => 'founder_story', 'format' => 'direct_camera', 'title' => 'Why I made it', 'summary' => 'The founder explains why.',
            'script' => 'I made this because my skin was dry.', 'product' => 'Dewbloom serum', 'context' => 'Founder', 'duration_seconds' => 15];
        $reply = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'A founder story for my serum', 'type' => 'founder_story',
            'presenters' => [['character_id' => 5]]])->assertOk()->json('data');
        $this->assertSame('question', $reply['reply']['kind'], 'a stock actor never says "I made this"');
        $this->assertSame(['Make it a testimonial instead'], $reply['reply']['payload']['chips']);
        $this->assertSame([], $this->handed);

        $this->replies[] = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Dewbloom review', 'summary' => 'Yara reviews it.',
            'script' => 'This serum fixed my dull skin.', 'product' => 'Dewbloom serum', 'context' => 'Dull skin', 'duration_seconds' => 15];
        $next = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Make it a testimonial instead', 'type' => 'founder_story',
            'presenters' => [['character_id' => 5]]])->assertOk()->json('data');
        $this->assertSame(['pitch', 'testimonial', 'testimonial'], [$next['reply']['kind'], $next['reply']['payload']['type'], $next['chat']['state']['type']]);

        DB::table('characters')->insert(['id' => 6, 'workspace_id' => 1, 'name' => 'Ada (me)', 'status' => 'active', 'is_stock' => false, 'reference_asset_id' => 10]);
        $this->replies[] = ['action' => 'pitch', 'type' => 'founder_story', 'format' => 'direct_camera', 'title' => 'Why I made it', 'summary' => 'Ada explains why.',
            'script' => 'I made this because my skin was dry.', 'product' => 'Dewbloom serum', 'context' => 'Founder', 'duration_seconds' => 15];
        $own = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'It is me, use my face', 'type' => 'founder_story',
            'presenters' => [['character_id' => 6]]])->assertOk()->json('data.reply');
        $this->assertSame(['pitch', 'founder_story'], [$own['kind'], $own['payload']['type']]);
    }

    public function test_while_staff_only_only_the_team_gets_the_chat(): void
    {
        $this->withoutMiddleware();
        config(['services.ugc.chat_open_to_all' => false, 'create.allowed_domains' => ['wyvstudio.com']]);
        $customer = $this->user()->forceFill(['email' => 'shopper@example.com', 'role' => 'owner']);
        $team = $this->user()->forceFill(['email' => 'hello@wyvstudio.com', 'role' => 'owner']);
        $this->actingAs($customer)->postJson('/api/v1/ugc/chats')->assertNotFound();
        $this->actingAs($customer)->getJson('/api/v1/ugc/chats')->assertNotFound();
        $this->actingAs($team)->postJson('/api/v1/ugc/chats')->assertCreated();
        config(['services.ugc.chat_allowed_emails' => ['shopper@example.com']]);
        $this->actingAs($customer)->postJson('/api/v1/ugc/chats')->assertCreated();
        config(['services.ugc.chat_allowed_emails' => []]);
        config(['services.ugc.chat_open_to_all' => true]);
        $this->actingAs($customer)->postJson('/api/v1/ugc/chats')->assertCreated();
    }

    public function test_unknown_types_are_refused_and_all_menu_types_are_exposed(): void
    {
        $this->withoutMiddleware();
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'An ad', 'type' => 'unknown'])->assertStatus(422);
        $types = $this->actingAs($this->user())->getJson('/api/v1/ugc/settings')->assertOk()->json('data.types');
        $this->assertCount(14, $types);
        $this->assertNotContains(false, array_column($types, 'available'));
    }

    public function test_a_testimonial_that_shows_a_routine_is_planned_as_separate_shots(): void
    {
        // Take 283: open, pour and taste as one talking take could only be one overloaded shot.
        $this->withoutMiddleware();
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        $this->replies[] = ['action' => 'pitch', 'type' => 'testimonial', 'format' => 'direct_camera', 'title' => 'Snack', 'summary' => 'She holds up the pouch, opens it, pours peanuts into her hand and takes a crunchy bite.',
            'script' => 'This serum fixed my dull skin in a week.', 'product' => 'Crunchy Pea', 'context' => 'Snack break', 'duration_seconds' => 15];
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'A UGC ad', 'attachments' => [['asset_id' => 20]],
            'presenters' => [['character_id' => 5, 'voice' => null]]])->assertOk();
        $this->assertSame('story', $this->planned[0]['format']);
    }

    public function test_new_type_routes_stay_staff_only_until_opened(): void
    {
        $this->withoutMiddleware();
        config(['services.ugc.types_live' => []]);
        $types = collect($this->actingAs($this->user())->getJson('/api/v1/ugc/settings')->assertOk()->json('data.types'))->keyBy('key');
        $this->assertSame([false, false, false], [$types['skit']['available'], $types['pov_hook']['available'], $types['reaction']['available']], 'new types hidden from customers');
        $this->assertSame([true, false], [$types['green_screen']['available'], $types['green_screen']['live']], 'older types stay, made the old way');
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'A skit', 'type' => 'skit'])->assertStatus(422);
        $staff = $this->user()->forceFill(['role' => 'super_admin']);
        $this->assertTrue(collect($this->actingAs($staff)->getJson('/api/v1/ugc/settings')->json('data.types'))->firstWhere('key', 'skit')['available'], 'staff see everything');
        config(['services.ugc.types_live' => ['skit']]);
        $this->assertTrue(collect($this->actingAs($this->user())->getJson('/api/v1/ugc/settings')->json('data.types'))->firstWhere('key', 'skit')['available'], 'opened one type at a time');
        $this->assertFalse(\App\Services\Ugc\UgcTypes::native('asmr', $this->user()));
        $this->assertArrayNotHasKey('pov_hook', \App\Services\Ugc\UgcTypes::contracts($this->user()), 'the director is never offered a type the user cannot choose');
        $this->assertStringContainsString('No compositing', \App\Services\Ugc\UgcTypes::contracts($this->user())['green_screen'][3]);
    }

    public function test_every_type_routes_to_its_production_contract(): void
    {
        $this->withoutMiddleware();
        DB::table('characters')->insert(['id' => 6, 'workspace_id' => 1, 'name' => 'Maker', 'status' => 'active', 'is_stock' => false, 'reference_asset_id' => 10]);
        foreach (\App\Services\Ugc\UgcTypes::DEFINITIONS as $type => $definition) {
            if ($type === 'auto') continue;
            $format = $definition[2];
            $silent = in_array($type, ['asmr', 'pov_hook', 'reaction'], true);
            $segment = ['kind' => $type === 'reaction' ? 'reaction' : ($silent ? 'b_roll' : 'on_camera'),
                'script_text' => $silent ? '' : 'Here is the product.', 'seconds' => 5, 'visual_brief' => 'The product on a desk.',
                'motion_prompt' => 'Open the package gently.', 'voice_direction' => 'Whisper this softly.', 'speed' => 1,
                'source' => $silent && $type !== 'reaction' ? 'generate' : null, 'headline' => $silent ? 'A useful discovery' : '', 'speaker' => $type === 'skit' ? 1 : null];
            $segments = [$segment];
            if ($type === 'skit') $segments[] = array_replace($segment, ['speaker' => 2, 'script_text' => 'That looks useful.']);
            $this->planOverride = ['format' => $format, 'script' => \App\Services\Ugc\UgcPlan::script($segments), 'segments' => $segments, 'presenter_character_id' => 5];
            $this->replies[] = ['action' => 'pitch', 'type' => $type, 'format' => $format, 'summary' => 'A product video.', 'script' => $this->planOverride['script']];
            $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
            $input = ['text' => 'Make this ad', 'type' => $type];
            if ($type === 'green_screen') $input['attachments'] = [['asset_id' => 20, 'role' => 'background']];
            if ($type === 'founder_story') $input['presenters'] = [['character_id' => 6]];
            if ($type === 'skit') $input['presenters'] = [['character_id' => 5], ['character_id' => 6]];
            $pitch = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", $input)->assertOk()->json('data.reply');
            $this->assertSame('pitch', $pitch['kind'], $type);
            $stored = json_decode(DB::table('ugc_chat_messages')->where('id', $pitch['id'])->value('payload_json'), true);
            $take = $stored['takes'][0];
            $this->assertSame($type, $take['request']['ugc_type']);
            $this->assertSame($format, end($this->planned)['format']);
            $this->assertSame(in_array($type, ['reaction', 'pov_hook'], true) ? 'composed' : 'one_shot', $take['mode'], $type);
            if ($type === 'skit') {
                $this->assertCount(1, $pitch['payload']['takes']);
                $this->assertSame([5, 6], $take['request']['cast_character_ids']);
            }
            if ($type === 'green_screen') $this->assertSame(20, $take['request']['composition']['background_asset_id']);
        }
    }

    public function test_green_screen_asks_for_a_background_and_skit_for_two_speakers(): void
    {
        $this->withoutMiddleware();
        foreach (['green_screen', 'skit'] as $type) {
            $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
            $this->replies[] = ['action' => 'pitch', 'type' => $type, 'summary' => 'A video.', 'script' => 'Try this.'];
            $reply = $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'Make it', 'type' => $type])->assertOk()->json('data.reply');
            $this->assertSame('question', $reply['kind']);
        }
        $this->assertSame([], $this->planned);
    }

    public function test_a_composed_video_retries_only_its_failed_shots_and_is_never_remade_whole(): void
    {
        $this->withoutMiddleware();
        $this->takeFormat = 'composed';
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        DB::table('ugc_chat_messages')->insert(['id' => 900, 'chat_id' => $chat, 'role' => 'assistant', 'kind' => 'making', 'content' => 'Making it now.',
            'payload_json' => json_encode(['pitch_id' => 0, 'runs' => [['run_id' => 'run-x', 'take_ids' => [301], 'name' => 'Yara', 'take' => 0]]]), 'created_at' => now()]);
        $this->takeStatus = 'needs_attention';
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/retry", ['message_id' => 900, 'run' => 0])->assertOk();
        $this->assertSame([42], $this->sceneRetries, 'only the failed shot is made again');
        $this->assertSame([], $this->handed, 'never remade whole: its finished shots were already paid for');
        $this->assertSame([], $this->deleted);
    }

    public function test_a_presenter_from_another_workspace_is_dropped(): void
    {
        $this->withoutMiddleware();
        DB::table('characters')->insert(['id' => 77, 'workspace_id' => 2, 'name' => 'Someone Private', 'status' => 'active', 'is_stock' => false, 'reference_asset_id' => 10]);
        $chat = $this->actingAs($this->user())->postJson('/api/v1/ugc/chats')->json('data.id');
        $this->replies[] = ['action' => 'ask', 'question' => 'What is it?', 'chips' => ['A serum']];
        $this->actingAs($this->user())->postJson("/api/v1/ugc/chats/{$chat}/messages", ['text' => 'An ad', 'presenters' => [['character_id' => 77], ['character_id' => 5]]])->assertOk();
        $state = json_decode(DB::table('ugc_chats')->where('id', $chat)->value('state_json'), true);
        $this->assertSame([5], array_column($state['presenters'], 'character_id'));
    }
}
