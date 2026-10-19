<?php

namespace Tests\Feature;

use App\Models\{Asset, User, Workspace};
use App\Services\Create\{CreateStorage, SampleLibrary, TemplateLibrary};
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/** Templates (owner, 2026-10-16): one library with the examples, staff-only until public, following the reference options. */
class CreateTemplatesTest extends TestCase
{
    use BuildsDeveloperSchema;
    private User $staff;
    private User $customer;
    private TemplateLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'create_test', 'database.connections.create_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array', 'services.posthog.key' => '', 'create.enabled' => true, 'create.runtime_controls_enabled' => false,
            'create.worker_ownership_required' => false, 'create.mode' => 'fixture', 'create.templates_public' => false]);
        DB::purge('create_test');
        Bus::fake(); Http::preventStrayRequests(); Redis::shouldReceive('get')->andReturn(null);
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->buildDeveloperSchema();
        foreach (['2026_09_25_200000_create_api_operations', '2026_10_10_090000_add_hold_expired_at_to_api_operations', '2026_09_28_120000_create_composition_conversations',
            '2026_09_29_000000_create_composition_attempts', '2026_09_29_120000_link_composition_outputs', '2026_09_29_180000_add_create_output_metadata',
            '2026_09_30_120000_create_create_plans', '2026_09_30_130000_add_create_provider_consent', '2026_10_01_120000_create_create_plan_media',
            '2026_10_01_130000_create_create_styles', '2026_10_03_150000_create_composition_trace_events', '2026_10_03_160000_widen_create_plan_media_item_index',
            '2026_10_06_230000_create_create_stored_files', '2026_10_07_000000_create_create_runtime_controls', '2026_10_07_120000_add_notes_to_create_attachments',
            '2026_10_16_120000_create_create_templates_table', '2026_10_17_220000_add_free_audit_to_create_templates'] as $m) (require database_path('migrations/'.$m.'.php'))->up();
        $ours = Workspace::create(['name' => 'WyvStudio', 'plan_tier' => 'agency', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $theirs = Workspace::create(['name' => 'Customer', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $this->staff = User::create(['email' => 'staff@example.test', 'name' => 'Staff', 'role' => 'super_admin', 'status' => 'active']);
        $this->staff->forceFill(['workspace_id' => $ours->id])->save();
        $this->customer = User::create(['email' => 'customer@example.test', 'name' => 'Customer', 'role' => 'owner', 'status' => 'active']);
        $this->customer->forceFill(['workspace_id' => $theirs->id])->save();
        config(['create.workspaces' => [(int) $ours->id, (int) $theirs->id], 'create.allowed_emails' => ['staff@example.test', 'customer@example.test']]);
        $this->library = app(TemplateLibrary::class);
    }

    private function rejected(int $status, callable $fn): void
    {
        try { $fn(); $this->fail('Expected '.$status); } catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode(), $e->getMessage()); }
    }

    /** A finished version in staff's workspace: a product picture made by the build, a voiceover and music, and its video. */
    private function version(): array
    {
        $storage = app(CreateStorage::class);
        $conversation = (string) Str::uuid(); $run = (string) Str::uuid(); $revision = (string) Str::uuid();
        DB::table('create_conversations')->insert(['id' => $conversation, 'workspace_id' => $this->staff->workspace_id, 'created_by_user_id' => $this->staff->id,
            'title' => 'Ridge 2 drop', 'version' => 3, 'settings_json' => '{}', 'head_revision_id' => $revision, 'created_at' => now(), 'updated_at' => now()]);
        $files = [];
        foreach ([['image', 'image/png', 'png', 'shoe'], ['audio', 'audio/wav', 'wav', 'voice']] as $i => [$type, $mime, $ext, $what]) {
            $asset = Asset::create(['workspace_id' => $this->staff->workspace_id, 'asset_type' => $type, 'title' => 'AI '.$what, 'storage_url' => 'create-upload://x/'.$i,
                'mime_type' => $mime, 'file_size_bytes' => 4, 'status' => 'active', 'restriction_scope' => 'workspace']);
            $sha = hash('sha256', $what.'!!');
            $path = 'create/uploads/'.$this->staff->workspace_id.'/'.Str::uuid().'/'.$sha.'.'.$ext;
            $storage->put($path, $what.'!!', ['visibility' => 'private']);
            $files[] = ['asset_id' => $asset->id, 'purpose' => 'source', 'name' => 'asset-'.$asset->id.'-'.$sha.'.'.$ext, 'sha256' => $sha, 'bytes' => strlen($what.'!!'),
                'mime_type' => $mime, 'asset_type' => $type, 'storage_path' => $path, 'title' => 'AI '.$what];
        }
        DB::table('create_plan_media')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'plan_id' => 'p', 'item_index' => 0, 'kind' => 'ai_image', 'description_hash' => 'h', 'status' => 'succeeded',
            'record_json' => json_encode(['description' => 'Ridge 2 shoe, side profile', 'file' => ['asset_id' => $files[0]['asset_id']]]), 'charged_credits' => 43, 'created_at' => now(), 'updated_at' => now()]);
        $input = ['input_files' => [], 'derived_files' => $files, 'settings' => ['aspect_ratio' => '9:16', 'duration_seconds' => 15],
            'style_pack' => ['slug' => 'neo-brutalism', 'name' => 'Neo-brutalism'],
            'plan' => ['voice' => 'Puck', 'narration' => ['New drop.', 'Shop the drop.'], 'scenes' => [['from' => 0, 'to' => 2, 'what' => 'Hook']]],
            'plan_media' => [['kind' => 'ai_image', 'description' => 'Ridge 2 shoe, side profile', 'credits' => 43], ['kind' => 'voiceover', 'description' => 'Punchy read', 'credits' => 3],
                ['kind' => 'music', 'description' => 'Driving electronic', 'credits' => 34]]];
        DB::table('composition_runs')->insert(['id' => $run, 'conversation_id' => $conversation, 'workspace_id' => $this->staff->workspace_id, 'quote_id' => 'q_'.Str::random(8),
            'idempotency_key' => 'k'.Str::random(6), 'request_hash' => 'h', 'input_json' => json_encode($input), 'status' => 'preview_ready', 'stage' => 'Done', 'created_at' => now(), 'updated_at' => now()]);
        $html = '<!doctype html><html lang="en" data-composition-variables=\'[{"id":"headline","type":"string","label":"Hook","default":"NEW DROP"},{"id":"color_background","type":"color","label":"Field","default":"#FFE14D"}]\'>'
            .'<body><img id="heroshoe" src="'.$files[0]['name'].'"><audio src="'.$files[1]['name'].'"></audio><script>txt("hook", v.headline); paint(v.color_background);</script></body></html>';
        $bundle = ['index.html' => $html, 'style.css' => "@font-face{font-family:'Archivo Black';src:url(a.ttf)}"];
        $storage->put('create/previews/'.$run.'/v.mp4', 'video', ['visibility' => 'private']);
        DB::table('composition_revisions')->insert(['id' => $revision, 'conversation_id' => $conversation, 'run_id' => $run, 'number' => 1, 'bundle_json' => json_encode($bundle),
            'bundle_hash' => 'b', 'artifact_path' => 'create/previews/'.$run.'/v.mp4', 'artifact_hash' => 'a', 'summary' => 'V1',
            'metadata_json' => json_encode(['requirements' => [['text' => 'Mention Ridge 2']]]), 'created_at' => now()]);
        return [$conversation, $revision, $files];
    }

    public function test_the_page_is_staff_only_until_public_and_the_examples_stay_on_the_empty_screen(): void
    {
        $this->assertCount(32, $this->library->page($this->staff)['templates']);
        $this->rejected(404, fn () => $this->library->page($this->customer));
        [$conversation, $revision] = $this->version();
        $added = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer']);
        // Customers keep the 32 examples on the empty screen; a new template waits for the switch.
        $this->assertCount(32, SampleLibrary::catalogueFor($this->customer));
        $this->assertNotContains($added['id'], array_column(SampleLibrary::catalogueFor($this->customer), 'id'));
        $this->rejected(404, fn () => $this->library->show($this->customer, $added['id']));
        config(['create.templates_public' => true]);
        $this->assertCount(33, $this->library->page($this->customer)['templates']);
        $this->assertSame('Sneaker drop', $this->library->show($this->customer, $added['id'])['title']);
        $this->assertFalse($this->library->page($this->customer)['staff']);
    }

    public function test_staff_add_a_version_with_its_parts_and_only_from_their_own_workspace(): void
    {
        [$conversation, $revision] = $this->version();
        $this->rejected(403, fn () => $this->library->add($this->customer, $conversation, $revision, ['title' => 'x', 'category' => 'offer']));
        $draft = $this->library->draft($this->staff, $conversation, $revision);
        $this->assertSame([['id' => 'headline', 'label' => 'Hook', 'default' => 'NEW DROP', 'max' => 12]], $draft['parts']['text']);
        $this->assertSame('#FFE14D', $draft['parts']['colours'][0]['default']);
        $this->assertSame(['Archivo Black'], $draft['parts']['fonts']);
        $this->assertSame(['heroshoe', 'Ridge 2 shoe, side profile', 'ai_image'], [$draft['parts']['images'][0]['element'], $draft['parts']['images'][0]['what'], $draft['parts']['images'][0]['kind']]);
        $this->assertSame(['New drop.', 'Shop the drop.'], $draft['parts']['voice']['lines']);
        $this->assertSame('Neo-brutalism', $draft['parts']['style']);
        $this->assertSame(['1 line of text', '1 brand colour', '1 product picture', 'Voiceover', 'Music'], $draft['summary']);
        $added = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer', 'ask_hint' => 'Your product link']);
        $this->assertSame(['version', '9:16', 15, 'Neo-brutalism'], [$added['source'], $added['shape'], $added['seconds'], $added['style']]);
        $this->assertSame($added['id'], $this->library->draft($this->staff, $conversation, $revision)['existing_id']);
        // A customer's own conversation never becomes a template, even for staff.
        $customers = (string) Str::uuid();
        DB::table('create_conversations')->insert(['id' => $customers, 'workspace_id' => $this->customer->workspace_id, 'created_by_user_id' => $this->customer->id,
            'title' => 'Theirs', 'version' => 1, 'settings_json' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->rejected(404, fn () => $this->library->draft($this->staff, $customers, $revision));
        $this->library->archive($this->staff, $added['id']);
        $this->assertCount(32, $this->library->page($this->staff)['templates']);
    }

    public function test_a_free_plan_gets_one_free_first_ad_from_a_template_watermarked(): void
    {
        // FREE-FIRST (owner, 2026-10-17): the first template conversation of a free plan, as a free swap or free edit.
        [$conversation, $revision] = $this->version();
        $t = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer']);
        config(['create.templates_public' => true]);
        DB::table('create_templates')->where('id', $t['id'])->update(['free_audit_json' => json_encode(['passed' => true])]);
        if (! \Illuminate\Support\Facades\Schema::hasColumn('workspaces', 'onboarding_json')) \Illuminate\Support\Facades\Schema::table('workspaces', fn ($t) => $t->text('onboarding_json')->nullable());
        \App\Models\Workspace::whereKey($this->customer->workspace_id)->update(['plan_tier' => 'free']);
        $ws = (int) $this->customer->workspace_id;
        $this->assertNull(\App\Services\Create\FreeFirst::conversationId($ws));
        $first = $this->library->use($this->customer, $t['id'], ['product' => 'Volt Rush', 'match' => 'exact', 'idempotency_key' => 'f1']);
        $second = $this->library->use($this->customer, $t['id'], ['product' => 'Volt Rush again', 'match' => 'exact', 'idempotency_key' => 'f2']);
        $this->assertSame($first['id'], \App\Services\Create\FreeFirst::conversationId($ws));
        $this->assertTrue(\App\Services\Create\FreeFirst::applies($ws, $first['id']));
        $this->assertFalse(\App\Services\Create\FreeFirst::applies($ws, $second['id']), 'one free ad: a second template conversation is not free');

        // The free ad keeps the template's shape and length: a brief's "10-second square" is not applied (owner's test,
        // 2026-10-17: a "15-second vertical" brief on a square template failed the render's size check).
        $this->assertSame(['aspect_ratio' => '9:16', 'duration_seconds' => 15], \App\Services\Create\FreeFirst::copiedShape($first['id']));
        $sent = app(\App\Services\Create\ConversationService::class)->message($this->customer, $first['id'], ['content' => 'A 10-second square ad for Volt Rush',
            'idempotency_key' => 'm1', 'expected_version' => (int) DB::table('create_conversations')->where('id', $first['id'])->value('version')]);
        $settings = json_decode(DB::table('create_conversations')->where('id', $first['id'])->value('settings_json'), true);
        $this->assertSame(['9:16', 15], [$settings['aspect_ratio'], $settings['duration_seconds']]);
        $this->assertTrue(DB::table('create_messages')->where('conversation_id', $first['id'])->where('role', 'assistant')->where('content', 'like', "%keeps the template's shape and length (vertical, 15 seconds)%")->exists());
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->customer)->patchJson('/api/v1/create/conversations/'.$first['id'], ['settings' => ['aspect_ratio' => '1:1'], 'expected_version' => (int) DB::table('create_conversations')->where('id', $first['id'])->value('version')])
            ->assertStatus(422)->assertJsonFragment(['message' => sprintf(\App\Services\Create\FreeFirst::SHAPE_KEPT, 'vertical, 15 seconds')]);

        // A free edit on anything but the free ad needs a paid plan; on the free ad it is allowed and watermarked.
        $edits = app(\App\Services\Create\FreeEditService::class);
        $head = fn ($id) => DB::table('create_conversations')->where('id', $id)->first();
        $c2 = $head($second['id']);
        try { $edits->apply($this->customer, $second['id'], $c2->head_revision_id, ['headline' => 'VOLT RUSH'], (int) $c2->version, 'e2'); $this->fail('a second free ad was allowed'); }
        catch (HttpException $e) { $this->assertSame([402, \App\Services\Create\FreeFirst::GATE], [$e->getStatusCode(), $e->getMessage()]); }
        $c1 = $head($first['id']);
        try { $edits->apply($this->customer, $first['id'], $c1->head_revision_id, ['headline' => 'VOLT RUSH'], (int) $c1->version, 'e1'); }
        catch (HttpException $e) { $this->assertNotSame(402, $e->getStatusCode(), 'the free ad is not refused for its plan: '.$e->getMessage()); }
        $quote = \App\Models\ApiQuote::query()->where('workspace_id', $ws)->get()->first(fn ($q) => ! empty($q->payload_json['free_edit']));
        $this->assertTrue((bool) ($quote?->payload_json['watermark'] ?? false), 'the free ad renders with the WyvStudio mark');

        // Its AI-planned changes are free up to 5; then the reply offers the Test Pass (free edits stay open).
        $planning = app(\App\Services\Create\PlanService::class);
        $planning->assertCanPayPlanning($this->customer, $first['id']);
        foreach (range(1, \App\Services\Create\FreeFirst::PLAN_LIMIT) as $n) DB::table('create_plans')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $first['id'], 'message_id' => (string) Str::uuid(),
            'brief_sequence' => $n, 'idempotency_key' => 'p'.$n, 'request_hash' => 'h'.$n, 'provider' => 'test', 'plan_json' => '{}', 'status' => 'superseded', 'created_at' => now(), 'updated_at' => now()]);
        try { $planning->assertCanPayPlanning($this->customer, $first['id']); $this->fail('a sixth AI change was free'); }
        catch (HttpException $e) { $this->assertSame([402, \App\Services\Create\FreeFirst::PLAN_LIMIT_MESSAGE], [$e->getStatusCode(), $e->getMessage()]); }

        // Free only from a template with its own code and no AI-made video (owner, 2026-10-17).
        $fits = fn (array $t) => \App\Services\Create\FreeFirst::templateFits((object) [...$t, 'free_audit_json' => json_encode(['passed' => true])]);
        $this->assertTrue($fits(['source' => 'version', 'revision_id' => 'r', 'parts_json' => json_encode(['clips' => [['kind' => 'stock_video']]])]));
        $this->assertFalse($fits(['source' => 'version', 'revision_id' => 'r', 'parts_json' => json_encode(['clips' => [['kind' => 'ugc_take']]])]), 'a creator take is AI video');
        $this->assertFalse($fits(['source' => 'version', 'revision_id' => 'r', 'parts_json' => json_encode(['clips' => [['kind' => 'generated_shot']]])]));
        $this->assertFalse($fits(['source' => 'video', 'revision_id' => null, 'parts_json' => null]), 'no code of its own');

        // The planner is told it is working on a free account's free ad, and what it may change.
        $context = (new \ReflectionMethod(\App\Services\Create\PlanService::class, 'context'))->invoke(app(\App\Services\Create\PlanService::class), $this->customer, DB::table('create_conversations')->where('id', $first['id'])->first());
        $this->assertContains('generated pictures or video', $context['free_account']['not_allowed']);
        $this->assertStringContainsString('a new voiceover', implode(' ', $context['free_account']['allowed']));
        $other = (new \ReflectionMethod(\App\Services\Create\PlanService::class, 'context'))->invoke(app(\App\Services\Create\PlanService::class), $this->customer, DB::table('create_conversations')->where('id', $second['id'])->first());
        $this->assertArrayNotHasKey('free_account', $other);
        $this->assertStringContainsString('free_account, when present', \App\Services\Create\Planning\PlanPrompt::system());

        // A paid plan is never marked, and every conversation is open to it.
        \App\Models\Workspace::whereKey($ws)->update(['plan_tier' => 'creator']);
        $this->assertFalse(\App\Services\Create\FreeFirst::applies($ws, $first['id']));
        $this->assertFalse(\App\Services\Create\FreeFirst::freePlan($ws));
    }

    public function test_footage_of_the_templates_own_product_is_listed_and_the_brief_says_to_replace_it(): void
    {
        // Owner's test, 2026-10-17: an app demo's screen recording was not listed, so a free change kept the old product.
        [, $revision] = $this->version();
        $row = DB::table('composition_revisions')->where('id', $revision)->first();
        $bundle = json_decode($row->bundle_json, true);
        $bundle['index.html'] = str_replace('</body>', '<video src="asset-901-'.str_repeat('a', 64).'.mp4"></video></body>', $bundle['index.html']);
        if (! str_contains($bundle['index.html'], 'asset-901-')) $bundle['index.html'] .= '<video src="asset-901-'.str_repeat('a', 64).'.mp4"></video>';
        DB::table('composition_revisions')->where('id', $revision)->update(['bundle_json' => json_encode($bundle)]);
        $parts = TemplateLibrary::partsOf(DB::table('composition_revisions')->where('id', $revision)->first());
        $this->assertSame([['asset_id' => 901, 'what' => 'a video clip', 'kind' => 'file']], $parts['footage']);
        $this->assertContains('1 product recording to replace', TemplateLibrary::summary($parts));
        $t = (object) ['title' => 'App demo', 'parts_json' => json_encode($parts)];
        $this->assertStringContainsString("put my screen recording or footage of my product in its place", TemplateLibrary::brief($t, 'version', 'Lumio PDF reader'));
        $this->assertStringContainsString('a reaction or presenter stays', TemplateLibrary::brief($t, 'version', 'Lumio PDF reader'));
        $this->assertFalse(\App\Services\Create\FreeFirst::templateFits((object) ['source' => 'version', 'revision_id' => 'r', 'parts_json' => json_encode($parts)]), 'a free swap cannot replace footage');
    }

    public function test_a_free_ads_question_and_brief_never_offer_to_generate_anything(): void
    {
        // Owner's test, 2026-10-17: the free ad was asked for screens and offered "a placeholder".
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        $clarifier = app(\App\Services\Create\Planning\Clarifier::class);
        $free = ['free_account' => ['allowed' => [], 'not_allowed' => []], 'messages' => [], 'files' => []];
        $this->assertSame('Want your product photos in it? Attach them here, or reply "go without" to keep the template\'s pictures.',
            $clarifier->question([...$free, 'current_pictures' => [['src' => 'a.png']]], 0));
        $this->assertNull($clarifier->question([...$free, 'current_pictures' => []], 0), 'no picture slots: nothing to ask, no model call');
        $this->assertNull($clarifier->question([...$free, 'current_pictures' => [['src' => 'a.png']], 'files' => [['asset_type' => 'image', 'purpose' => 'source', 'title' => 'p']]], 0), 'photos attached');
        $t = (object) ['title' => 'Shoe drop', 'parts_json' => json_encode(['images' => [['what' => 'the shoe']]])];
        $this->assertStringContainsString("keep the template's own picture where I gave none", TemplateLibrary::brief($t, 'version', 'Volt', true));
        $this->assertStringContainsString('make a new picture only where I gave none', TemplateLibrary::brief($t, 'version', 'Volt'));
    }

    public function test_a_template_copys_voice_traces_back_to_the_voice_its_plan_bought_and_keeps_its_line_count(): void
    {
        // Owner's free-ad test, 2026-10-17: the copied narration was not found, so the voice swap failed and the gate refused.
        $mk = fn (array $meta) => Asset::create(['workspace_id' => $this->customer->workspace_id, 'asset_type' => 'audio', 'title' => 'a', 'storage_url' => 'x://a',
            'mime_type' => 'audio/wav', 'file_size_bytes' => 4, 'status' => 'active', 'restriction_scope' => 'workspace', 'metadata_json' => $meta])->id;
        $voice = $mk([]);
        $staffCopy = $mk(['imported_asset_id' => $voice]);
        $userCopy = $mk(['copied_from_asset_id' => $staffCopy]);
        $root = new \ReflectionMethod(\App\Services\Create\VoiceSwap::class, 'rootOf');
        $this->assertSame($voice, $root->invoke(null, $userCopy));
        $five = ['A', 'B', 'C', 'D', 'E'];
        $problems = \App\Services\Create\Planning\PlanPrompt::problems(['swap' => ['voice' => true], 'narration' => ['1', '2', '3', '4'], 'scenes' => [['start' => 0, 'end' => 15]]], ['current_narration' => $five, 'settings' => ['duration_seconds' => 15]]);
        $this->assertStringContainsString("keep the version's 5 narration lines", $problems[0] ?? '');
        $this->assertSame([], \App\Services\Create\Planning\PlanPrompt::problems(['swap' => ['voice' => true], 'narration' => ['1', '2', '3', '4', '5']], ['current_narration' => $five, 'settings' => ['duration_seconds' => 15]]));
        // A plan that still differs is re-split into the version's lines, keeping whole sentences where it can.
        $this->assertSame(['AI-powered PDF reader?', 'Meet Lumio.', 'Lumio brings AI', 'to your PDFs.', 'Try it at lumiodocs.com.'],
            \App\Services\Create\VoiceSwap::fitLines(['AI-powered PDF reader?', 'Meet Lumio.', 'Lumio brings AI to your PDFs.', 'Try it at lumiodocs.com.'], $five));
        $this->assertSame(['One. Two.', 'Three.', 'Four.', 'Five.', 'Six.'], \App\Services\Create\VoiceSwap::fitLines(['One.', 'Two.', 'Three.', 'Four.', 'Five.', 'Six.'], $five));
        $this->assertNull(\App\Services\Create\VoiceSwap::fitLines(['Hi there'], $five), 'fewer words than lines');
    }

    public function test_dead_fields_and_wyvstudio_written_into_the_code_are_found_and_keep_a_template_from_being_free(): void
    {
        // Owner's free-ad test, 2026-10-17: the cta field was never read, so the end card kept wyvstudio.com.
        [, $revision] = $this->version();
        $row = DB::table('composition_revisions')->where('id', $revision)->first();
        $bundle = json_decode($row->bundle_json, true);
        $decl = htmlspecialchars(json_encode([['id' => 'headline', 'type' => 'string', 'label' => 'Hook', 'default' => 'NEW DROP'], ['id' => 'cta', 'type' => 'string', 'label' => 'URL', 'default' => 'wyvstudio.com']]), ENT_QUOTES);
        $bundle['index.html'] = "<html data-composition-variables='".$decl."'><body><div id=\"h\"></div><div id=\"url\">wyvstudio<span>.</span>com</div><script>txt('h', v.headline); // see https://example.com</script></body></html>";
        DB::table('composition_revisions')->where('id', $revision)->update(['bundle_json' => json_encode($bundle)]);
        $parts = TemplateLibrary::partsOf(DB::table('composition_revisions')->where('id', $revision)->first());
        $this->assertSame(['cta'], $parts['dead_fields']);
        $this->assertTrue($parts['fixed_brand']);
        $this->assertSame(['headline'], array_column($parts['text'], 'id'), 'a dead field is not offered');
        $this->assertFalse(\App\Services\Create\FreeFirst::templateFits((object) ['source' => 'version', 'revision_id' => 'r', 'parts_json' => json_encode($parts)]));
        // Only templates whose render audit passed are free, however clean their code looks.
        $clean = ['source' => 'version', 'revision_id' => 'r', 'parts_json' => json_encode(['text' => [['id' => 'headline']]])];
        $this->assertTrue(\App\Services\Create\FreeFirst::templateFits((object) [...$clean, 'free_audit_json' => json_encode(['passed' => true])]));
        $this->assertFalse(\App\Services\Create\FreeFirst::templateFits((object) [...$clean, 'free_audit_json' => json_encode(['passed' => false, 'leftovers' => ['wyvstudio.com']])]));
        $this->assertFalse(\App\Services\Create\FreeFirst::templateFits((object) [...$clean, 'free_audit_json' => null]));
    }

    public function test_a_render_audit_is_recorded_only_for_the_templates_own_code_and_template_builds_are_staff_only(): void
    {
        [$conversation, $revision] = $this->version();
        $t = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer']);
        $bundle = json_decode(DB::table('composition_revisions')->where('id', $revision)->value('bundle_json'), true);
        $file = tempnam(sys_get_temp_dir(), 'audit');
        // The audit of a copy whose files got new asset ids (moved from a local install) still matches.
        $moved = array_map(fn ($text) => is_string($text) ? preg_replace('/asset-\d+-/', 'asset-77777-', $text) : $text, $bundle);
        file_put_contents($file, json_encode(['passed' => true, 'leftovers' => [], 'unseen' => [], 'overflow' => [], 'bundle_hash' => \App\Services\Create\TemplateAudit::bundleHash($moved)]));
        $this->artisan('create:template-audit', ['template' => $t['id'], 'result' => $file])->assertSuccessful();
        $this->assertTrue(json_decode(DB::table('create_templates')->where('id', $t['id'])->value('free_audit_json'), true)['passed']);
        file_put_contents($file, json_encode(['passed' => true, 'bundle_hash' => str_repeat('0', 64)]));
        $this->artisan('create:template-audit', ['template' => $t['id'], 'result' => $file])->assertFailed();
        $this->artisan('create:template-audit', ['template' => $t['id'], 'result' => $file, '--clear' => true])->assertSuccessful();
        $this->assertNull(DB::table('create_templates')->where('id', $t['id'])->value('free_audit_json'));

        // "Build as a template" is a staff setting.
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->customer)->postJson('/api/v1/create/conversations', ['template_build' => true])->assertStatus(403);
        $this->actingAs($this->staff)->postJson('/api/v1/create/conversations', ['template_build' => true])->assertStatus(201);
        $this->assertStringContainsString('settings.template_build, when true', \App\Services\Create\Planning\PlanPrompt::system());
    }

    public function test_make_mine_exactly_starts_from_a_copy_of_the_version_in_the_users_workspace(): void
    {
        [$conversation, $revision, $files] = $this->version();
        $t = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer']);
        config(['create.templates_public' => true]);
        $made = $this->library->use($this->customer, $t['id'], ['product' => 'Volt Rush, a zero-sugar energy drink', 'match' => 'exact', 'idempotency_key' => 'u1']);
        $this->assertSame($made, $this->library->use($this->customer, $t['id'], ['product' => 'Volt Rush', 'idempotency_key' => 'u1']), 'same key replays');
        $c = DB::table('create_conversations')->where('id', $made['id'])->first();
        $this->assertSame((int) $this->customer->workspace_id, (int) $c->workspace_id);
        $this->assertSame(['exact', $t['id']], [json_decode($c->settings_json, true)['reference_match'], json_decode($c->settings_json, true)['template_id']]);
        $head = DB::table('composition_revisions')->where('id', $c->head_revision_id)->first();
        $this->assertSame(1, (int) $head->number);
        $this->assertArrayNotHasKey('requirements', json_decode($head->metadata_json, true), 'the old product\'s requirements stay behind');
        $run = json_decode(DB::table('composition_runs')->where('id', $head->run_id)->value('input_json'), true);
        $this->assertCount(2, $run['input_files']);
        $html = json_decode($head->bundle_json, true)['index.html'];
        foreach ($run['input_files'] as $i => $f) {
            $asset = Asset::find($f['asset_id']);
            $this->assertSame((int) $this->customer->workspace_id, (int) $asset->workspace_id, 'copied into the user\'s workspace');
            $this->assertSame($files[$i]['asset_id'], $asset->metadata_json['copied_from_asset_id']);
            $this->assertStringContainsString($f['name'], $html, 'the code names the copy');
            $this->assertStringNotContainsString($files[$i]['name'], $html);
            $this->assertSame($files[$i]['sha256'], $f['sha256']);
            $this->assertTrue(app(CreateStorage::class)->exists($f['storage_path']));
        }
        $this->assertTrue(app(CreateStorage::class)->exists($head->artifact_path));
        $this->assertStringContainsString('Volt Rush, a zero-sugar energy drink', $made['brief']);
        $this->assertStringContainsString('Hook "NEW DROP"', $made['brief']);
        $this->assertStringContainsString('Use my attached photos there', $made['brief']);
        $this->assertStringContainsString('keep the track as it is', $made['brief']);
    }

    public function test_a_product_photo_on_an_exact_template_copy_brings_its_cut_out_as_one_change(): void
    {
        [$conversation, $revision] = $this->version();
        $t = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer']);
        config(['create.templates_public' => true]);
        $made = $this->library->use($this->customer, $t['id'], ['product' => 'Volt Rush', 'match' => 'exact', 'idempotency_key' => 'cut1']);
        \Illuminate\Support\Facades\Storage::fake('minio');
        \Illuminate\Support\Facades\Storage::disk('minio')->put('photos/can.jpg', 'jpeg-bytes');
        $photo = Asset::create(['workspace_id' => $this->customer->workspace_id, 'asset_type' => 'image', 'title' => 'Volt Rush can', 'storage_url' => 'minio://photos/can.jpg',
            'mime_type' => 'image/jpeg', 'file_size_bytes' => 10, 'status' => 'active', 'restriction_scope' => 'workspace']);
        $executor = \Mockery::mock(\App\Services\Create\PlanMediaExecutor::class);
        $executor->shouldReceive('cutoutBytes')->once()->with('jpeg-bytes', 'image/jpeg')->andReturn('png-bytes');
        $this->app->instance(\App\Services\Create\PlanMediaExecutor::class, $executor);
        $version = (int) DB::table('create_conversations')->where('id', $made['id'])->value('version');

        app(\App\Services\Create\ConversationService::class)->attach($this->customer, $made['id'], $photo->id, 'source', $version);
        $attached = DB::table('create_attachments')->where('conversation_id', $made['id'])->pluck('purpose', 'asset_id');
        $cut = Asset::where('title', 'Cut out · Volt Rush can')->first();
        $this->assertNotNull($cut);
        $this->assertSame(['image/png', (int) $this->customer->workspace_id], [$cut->mime_type, (int) $cut->workspace_id]);
        $this->assertSame(['source', 'source'], [$attached[$photo->id], $attached[$cut->id]]);
        $this->assertSame($version + 1, (int) DB::table('create_conversations')->where('id', $made['id'])->value('version'), 'one change, so the app\'s next message still matches');
        // Attaching the cut-out itself, or the photo again, makes no second cut-out (the mock allows one call).
        app(\App\Services\Create\ConversationService::class)->attach($this->customer, $made['id'], $photo->id, 'source', $version + 1);
    }

    public function test_a_cut_out_is_placed_where_the_templates_own_product_stands(): void
    {
        $dir = sys_get_temp_dir().'/fit-test-'.Str::random(6); @mkdir($dir);
        // An opaque block on a transparent canvas, drawn by overlay (drawbox writes no alpha on ffmpeg 5.1).
        $png = fn (string $name, string $canvas, string $block, int $x, int $y) => \Illuminate\Support\Facades\Process::run(['ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'color=c=black@0.0:s='.$canvas.',format=rgba', '-f', 'lavfi', '-i', 'color=c=red:s='.$block.',format=rgba',
            '-filter_complex', "[0][1]overlay={$x}:{$y}:format=auto,format=rgba", '-frames:v', '1', $dir.'/'.$name])->throw();
        $png('slot.png', '200x200', '40x120', 80, 40);   // the template's slim can, standing at y = 160
        $png('jar.png', '300x200', '280x180', 10, 10);   // the new product: a wide jar filling its picture
        $storage = app(CreateStorage::class);
        $slotPath = 'create/uploads/'.$this->staff->workspace_id.'/'.Str::uuid().'/'.hash('sha256', 'slot').'.png';
        $storage->put($slotPath, (string) file_get_contents($dir.'/slot.png'), ['visibility' => 'private']);
        $run = (string) Str::uuid(); $rev = (string) Str::uuid();
        DB::table('composition_runs')->insert(['id' => $run, 'conversation_id' => (string) Str::uuid(), 'workspace_id' => $this->staff->workspace_id, 'quote_id' => 'q_fit', 'idempotency_key' => 'kfit', 'request_hash' => 'h',
            'input_json' => json_encode(['input_files' => [['name' => 'asset-7-'.str_repeat('a', 64).'.png', 'storage_path' => $slotPath]]]), 'status' => 'preview_ready', 'stage' => 'Done', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('composition_revisions')->insert(['id' => $rev, 'conversation_id' => (string) Str::uuid(), 'number' => 1, 'run_id' => $run,
            'bundle_json' => json_encode(['index.html' => '<img id="can" src="asset-7-'.str_repeat('a', 64).'.png">']),
            'bundle_hash' => 'b', 'artifact_path' => 'create/previews/'.$run.'/v.mp4', 'artifact_hash' => 'a', 'summary' => 'V1', 'metadata_json' => '{}', 'created_at' => now()]);
        $fitted = \App\Services\Create\TemplateCutout::fitToSlot((object) ['head_revision_id' => $rev], (string) file_get_contents($dir.'/jar.png'));
        $this->assertNotNull($fitted);
        file_put_contents($dir.'/out.png', $fitted);
        $box = new \ReflectionMethod(\App\Services\Create\TemplateCutout::class, 'box');
        $size = new \ReflectionMethod(\App\Services\Create\TemplateCutout::class, 'size');
        $this->assertSame(['w' => 200, 'h' => 200], $size->invoke(null, $dir.'/out.png'), 'the template\'s canvas');
        ['w' => $w, 'h' => $h, 'x' => $x, 'y' => $y] = $box->invoke(null, $dir.'/out.png');
        $this->assertLessThanOrEqual(51, $w, 'a quarter wider than the can at most');
        $this->assertEqualsWithDelta(160, $y + $h, 2, 'standing where the can stood');
        $this->assertEqualsWithDelta(100, $x + $w / 2, 3, 'centred on the can');
    }

    public function test_make_mine_similar_or_from_a_video_attaches_the_template_as_the_reference(): void
    {
        [$conversation, $revision] = $this->version();
        $t = $this->library->add($this->staff, $conversation, $revision, ['title' => 'Sneaker drop', 'category' => 'offer']);
        app(CreateStorage::class)->put('create/previews/'.DB::table('composition_revisions')->where('id', $revision)->value('run_id').'/v.mp4',
            "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64), ['visibility' => 'private']);
        $made = $this->library->use($this->staff, $t['id'], ['product' => 'Volt Rush', 'match' => 'similar', 'idempotency_key' => 's1']);
        $c = DB::table('create_conversations')->where('id', $made['id'])->first();
        $this->assertNull($c->head_revision_id, 'similar makes a new video');
        $ref = DB::table('create_attachments')->where('conversation_id', $made['id'])->first();
        $this->assertSame('reference', $ref->purpose);
        $this->assertSame($t['id'], Asset::find($ref->asset_id)->metadata_json['create_template_id']);
        $this->assertStringContainsString('Keep its format, look and pacing', $made['brief']);
        // An example (a video only) is a reference even for "exactly".
        Http::fake(['*/marketing/samples/weave/b01.mp4' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64))]);
        $example = $this->library->use($this->staff, 'b01', ['product' => 'Volt Rush', 'match' => 'exact', 'idempotency_key' => 'e1']);
        $this->assertSame('reference', DB::table('create_attachments')->where('conversation_id', $example['id'])->value('purpose'));
        $this->assertStringContainsString('following it exactly', $example['brief']);
    }

    public function test_write_it_for_me_drafts_the_listing_from_the_version_for_staff_only(): void
    {
        [$conversation, $revision] = $this->version();
        config(['services.anthropic.key' => 'k']);
        $sent = null; $calls = 0;
        Http::fake(['api.anthropic.com/*' => function ($request) use (&$sent, &$calls) {
            $sent = $request->data();
            if (++$calls > 1) return Http::response(['content' => [['type' => 'text', 'text' => '{"title":"X","category":"cooking","description":"D","ask_hint":"H"}']]]);
            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['title' => 'Sneaker drop with an offer', 'category' => 'offer',
                'description' => 'Bold blocks slam in: your product on a card, three reasons, the offer in a burst.', 'ask_hint' => 'A link, or a few words about it. Your offer too. e.g. yourshop.com/x, 20% off'])]]]);
        }]);
        $s = $this->library->suggest($this->staff, $conversation, $revision);
        $this->assertSame(['Sneaker drop with an offer', 'offer'], [$s['title'], $s['category']]);
        $this->assertStringStartsWith('A link', $s['ask_hint']);
        $prompt = $sent['messages'][0]['content'];
        $this->assertStringContainsString('Hook: NEW DROP', $prompt, 'it reads the version\'s parts');
        $this->assertStringContainsString('Ridge 2 shoe, side profile', $prompt);
        $this->rejected(403, fn () => $this->library->suggest($this->customer, $conversation, $revision));
        $this->assertArrayNotHasKey('category', $this->library->suggest($this->staff, $conversation, $revision), 'an unknown category is dropped');
    }

    public function test_a_change_that_only_swaps_parts_is_made_without_the_builder(): void
    {
        [$conversation, $revision, $files] = $this->version();
        $photo = Asset::create(['workspace_id' => $this->staff->workspace_id, 'asset_type' => 'image', 'title' => 'Volt Rush can', 'storage_url' => 'create-upload://x/p',
            'mime_type' => 'image/png', 'file_size_bytes' => 4, 'status' => 'active', 'restriction_scope' => 'workspace']);
        $base = DB::table('composition_revisions')->where('id', $revision)->first();
        $pictures = \App\Services\Create\PartSwap::pictures(json_decode($base->bundle_json, true));
        $this->assertSame([['src' => $files[0]['name'], 'asset_id' => $files[0]['asset_id'], 'element' => 'heroshoe']], $pictures);
        $described = \App\Services\Create\PartSwap::described($pictures, (int) $this->staff->workspace_id);
        $this->assertSame('Ridge 2 shoe, side profile', $described[0]['what'], 'the plan item that made the picture says what it shows');
        $ctx = ['current_variables' => [['id' => 'headline', 'type' => 'string', 'label' => 'Hook', 'current' => 'NEW DROP'], ['id' => 'color_background', 'type' => 'color', 'label' => 'Field', 'current' => '#FFE14D']],
            'current_pictures' => $described, 'files' => [['asset_id' => $photo->id, 'asset_type' => 'image', 'purpose' => 'source']]];
        $raw = ['values' => ['headline' => 'VOLT RUSH', 'color_background' => '#D7FF3A', 'nope' => 'x'], 'pictures' => [['from' => $files[0]['name'], 'to_asset_id' => $photo->id], ['from' => 'asset-9-x.png', 'to_asset_id' => $photo->id]]];
        $swap = \App\Services\Create\PartSwap::normalize($raw, $ctx, ['on_screen_words', 'pictures']);
        $this->assertSame(['headline' => 'VOLT RUSH', 'color_background' => '#d7ff3a'], $swap['values']);
        $this->assertSame([['from' => $files[0]['name'], 'to_asset_id' => $photo->id]], $swap['pictures'], 'only pictures the version has, filled with attached images');
        $this->assertFalse($swap['voice']);
        $this->assertNull(\App\Services\Create\PartSwap::normalize($raw, $ctx, ['on_screen_words', 'pictures', 'timing']), 'timing needs the builder');
        $this->assertNull(\App\Services\Create\PartSwap::normalize([...$raw, 'values' => ['headline' => 'Freelance money, finally sorted?']], $ctx, ['on_screen_words', 'pictures']), 'text longer than its slot needs the builder');
        $this->assertSame([12, 4, 29, 24, 10, 10], [\App\Services\Create\PartSwap::maxChars('NEW DROP'), \App\Services\Create\PartSwap::maxChars('Go'),
            \App\Services\Create\PartSwap::maxChars('or saffronstreet.co'), \App\Services\Create\PartSwap::maxChars('fieldwell.co'),
            \App\Services\Create\PartSwap::maxChars('$45'), \App\Services\Create\PartSwap::maxChars('20%')],
            'words that wrap half as long again, one word twice (beelitecandles.com where fieldwell.co stood), a price any currency (Rs 2,700 where $45 stood) (SWAP-SAFE)');
        $this->assertTrue(\App\Services\Create\PartSwap::isNumber('Rs 2,700') === false && \App\Services\Create\PartSwap::isNumber('$45') && \App\Services\Create\PartSwap::isNumber('£32'));
        $this->assertNull(\App\Services\Create\PartSwap::normalize(['values' => ['headline' => 'X']], $ctx, ['on_screen_words', 'pictures']), 'a picture change with no picture to put in is not a swap');
        $this->assertNull(\App\Services\Create\PartSwap::normalize(['pictures' => [['from' => $files[0]['name'], 'to_asset_id' => 999]]], $ctx, ['pictures']), 'an image the user did not attach is not used');

        // The quote bakes the values and the photo into the version: nothing to buy, no builder.
        $c = DB::table('create_conversations')->where('id', $conversation)->first();
        $plan = ['planner_task' => 'edit', 'change_touches' => ['on_screen_words', 'pictures'], 'swap' => $swap];
        $captured = [['asset_id' => $photo->id, 'name' => 'asset-'.$photo->id.'-'.str_repeat('c', 64).'.png', 'asset_type' => 'image', 'purpose' => 'source']];
        $q = \App\Services\Create\PartSwap::forQuote($c, $base, $plan, [], ['output_kind' => 'video'], false, $captured);
        $this->assertTrue($q['free']);
        $html = $q['bundle']['index.html'];
        $this->assertStringContainsString($captured[0]['name'], $html);
        $this->assertStringNotContainsString($files[0]['name'], $html);
        $this->assertSame('VOLT RUSH', collect(\App\Services\Create\CompositionVariables::declarations($html))->firstWhere('id', 'headline')['default']);
        $this->assertStringContainsString($files[1]['name'], $html, 'the voice is kept');
        $this->assertNull(\App\Services\Create\PartSwap::forQuote($c, $base, $plan, [['kind' => 'music']], ['output_kind' => 'video'], false, $captured), 'buying anything but a voice is a build');
        $this->assertNull(\App\Services\Create\PartSwap::forQuote($c, $base, [...$plan, 'swap' => [...$swap, 'voice' => true], 'change_touches' => ['voice', 'pictures']], [], ['output_kind' => 'video'], false, $captured), 'a new voice must be in the plan');
        $this->assertNull(\App\Services\Create\PartSwap::forQuote($c, $base, $plan, [], ['output_kind' => 'video'], false, []), 'the photo must be among the run\'s files');
        $this->assertNull(\App\Services\Create\PartSwap::forQuote($c, null, $plan, [], ['output_kind' => 'video'], false, $captured), 'a first video is built');
        $this->assertNotNull(\App\Services\Create\PartSwap::forQuote($c, $base, [...$plan, 'planner_task' => 'creative'], [], ['output_kind' => 'video'], false, $captured), 'a template conversation\'s first plan is "creative" planning, still a swap');

        // A free swap runs on its own like any small job; a plain free edit keeps its button.
        $quote = \App\Models\ApiQuote::create(['id' => \App\Models\ApiQuote::newId(), 'workspace_id' => $this->staff->workspace_id, 'created_by_user_id' => $this->staff->id,
            'credits_min' => 0, 'credits_max' => 0, 'expires_at' => now()->addMinutes(10), 'payload_json' => ['kind' => 'composition_fixture', 'mode' => 'agent', 'free_edit' => true, 'part_swap' => ['pictures' => $swap['pictures']]]]);
        $conversations = app(\App\Services\Create\ConversationService::class);
        $this->assertTrue($conversations->autoRunEligible($this->staff, $c, $quote));
        $quote->payload_json = ['kind' => 'composition_fixture', 'mode' => 'agent', 'free_edit' => true];
        $this->assertFalse($conversations->autoRunEligible($this->staff, $c, $quote));
    }

    public function test_a_version_moves_between_installs_and_becomes_a_templates_source(): void
    {
        // TPL-LOCAL (2026-10-16): examples built locally are exported with their code and media and imported on prod.
        [, $revision, $files] = $this->version();
        $dir = sys_get_temp_dir().'/vx-'.Str::random(6);
        $transfer = app(\App\Services\Create\VersionTransfer::class);
        $out = $transfer->export($revision, $dir);
        $this->assertSame([2, 13, true], [$out['files'], $out['bytes'], $out['video']]);
        $this->rejected(403, fn () => $transfer->import($dir, $this->customer, 'b01'));
        $in = $transfer->import($dir, $this->staff, 'b01');
        $this->assertSame(2, $in['files']);
        $t = DB::table('create_templates')->find('b01');
        $this->assertSame(['version', $in['revision_id'], 'weave'], [$t->source, $t->revision_id, $t->sample_folder], 'the example keeps its public poster and video');
        $this->assertContains('1 product picture', $in['template']['summary']);
        $head = DB::table('composition_revisions')->find($in['revision_id']);
        $html = json_decode($head->bundle_json, true)['index.html'];
        $this->assertStringNotContainsString($files[0]['name'], $html, 'the code names the imported copies');
        $parts = json_decode($t->parts_json, true);
        $this->assertSame('Ridge 2 shoe, side profile', $parts['images'][0]['what'], 'what each picture shows came along');
        // Exactly on that template now starts from the imported code.
        $made = $this->library->use($this->staff, 'b01', ['product' => 'Volt Rush', 'match' => 'exact', 'idempotency_key' => 'x1']);
        $this->assertSame(1, (int) DB::table('composition_revisions')->where('conversation_id', $made['id'])->value('number'));
        $this->assertStringContainsString('Follow it exactly', $made['brief']);
    }

    public function test_a_launch_vault_limits_which_templates_can_be_used(): void
    {
        config(['create.templates_public' => true]);
        $credits = \Mockery::mock(\App\Services\CreditService::class)->makePartial();
        $credits->shouldReceive('limitFor')->andReturnUsing(fn ($ws, $key) => $key === 'sample_vault' && (int) $ws === (int) $this->customer->workspace_id ? 10 : null);
        $this->app->instance(\App\Services\CreditService::class, $credits);
        $page = $this->library->page($this->customer)['templates'];
        $this->assertSame([10, 22], [count(array_filter($page, fn ($t) => ! $t['locked'])), count(array_filter($page, fn ($t) => $t['locked']))]);
        $this->assertCount(10, SampleLibrary::catalogueFor($this->customer));
        $this->rejected(403, fn () => $this->library->use($this->customer, $page[20]['id'], ['product' => 'x', 'idempotency_key' => 'v1']));
    }
}
