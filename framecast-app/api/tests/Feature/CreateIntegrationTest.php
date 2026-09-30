<?php

namespace Tests\Feature;

use App\Models\{ApiQuote, Asset, User, Workspace};
use App\Services\Create\{ConversationService, RunService};
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

class CreateIntegrationTest extends TestCase
{
    use BuildsDeveloperSchema;
    private User $owner;
    private Workspace $workspace;
    private ConversationService $conversations;
    private RunService $runs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'create_test', 'database.connections.create_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array', 'services.posthog.key' => '', 'create.enabled' => true, 'create.mode' => 'fixture', 'developer.operation_accounting' => true]);
        DB::purge('create_test');
        Bus::fake(); Http::preventStrayRequests(); Redis::shouldReceive('get')->andReturn(null);
        $this->buildDeveloperSchema();
        (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
        (require database_path('migrations/2026_09_28_120000_create_composition_conversations.php'))->up();
        (require database_path('migrations/2026_09_29_000000_create_composition_attempts.php'))->up();
        (require database_path('migrations/2026_09_29_120000_link_composition_outputs.php'))->up();
        (require database_path('migrations/2026_09_29_130000_create_composition_reconciliations.php'))->up();
        (require database_path('migrations/2026_09_29_180000_add_create_output_metadata.php'))->up();
        (require database_path('migrations/2026_09_29_190000_create_composition_deliveries.php'))->up();
        (require database_path('migrations/2026_09_30_120000_create_create_plans.php'))->up();
        (require database_path('migrations/2026_09_30_130000_add_create_provider_consent.php'))->up();
        $this->workspace = Workspace::create(['name' => 'Local', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $this->owner = User::create(['email' => 'local@example.test', 'name' => 'Local', 'role' => 'owner', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $this->workspace->id])->save();
        config(['create.workspaces' => [(int) $this->workspace->id]]);
        $this->conversations = app(ConversationService::class); $this->runs = app(RunService::class);
    }

    public function test_brief_wording_sets_supported_settings_and_asks_about_unsupported_ones(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Make a square 20 second product video in French.', 'expected_version' => 0, 'idempotency_key' => 'm-1']);
        $c = $this->conversations->conversation($this->owner, $c->id);
        $settings = json_decode($c->settings_json, true);
        $this->assertSame(['1:1', 20, 'fr'], [$settings['aspect_ratio'], $settings['duration_seconds'], $settings['language']]);
        $rows = DB::table('create_messages')->where('conversation_id', $c->id)->orderBy('sequence')->get(['role', 'sequence', 'content']);
        $this->assertSame(['user', 'assistant'], $rows->pluck('role')->all());
        $this->assertSame([1, 2], $rows->pluck('sequence')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, (int) $c->version, 'each reply advances the version so an older quote is invalidated');
        $this->assertStringContainsString('square (1:1)', $rows[1]->content);

        // Unsupported asks are questions, not silent approximations, and change nothing.
        $this->conversations->message($this->owner, $c->id, ['content' => 'Actually make it 90 seconds in Swahili.', 'expected_version' => 2, 'idempotency_key' => 'm-2']);
        $c = $this->conversations->conversation($this->owner, $c->id);
        $this->assertSame(20, json_decode($c->settings_json, true)['duration_seconds']);
        $this->assertSame('fr', json_decode($c->settings_json, true)['language']);
        $asks = DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'assistant')->where('sequence', '>', 2)->pluck('content');
        $this->assertCount(2, $asks);
        $this->assertStringContainsString('5 to 30 seconds', $asks[0]);
        $this->assertStringContainsString('Swahili', $asks[1]);
        $this->assertSame(5, (int) $c->version);

        // Replaying the first message returns it unchanged and adds nothing.
        $again = $this->conversations->message($this->owner, $c->id, ['content' => 'Make a square 20 second product video in French.', 'expected_version' => 0, 'idempotency_key' => 'm-1']);
        $this->assertSame('user', $again->role);
        $this->assertSame(5, DB::table('create_messages')->where('conversation_id', $c->id)->count());
    }

    public function test_offline_plan_is_a_free_assistant_turn_with_editable_selections(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Launch video with callouts "Sit-stand in 8 seconds" and "Holds two monitors".', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $v = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, $v, 'plan-1');
        $this->assertSame('offline-planner-v1', $p['provider']);
        $this->assertSame(['Sit-stand in 8 seconds', 'Holds two monitors'], $p['plan']['callouts']);
        $this->assertSame('type_on', $p['plan']['selections']['choices']['opening']);
        $this->assertFalse($p['stale']);
        $msg = DB::table('create_messages')->where('id', $p['message_id'])->first();
        $this->assertSame('assistant', $msg->role);
        $this->assertSame($v + 1, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame($p['id'], $plans->propose($this->owner, $c->id, $v, 'plan-1')['id'], 'same key replays');
        $this->assertSame(0, (int) DB::table('api_operations')->count(), 'planning reserves nothing');

        $v++;
        $edited = $plans->select($this->owner, $c->id, $p['id'], $v, ['callouts' => ['Sit-stand in 8 seconds', '', 'Assembles in 15 minutes'], 'choices' => ['opening' => 'reveal']]);
        $this->assertSame(['Sit-stand in 8 seconds', 'Assembles in 15 minutes'], $edited['plan']['selections']['callouts']);
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], $v + 1, ['choices' => ['opening' => 'fireworks']]));
        $this->rejected(409, fn () => $plans->select($this->owner, $c->id, $p['id'], $v, ['choices' => ['opening' => 'type_on']]));

        // The quote carries the approved plan.
        $q = $this->conversations->quote($this->owner, $c->id, $v + 1);
        $this->assertSame(['Sit-stand in 8 seconds', 'Assembles in 15 minutes'], $q->payload_json['plan']['on_screen_copy']);
        $this->assertSame('Slow reveal', $q->payload_json['plan']['choices'][0]['chosen']);

        // A new brief makes the plan stale: it is no longer quoted or editable.
        $this->conversations->message($this->owner, $c->id, ['content' => 'Actually keep it calm.', 'expected_version' => $v + 1, 'idempotency_key' => 'b2']);
        $cNow = $this->conversations->conversation($this->owner, $c->id);
        $this->assertNull(\App\Services\Create\PlanService::forQuote($cNow));
        // Planning again keeps the user's edited copy.
        $again = $plans->propose($this->owner, $c->id, (int) $cNow->version, 'plan-2');
        $this->assertSame(['Sit-stand in 8 seconds', 'Assembles in 15 minutes'], $again['plan']['callouts']);
        $cNow = $this->conversations->conversation($this->owner, $c->id);
        $this->rejected(409, fn () => $plans->select($this->owner, $c->id, $p['id'], (int) $cNow->version, ['choices' => ['opening' => 'type_on']]));
    }

    public function test_model_plan_is_normalised_and_priced_by_the_catalogue(): void
    {
        config(['create.mode' => 'agent', 'create.planner' => 'anthropic', 'create.planner_model' => 'claude-opus-5-5', 'services.anthropic.key' => 'test-key']);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'take.mp4', 'status' => 'active', 'storage_url' => 'https://b2/take.mp4']);
        $foreign = 999999;
        $reply = ['summary' => 'I will open on your take and punch in on the product.',
            'reused' => [['asset_id' => $asset->id, 'use' => 'First 4 seconds'], ['asset_id' => $foreign, 'use' => 'not yours']],
            'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 4, 'idea' => 'Take'], ['label' => 'Too long', 'start' => 10, 'end' => 90, 'idea' => 'clamped'], ['label' => 'Bad', 'start' => 5, 'end' => 5]],
            'callouts' => ['Holds two monitors'],
            'decisions' => [['id' => 'Intro Style!', 'question' => 'Energetic intro?', 'options' => [
                ['id' => 'punch', 'label' => 'Punch-in', 'detail' => 'Included', 'kind' => 'included', 'tool' => null],
                ['id' => 'gen', 'label' => 'Generated motion', 'detail' => 'New clip', 'kind' => 'media', 'tool' => 'animate_image', 'credits' => 1],
            ]], ['id' => 'lonely', 'question' => 'One option only', 'options' => [['id' => 'a', 'label' => 'A']]]],
            'kept_as_is' => ['take.mp4 audio'],
            'media' => [['kind' => 'ai_image', 'description' => 'Studio background', 'credits' => 1], ['kind' => 'launch_rocket', 'description' => 'not a tool']],
            'left_out' => 'No price was given.'];
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'content' => [['type' => 'text', 'text' => 'Here you go: '.json_encode($reply)]],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 400, 'cache_read_input_tokens' => 900]])]);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'source', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Energetic launch.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        $p = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, 2, 'p1');
        $plan = $p['plan'];
        $this->assertSame('anthropic:claude-opus-5-5', $p['provider']);
        $this->assertSame([$asset->id], array_column($plan['reused'], 'asset_id'), 'only this conversation\'s source files');
        $this->assertEquals([['label' => 'Hook', 'start' => 0.0, 'end' => 4.0, 'idea' => 'Take'], ['label' => 'Too long', 'start' => 10.0, 'end' => 15.0, 'idea' => 'clamped']], $plan['scenes']);
        $this->assertCount(1, $plan['decisions'], 'a decision with one option is dropped');
        $this->assertSame('introstyle', $plan['decisions'][0]['id']);
        $gen = $plan['decisions'][0]['options'][1];
        $this->assertSame(\App\Services\CreditService::animationCost('quick', '480p', 5), $gen['credits'], 'price comes from the catalogue, not the model');
        $this->assertSame(['ai_image'], array_column($plan['media'], 'kind'), 'unknown tools are dropped');
        $this->assertSame(app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null), $plan['media'][0]['credits']);
        $this->assertSame(900, json_decode(DB::table('create_plans')->where('id', $p['id'])->value('usage_json'), true)['cache_read_tokens']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r['model'] === 'claude-opus-5-5'
            && $r['system'][0]['cache_control']['type'] === 'ephemeral' && $r->hasHeader('x-api-key', 'test-key'));
    }

    public function test_planning_is_limited_per_day_and_failures_cost_nothing(): void
    {
        $c = $this->brief(); $plans = app(\App\Services\Create\PlanService::class);
        config(['create.plan_daily_limit' => 1]);
        $plans->propose($this->owner, $c->id, 1, 'one');
        $this->rejected(429, fn () => $plans->propose($this->owner, $c->id, 2, 'two'));
        config(['create.plan_daily_limit' => 40, 'create.mode' => 'agent', 'create.planner' => 'replicate', 'services.replicate.api_token' => 't']);
        Http::fake(['api.replicate.com/*' => Http::response(['error' => 'down'], 500)]);
        $this->rejected(502, fn () => $plans->propose($this->owner, $c->id, 2, 'three'));
        $this->assertSame(1, DB::table('create_plans')->count());
        $viewer = User::create(['email' => 'viewer@example.test', 'name' => 'V', 'role' => 'viewer', 'status' => 'active']);
        $viewer->forceFill(['workspace_id' => $this->workspace->id])->save();
        $this->rejected(403, fn () => $plans->propose($viewer, $c->id, 2, 'four'));
    }

    public function test_free_edit_rerenders_variables_without_a_model_call_or_credits(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $html = '<!doctype html><html lang="en" data-composition-variables=\'[{"id":"headline","type":"string","label":"Headline","default":"Your product. Your story."},{"id":"cta","type":"string","label":"Button text","default":"Explore the collection"},{"id":"color_background","type":"color","label":"Background","default":"#17151d"},{"id":"color_accent","type":"color","label":"Accent","default":"#ff6b32"}]\'><head></head><body><div id="root" data-composition-id="main"><div id="cta">Explore the collection</div></div></body></html>';
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => $html]], 'private/v1.mp4', 'hash');
        $c = $this->conversations->conversation($this->owner, $c->id);
        $rev = DB::table('composition_revisions')->first();
        $decl = \App\Services\Create\CompositionVariables::declarations($html);
        $this->assertSame(['headline', 'cta', 'color_background', 'color_accent'], array_column($decl, 'id'));

        $edits = app(\App\Services\Create\FreeEditService::class);
        $this->rejected(422, fn () => $edits->apply($this->owner, $c->id, $rev->id, ['price' => '$9'], (int) $c->version, 'x1'));
        $this->rejected(422, fn () => $edits->apply($this->owner, $c->id, $rev->id, ['color_accent' => 'orange'], (int) $c->version, 'x2'));
        $this->rejected(422, fn () => $edits->apply($this->owner, $c->id, $rev->id, ['cta' => 'Explore the collection'], (int) $c->version, 'x3'));

        $edit = $edits->apply($this->owner, $c->id, $rev->id, ['cta' => 'Get 20% off', 'color_accent' => '#22AA66'], (int) $c->version, 'free-1');
        $this->assertSame($edit->id, $edits->apply($this->owner, $c->id, $rev->id, ['cta' => 'Get 20% off'], (int) $c->version, 'free-1')->id, 'same key replays');
        $input = json_decode($edit->input_json, true);
        $this->assertTrue($input['free_edit']);
        $this->assertSame(['render'], array_keys($input['execution_policy']), 'no agent, no media');
        $this->assertSame(0, (int) ApiQuote::find($edit->quote_id)->credits_max);
        $this->assertSame(['cta' => 'Get 20% off', 'color_accent' => '#22aa66'], $input['edit_values']);
        $baked = \App\Services\Create\CompositionVariables::declarations($input['base_bundle']['index.html']);
        $this->assertSame('Get 20% off', collect($baked)->firstWhere('id', 'cta')['default']);
        $this->assertSame('#22aa66', collect($baked)->firstWhere('id', 'color_accent')['default']);
        $this->assertSame('Your product. Your story.', collect($baked)->firstWhere('id', 'headline')['default']);
        $this->assertSame($rev->id, $input['source_revision_id']);
        $this->assertSame(0, (int) DB::table('api_operations')->where('id', $edit->operation_id)->value('reserved_credits'));

        // Finishing it makes version 2 current, and the version list exposes its fields.
        $lease = $this->runs->claim();
        $this->runs->finish($edit->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Free', 'bundle' => $input['base_bundle']], 'private/v2.mp4', 'hash2');
        $head = $this->conversations->conversation($this->owner, $c->id)->head_revision_id;
        $this->assertSame(2, (int) DB::table('composition_revisions')->where('id', $head)->value('number'));
        config(['create.free_edit_daily_limit' => 1]);
        $this->rejected(429, fn () => $edits->apply($this->owner, $c->id, $head, ['cta' => 'Shop now'], (int) $this->conversations->conversation($this->owner, $c->id)->version, 'free-2'));
    }

    public function test_small_jobs_auto_run_only_under_the_threshold_and_after_consent(): void
    {
        $c = $this->brief();
        $q = $this->conversations->quote($this->owner, $c->id, 1);
        $this->assertTrue($this->conversations->autoRunEligible($this->owner, $c, $q), 'a free fixture render is a small job');
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'auto-1', false, true);
        $this->assertTrue(json_decode($run->input_json, true)['auto_run']);

        // Over the threshold, or in paid mode without consent, it needs approval.
        $big = ApiQuote::find($q->id)->replicate(); $big->id = ApiQuote::newId(); $big->credits_max = 16; $big->consumed_at = null; $big->idempotency_key = null; $big->save();
        $this->assertFalse($this->conversations->autoRunEligible($this->owner, $c, $big));
        $paid = ApiQuote::find($q->id)->replicate(); $paid->id = ApiQuote::newId(); $paid->credits_max = 10; $paid->consumed_at = null; $paid->idempotency_key = null;
        $paid->payload_json = array_merge($q->payload_json, ['mode' => 'agent']); $paid->save();
        $this->assertFalse($this->conversations->autoRunEligible($this->owner, $c, $paid), 'no provider consent yet');
        DB::table('create_conversations')->where('id', $c->id)->update(['provider_consent_at' => now()]);
        $this->assertTrue($this->conversations->autoRunEligible($this->owner, $this->conversations->conversation($this->owner, $c->id), $paid));
        config(['create.auto_run_daily_limit' => 1]);
        $this->assertFalse($this->conversations->autoRunEligible($this->owner, $this->conversations->conversation($this->owner, $c->id), $paid), 'daily auto-run ceiling');
    }

    public function test_derived_media_is_stored_with_provenance_and_inherited_by_later_runs(): void
    {
        $c = $this->brief(); $source = $this->imageAttachment($c, 'source');
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'derive-1');
        $lease = $this->runs->claim()['lease_token'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6zd8AAAAASUVORK5CYII=');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('derived-1-grade.png', $png);

        $this->rejected(422, fn () => $this->runs->derived($run->id, $lease, $file, $source->id, 'rm_rf', []));
        $this->rejected(422, fn () => $this->runs->derived($run->id, $lease, $file, $source->id + 999, 'grade', []), );
        $this->rejected(403, fn () => $this->runs->derived($run->id, str_repeat('x', 64), $file, $source->id, 'grade', []));
        $bad = \Illuminate\Http\UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;');
        $this->rejected(422, fn () => $this->runs->derived($run->id, $lease, $bad, $source->id, 'grade', []));

        $record = $this->runs->derived($run->id, $lease, $file, $source->id, 'grade', ['look' => 'warm']);
        $this->assertSame('source', $record['purpose']);
        $this->assertMatchesRegularExpression('/^asset-\d+-[a-f0-9]{64}\.png$/', $record['name']);
        $this->assertSame($record, $this->runs->derived($run->id, $lease, $file, $source->id, 'grade', ['look' => 'warm']), 'same bytes replay');
        $asset = Asset::find($record['asset_id']);
        $this->assertSame((int) $this->workspace->id, (int) $asset->workspace_id);
        $this->assertStringStartsWith('create-upload://', $asset->storage_url);
        $this->assertSame(['derived_from_asset_id' => $source->id, 'operation' => 'grade', 'params' => ['look' => 'warm']],
            array_intersect_key($asset->metadata_json, array_flip(['derived_from_asset_id', 'operation', 'params'])));
        $this->assertSame($record['sha256'], $this->runs->inputFile($run->id, $lease, $record['asset_id'])['sha256'], 'the worker can re-download it');

        // Finish; the next run inherits the derived file alongside the original source.
        $this->runs->finish($run->id, $lease, ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<img src="'.$record['name'].'">']], 'private/v1.mp4', 'h');
        $head = $this->conversations->conversation($this->owner, $c->id)->head_revision_id;
        $inherited = app(\App\Services\Create\InputSnapshotService::class)->inherited($c->id, $head);
        $this->assertEqualsCanonicalizing([$source->id, $record['asset_id']], array_column($inherited, 'asset_id'));
    }

    public function test_transcript_is_word_timed_cached_by_bytes_and_never_a_placeholder(): void
    {
        $c = $this->brief(); $source = $this->imageAttachment($c, 'source');
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'transcript-1');
        $lease = $this->runs->claim()['lease_token'];
        $samples = str_repeat("\0\0", 800);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $audio = $this->runs->derived($run->id, $lease, \Illuminate\Http\UploadedFile::fake()->createWithContent('derived-1-clean_audio.wav', $wav), $source->id, 'clean_audio', []);
        config(['services.openai.api_key' => 'test-openai', 'create.transcript_daily_limit' => 30]);
        Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::sequence()
            ->push(['text' => 'Save twenty percent', 'words' => [['word' => 'Save', 'start' => 0.1, 'end' => 0.4], ['word' => 'twenty', 'start' => 0.4, 'end' => 0.8], ['word' => 'percent', 'start' => 0.8, 'end' => 1.25]],
                'segments' => [['text' => 'Save twenty percent', 'start' => 0.1, 'end' => 1.25]]])]);
        $service = app(\App\Services\Create\TranscriptService::class);
        $this->rejected(422, fn () => $service->forRun($run->id, $lease, $source->id), );
        $t = $service->forRun($run->id, $lease, $audio['asset_id']);
        $this->assertSame([['text' => 'Save', 'start' => 0.1, 'end' => 0.4], ['text' => 'twenty', 'start' => 0.4, 'end' => 0.8], ['text' => 'percent', 'start' => 0.8, 'end' => 1.25]], $t['words']);
        $this->assertFalse($t['cached']);
        // Same bytes: served from the asset, no second provider call.
        $this->assertTrue($service->forRun($run->id, $lease, $audio['asset_id'])['cached']);
        Http::assertSentCount(1);
        $this->assertSame('Save twenty percent', Asset::find($audio['asset_id'])->transcript_text);
        // A provider failure yields the media service's placeholder; that must not pass as speech.
        $meta = Asset::find($audio['asset_id'])->metadata_json; unset($meta['create_transcript']);
        Asset::whereKey($audio['asset_id'])->update(['metadata_json' => json_encode($meta)]);
        Http::fake(['https://api.openai.com/*' => Http::response(['error' => 'down'], 500)]);
        $this->rejected(503, fn () => $service->forRun($run->id, $lease, $audio['asset_id']));
        config(['create.transcript_daily_limit' => 2]);
        $this->rejected(429, fn () => $service->forRun($run->id, $lease, $audio['asset_id']));
        $this->rejected(403, fn () => $service->forRun($run->id, str_repeat('x', 64), $audio['asset_id']));
        $this->assertContains('transcript', array_column(\App\Services\Create\CapabilityCatalogue::forWorkspace($this->workspace->id), 'kind'));
    }

    public function test_reference_link_is_fetched_privately_studied_and_never_renderable(): void
    {
        $c = $this->brief();
        $service = app(\App\Services\Create\References\ReferenceLinkService::class);
        $this->rejected(422, fn () => $service->add($this->owner, $c->id, 'http://x.com/a/status/1', 1, 'r0'));
        $this->rejected(422, fn () => $service->add($this->owner, $c->id, 'https://evil.example/video.mp4', 1, 'r0'));
        $this->rejected(422, fn () => $service->add($this->owner, $c->id, 'https://user:pw@x.com/a/status/1', 1, 'r0'));
        $this->assertSame(['x', 'https://x.com/devteamdrew/status/2102436464323661880'], $service->validate('https://x.com/devteamdrew/status/2102436464323661880/history'));
        $this->assertSame(['youtube', 'https://www.youtube.com/watch?v=abcdEFG123'], $service->validate('https://www.youtube.com/watch?v=abcdEFG123&si=track&list=x'));

        $mp4 = file_get_contents(base_path('tests/Fixtures/create/tiny.mp4'));
        $calls = [];
        \Illuminate\Support\Facades\Process::fake(function ($process) use (&$calls, $mp4) {
            $cmd = $process->command; $calls[] = $cmd;
            if ($cmd[0] === 'yt-dlp' && in_array('-J', $cmd, true)) return \Illuminate\Support\Facades\Process::result(json_encode(['title' => 'Made with Opus', 'uploader' => 'DreW', 'duration' => 31.9, 'is_live' => false]));
            if ($cmd[0] === 'yt-dlp') { $o = $cmd[array_search('-o', $cmd, true) + 1]; file_put_contents(str_replace('%(ext)s', 'mp4', $o), $mp4); return \Illuminate\Support\Facades\Process::result(''); }
            if ($cmd[0] === 'ffprobe') return \Illuminate\Support\Facades\Process::result(json_encode(['streams' => [['codec_type' => 'video', 'width' => 1280, 'height' => 720]]]));
            if ($cmd[0] === 'ffmpeg' && str_contains(implode(' ', $cmd), 'scene')) return \Illuminate\Support\Facades\Process::result('', "frame:1 pts:1 pts_time:4.2\nframe:2 pts:2 pts_time:8.9\n");
            if ($cmd[0] === 'ffmpeg') { file_put_contents(end($cmd), 'jpg'); return \Illuminate\Support\Facades\Process::result(''); }
            return \Illuminate\Support\Facades\Process::result('', 'unexpected', 1);
        });
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.agent_model' => 'claude-opus-5-5']);
        Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"summary":"Hand-drawn vignettes cut on the beat.","look":"Paper texture, flat shapes","palette":["#F2EDE4","#3A2D6B","bad"],"type":"none","motion":"Springy","structure":"Cold open, montage, end card","borrow":["Hold each idea 3 s"],"avoid_copying":["The orange box character"]}']], 'usage' => ['input_tokens' => 1500, 'output_tokens' => 200]])]);

        $v = $this->conversations->conversation($this->owner, $c->id)->version;
        $asset = $service->add($this->owner, $c->id, 'https://x.com/devteamdrew/status/2102436464323661880/history', (int) $v, 'ref-1');
        $this->assertSame('reference', DB::table('create_attachments')->where('asset_id', $asset->id)->value('purpose'));
        $this->assertStringStartsWith('create-upload://', $asset->storage_url);
        $this->assertSame('X · DreW', $asset->title);
        $a = $asset->metadata_json['reference_analysis'];
        $this->assertSame([[4.2, 8.9], 3], [$a['cuts'], $a['shots']]);
        $this->assertSame(['#F2EDE4', '#3A2D6B'], $a['notes']['palette'], 'invalid colours are dropped');
        $this->assertContains('--ignore-config', $calls[0]);
        // Replay with the same key returns the same reference without fetching again.
        $n = count($calls);
        $this->assertSame($asset->id, $service->add($this->owner, $c->id, 'https://x.com/devteamdrew/status/2102436464323661880/history', (int) $v, 'ref-1')->id);
        $this->assertCount($n, $calls);
        // The planner and the run snapshot both carry the notes; the reference is never a source.
        $brief = \App\Services\Create\PlanService::referenceBrief($asset);
        $this->assertSame('Hand-drawn vignettes cut on the beat.', $brief['notes']['summary']);
        // A post longer than 5 minutes is refused before download.
        \Illuminate\Support\Facades\Process::fake(fn () => \Illuminate\Support\Facades\Process::result(json_encode(['duration' => 900])));
        $this->rejected(422, fn () => $service->add($this->owner, $c->id, 'https://www.tiktok.com/@a/video/1', (int) $this->conversations->conversation($this->owner, $c->id)->version, 'ref-2'));
    }

    private function brief(): object
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Keep my source audio.', 'expected_version' => 0, 'idempotency_key' => 'message-1']);
        return $this->conversations->conversation($this->owner, $c->id);
    }

    private function admitted(): array
    {
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-'.$c->id);
        return [$c, $q, $run];
    }

    private function rejected(int $status, callable $fn): void
    {
        try { $fn(); $this->fail('Expected HTTP '.$status); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    private function registeredOutput(): array
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $bytes = 'offline encoded video fixture'; $hash = hash('sha256',$bytes);
        $path = 'create/previews/'.$run->id.'/'.$hash.'.mp4';
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path,$bytes);
        $this->runs->finish($run->id,$claim['lease_token'],['status'=>'preview_ready','summary'=>'Test','bundle'=>['index.html'=>'<h1>Test</h1>']],$path,$hash);
        $revision = DB::table('composition_revisions')->value('id');
        $output = app(\App\Services\Create\CompositionOutputService::class)->register($this->owner,$c->id,$revision,2);
        return [$c,$revision,$output];
    }

    public function test_composition_registration_is_private_idempotent_and_scene_safe(): void
    {
        [$c,$revision,$output] = $this->registeredOutput();
        $again = app(\App\Services\Create\CompositionOutputService::class)->register($this->owner,$c->id,$revision,2);
        $this->assertSame($output,$again); $this->assertSame(1,\App\Models\ExportJob::count());
        $project = \App\Models\Project::findOrFail($output['project_id']);
        $this->assertTrue($project->isComposition()); $this->assertFalse($project->usesAutomaticFinish());
        $this->assertSame(0,\App\Models\Scene::count());
        $asset = Asset::findOrFail($output['asset_id']); $storage = app(\App\Services\Media\StorageService::class);
        $this->assertTrue($storage->isCreatePrivate($asset->storage_url));
        $this->assertSame('offline encoded video fixture',$storage->get($asset->storage_url));
        $this->assertFalse($storage->delete($asset->storage_url));
        $this->assertTrue($storage->exists($asset->storage_url));
        $url = $storage->url($asset->storage_url);
        $this->get($url)->assertOk()->assertHeader('Content-Type','video/mp4');
        $this->get(preg_replace('/signature=[^&]+/','signature=bad',$url))->assertForbidden();
        $this->workspace->update(['status'=>'suspended']); $this->get($url)->assertNotFound();
    }

    public function test_registered_output_rechecks_write_access_and_signed_url_expiry(): void
    {
        [$c,$revision,$output] = $this->registeredOutput();
        $this->owner->role = 'viewer';
        $this->rejected(403,fn()=>app(\App\Services\Create\CompositionOutputService::class)->register($this->owner,$c->id,$revision,2));
        $asset = Asset::findOrFail($output['asset_id']);
        $url = app(\App\Services\Media\StorageService::class)->url($asset->storage_url);
        $this->travel(6)->minutes();
        try { $this->get($url)->assertForbidden(); } finally { $this->travelBack(); }
        $this->assertSame(1,\App\Models\ExportJob::count());
    }

    public function test_composition_export_freshness_tracks_restore_and_old_head(): void
    {
        [$c,$revision,$output]=$this->registeredOutput();
        $project=\App\Models\Project::findOrFail($output['project_id']);
        $export=\App\Models\ExportJob::findOrFail($output['export_job_id']);
        $service=app(\App\Services\Export\ExportFreshnessService::class);
        $this->assertFalse($service->check($project,$export)['is_stale']);
        $this->conversations->restore($this->owner,$c->id,$revision,2);
        $this->assertTrue($service->check($project,$export)['is_stale']);
        $this->rejected(409,fn()=>app(\App\Services\Create\CompositionOutputService::class)->register($this->owner,$c->id,$revision,3));
    }

    public function test_composition_mutations_and_free_unwatermarked_save_are_blocked(): void
    {
        [$c,$revision,$output]=$this->registeredOutput();
        $project=\App\Models\Project::findOrFail($output['project_id']);
        try { app(\App\Services\Export\ProjectExportService::class)->assertExportable($project); $this->fail('Scene export accepted'); }
        catch(\Illuminate\Http\Exceptions\HttpResponseException $e){$this->assertSame(422,$e->getResponse()->status());}
        $request=\Illuminate\Http\Request::create('/api/v1/scenes','POST',['project_id'=>$project->id]);
        $request->setUserResolver(fn()=>$this->owner);
        $result=app(\App\Http\Middleware\GuardCompositionAccess::class)->handle($request,fn()=>response('unsafe'));
        $this->assertSame(422,$result->status());
        $this->assertSame('unsupported_editor_kind',$result->getData(true)['error']['code']);
        $this->workspace->update(['plan_tier'=>'free']);
        $this->rejected(402,fn()=>app(\App\Services\Create\CompositionOutputService::class)->register($this->owner,$c->id,$revision,2));
    }

    private function uploadPng(string $name='product.png'): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII='));
    }

    public function test_create_upload_is_private_idempotent_and_never_dispatches_paid_jobs(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $c=$this->conversations->create($this->owner,[]);
        $service=app(\App\Services\Create\AttachmentUploadService::class);
        $asset=$service->upload($this->owner,$c->id,$this->uploadPng(),'reference','upload-1',0);
        $again=$service->upload($this->owner,$c->id,$this->uploadPng(),'reference','upload-1',0);
        $this->assertSame($asset->id,$again->id);
        $this->assertSame(1,Asset::count());$this->assertSame(1,DB::table('create_attachments')->count());
        $this->assertSame('not_requested',$asset->transcription_status);
        $this->assertStringStartsWith('create-upload://',$asset->storage_url);
        Bus::assertNothingDispatched();
        $storage=app(\App\Services\Media\StorageService::class);
        $this->get($storage->url($asset->storage_url))->assertOk()->assertHeader('Content-Type','image/png');
        $this->conversations->message($this->owner,$c->id,['content'=>'Use this for inspiration','expected_version'=>1,'idempotency_key'=>'brief']);
        $quote=$this->conversations->quote($this->owner,$c->id,2);
        $this->assertSame('reference',$quote->payload_json['input']['input_files'][0]['purpose'] ?? $quote->payload_json['input_files'][0]['purpose'] ?? 'missing');
        $this->assertSame(0,DB::table('credit_ledger')->count());
    }

    public function test_upload_validation_permissions_quota_and_rollback(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $c=$this->brief();$service=app(\App\Services\Create\AttachmentUploadService::class);
        $this->owner->role='viewer';$this->rejected(403,fn()=>$service->upload($this->owner,$c->id,$this->uploadPng(),'reference','u',1));$this->owner->role='owner';
        $this->rejected(422,fn()=>$service->upload($this->owner,$c->id,\Illuminate\Http\UploadedFile::fake()->createWithContent('fake.png','<html>Not an image</html>'),'reference','u',1));
        config(['create.input_workspace_bytes'=>1]);$this->rejected(422,fn()=>$service->upload($this->owner,$c->id,$this->uploadPng(),'reference','u',1));
        config(['create.input_workspace_bytes'=>1073741824]);$this->rejected(409,fn()=>$service->upload($this->owner,$c->id,$this->uploadPng(),'reference','u',0));
        $this->assertSame(0,Asset::count());$this->assertSame([],\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
        $asset=$service->upload($this->owner,$c->id,$this->uploadPng(),'reference','u',1);
        $this->rejected(409,fn()=>$service->upload($this->owner,$c->id,$this->uploadPng(),'source','u',2));
        $other=$this->conversations->create($this->owner,[]);
        $this->rejected(409,fn()=>$service->upload($this->owner,$other->id,$this->uploadPng(),'reference','u',0));
        $this->assertSame(1,Asset::count());
    }

    public function test_upload_http_requires_reuse_confirmation_and_image_briefs_cannot_render_video_fixture(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);$this->actingAs($this->owner);
        \Illuminate\Support\Facades\Storage::fake('local');
        $c=$this->postJson('/api/v1/create/conversations',['output_kind'=>'image'])->assertCreated()->json('data.id');
        $this->post("/api/v1/create/conversations/$c/uploads",['asset_file'=>$this->uploadPng(),'purpose'=>'source','idempotency_key'=>'a','expected_version'=>0],['Accept'=>'application/json'])->assertStatus(422);
        $this->post("/api/v1/create/conversations/$c/uploads",['asset_file'=>$this->uploadPng(),'purpose'=>'source','reuse_confirmed'=>true,'idempotency_key'=>'a','expected_version'=>0],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.attachments.0.purpose','source');
        $this->postJson("/api/v1/create/conversations/$c/messages",['content'=>'Make a product image','expected_version'=>1,'idempotency_key'=>'brief'])->assertCreated();
        $this->postJson("/api/v1/create/conversations/$c/quotes",['expected_version'=>2])->assertStatus(422);
        $this->assertSame(0,\App\Models\ApiQuote::count());Bus::assertNothingDispatched();
    }

    public function test_history_search_archive_restore_and_active_archive_guard(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);$this->actingAs($this->owner);
        $c=$this->brief();
        $this->getJson('/api/v1/create/conversations?search=SOURCE')->assertOk()->assertJsonPath('data.0.id',$c->id);
        $this->patchJson("/api/v1/create/conversations/$c->id",['title'=>'Renamed','archived'=>true,'expected_version'=>1])->assertOk();
        $this->getJson('/api/v1/create/conversations')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/v1/create/conversations?archived=1')->assertOk()->assertJsonPath('data.0.title','Renamed');
        $this->patchJson("/api/v1/create/conversations/$c->id",['archived'=>false,'expected_version'=>2])->assertOk();
        $q=$this->conversations->quote($this->owner,$c->id,3);$this->conversations->approve($this->owner,$c->id,$q->id,'approve');
        $this->patchJson("/api/v1/create/conversations/$c->id",['archived'=>true,'expected_version'=>3])->assertStatus(409);
    }

    public function test_feature_off_and_non_allowlisted_workspaces_cannot_create(): void
    {
        config(['create.enabled' => false]); $this->rejected(404, fn () => $this->brief());
        config(['create.enabled' => true, 'create.workspaces' => []]); $this->rejected(404, fn () => $this->brief());
        $this->assertSame(0, DB::table('create_conversations')->count());
    }

    public function test_viewer_can_read_but_cannot_write(): void
    {
        $c = $this->brief(); $this->owner->role = 'viewer';
        $this->assertSame($c->id, $this->conversations->conversation($this->owner, $c->id)->id);
        $this->rejected(403, fn () => $this->conversations->quote($this->owner, $c->id, 1));
    }

    public function test_message_replay_is_idempotent_but_changed_payload_is_rejected(): void
    {
        $c = $this->brief(); $input = ['content' => 'Keep my source audio.', 'expected_version' => 0, 'idempotency_key' => 'message-1'];
        $this->conversations->message($this->owner, $c->id, $input);
        $this->assertSame(1, DB::table('create_messages')->count());
        $input['content'] = 'Replace the voice'; $this->rejected(409, fn () => $this->conversations->message($this->owner, $c->id, $input));
    }

    public function test_other_workspace_conversation_is_not_found(): void
    {
        $c = $this->brief(); $other = Workspace::create(['name' => 'Other', 'status' => 'active']);
        config(['create.workspaces' => [(int) $other->id]]); $this->owner->workspace_id = $other->id;
        $this->expectException(\Illuminate\Database\RecordNotFoundException::class);
        $this->conversations->conversation($this->owner, $c->id);
    }

    public function test_cross_workspace_asset_is_rejected(): void
    {
        $c = $this->brief(); $asset = Asset::create(['workspace_id' => 999, 'asset_type' => 'video', 'status' => 'ready']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'source', 1);
    }

    public function test_reference_attachment_changes_version_and_invalidates_quote(): void
    {
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'reference', 1);
        $this->assertSame('reference', DB::table('create_attachments')->value('purpose'));
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'old-quote'));
        $this->assertSame(0, DB::table('api_operations')->count());
    }

    public function test_expired_quote_and_disabled_accounting_block_admission(): void
    {
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $q->update(['expires_at' => now()->subMinute()]);
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'expired'));
        config(['developer.operation_accounting' => false]);
        $this->rejected(503, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'disabled'));
    }

    public function test_paid_mode_cannot_silently_call_a_provider(): void
    {
        $c = $this->brief(); config(['create.mode' => 'replicate']);
        $this->rejected(503, fn () => $this->conversations->quote($this->owner, $c->id, 1));
        Http::assertNothingSent();
    }

    public function test_admission_replay_and_capacity_use_shared_operations_without_debit(): void
    {
        [$c, $q, $run] = $this->admitted();
        $same = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-'.$c->id);
        $this->assertSame($run->id, $same->id); $this->assertSame(1, DB::table('api_operations')->count());
        $new = $this->conversations->quote($this->owner, $c->id, 1);
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $new->id, 'second'));
        $this->assertSame(0, DB::table('credit_ledger')->count());
        $this->assertSame(100, (int) $this->workspace->fresh()->credits_monthly);
    }

    public function test_cancel_before_claim_closes_hold_and_prevents_execution(): void
    {
        [$c, , $run] = $this->admitted(); $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertNull($this->runs->claim());
        $this->assertSame('cancelled', DB::table('composition_runs')->value('status'));
        $this->assertSame(0, (int) DB::table('api_operations')->value('reserved_credits'));
    }

    public function test_running_cancellation_waits_for_worker_and_rejects_ready_callback(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertSame('running', DB::table('api_operations')->value('status'));
        $this->assertTrue($this->runs->heartbeat($run->id, $lease['lease_token'], 1, 'Rendering')['cancel_requested']);
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Ready', 'bundle' => []], 'fake', 'hash'));
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'cancelled', 'summary' => 'Stopped'], null, null);
        $this->assertSame('cancelled', DB::table('composition_runs')->value('status'));
    }

    public function test_expired_lease_keeps_capacity_and_rejects_late_completion(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim(); $this->travel(100)->seconds();
        $this->assertNull($this->runs->claim());
        $this->assertSame('needs_attention', DB::table('api_operations')->value('status'));
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'failed', 'summary' => 'Late'], null, null));
    }

    public function test_old_progress_cannot_overwrite_new_stage_and_bad_token_is_rejected(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->runs->heartbeat($run->id, $lease['lease_token'], 2, 'Encoding');
        $this->runs->heartbeat($run->id, $lease['lease_token'], 1, 'Preparing');
        $this->assertSame('Encoding', DB::table('composition_runs')->value('stage'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, str_repeat('x', 64), 3, 'Done'));
    }

    public function test_completion_is_immutable_and_restore_creates_a_new_revision(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $result = ['status' => 'preview_ready', 'summary' => 'Fixture', 'bundle' => ['index.html' => '<html></html>']];
        $this->runs->finish($run->id, $lease['lease_token'], $result, 'private/test.mp4', 'hash');
        $again = $this->runs->finish($run->id, $lease['lease_token'], $result, 'private/test.mp4', 'hash');
        $this->assertTrue($again['replayed']); $this->assertSame(1, DB::table('composition_revisions')->count());
        $revision = DB::table('composition_revisions')->first();
        $newId = $this->conversations->restore($this->owner, $c->id, $revision->id, 2);
        $this->assertNotSame($revision->id, $newId); $this->assertSame(2, DB::table('composition_revisions')->count());
        $this->assertSame([1, 2], DB::table('composition_revisions')->orderBy('number')->pluck('number')->all());
        $result['summary'] = 'Different';
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], $result, 'private/test.mp4', 'hash'));
    }

    public function test_concurrent_brief_edit_preserves_finished_draft_without_replacing_head(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->conversations->message($this->owner, $c->id, ['content' => 'Actually make it blue.', 'expected_version' => 1, 'idempotency_key' => 'changed']);
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Old draft', 'bundle' => ['index.html' => 'old']], 'private/old.mp4', 'hash');
        $this->assertNull($this->conversations->conversation($this->owner, $c->id)->head_revision_id);
        $this->assertSame(1, (int) DB::table('composition_revisions')->value('conflict'));
    }
    public function test_recovery_requires_operator_confirmation_and_fences_old_worker(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->travel(100)->seconds(); $this->runs->claim();
        $this->artisan('create:reconcile-fixture', ['run' => $run->id])->assertExitCode(1);
        $this->assertSame('needs_attention', DB::table('api_operations')->value('status'));
        $this->artisan('create:reconcile-fixture', ['run' => $run->id, '--worker-stopped' => true])->assertExitCode(0);
        $this->assertSame('cancelled', DB::table('api_operations')->value('status'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, $lease['lease_token'], 2, 'Late'));
    }

    public function test_http_contract_hides_secrets_and_requires_explicit_approval(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner);
        $c = $this->postJson('/api/v1/create/conversations', [])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/create/conversations/$c/messages", ['content' => 'Fixture', 'expected_version' => 0, 'idempotency_key' => 'http-message'])->assertCreated();
        $quote = $this->postJson("/api/v1/create/conversations/$c/quotes", ['expected_version' => 1])->assertOk()->json('data.id');
        $this->postJson("/api/v1/create/conversations/$c/runs", ['quote_id' => $quote, 'idempotency_key' => 'http-run'])->assertStatus(422);
        $this->postJson("/api/v1/create/conversations/$c/runs", ['quote_id' => $quote, 'idempotency_key' => 'http-run', 'approved' => true])->assertAccepted();
        $this->runs->claim();
        $json = $this->getJson("/api/v1/create/conversations/$c")->assertOk()->json('data');
        foreach (['lease_hash', 'input_json', 'operation_id', 'request_hash'] as $private) $this->assertArrayNotHasKey($private, $json['runs'][0]);
    }

    public function test_internal_worker_routes_reject_missing_credentials_and_null_result(): void
    {
        config(['create.worker_token' => str_repeat('a', 64)]);
        $this->postJson('/api/internal/create/claim')->assertForbidden();
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/claim')->assertOk()->assertJsonPath('data', null);
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/runs/fake/finish', ['lease_token' => str_repeat('b', 64), 'result' => 'null'])->assertStatus(422);
    }

    public function test_message_order_follows_versions_even_with_identical_timestamps(): void
    {
        $this->freezeTime(); $c = $this->brief();
        $this->conversations->message($this->owner, $c->id, ['content' => 'Second instruction', 'expected_version' => 1, 'idempotency_key' => 'second-message']);
        $quote = $this->conversations->quote($this->owner, $c->id, 2);
        $this->assertSame(['Keep my source audio.', 'Second instruction'], array_column($quote->payload_json['messages'], 'content'));
    }

    private function imageAttachment(object $c, string $purpose = 'reference'): Asset
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('minio');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=');
        \Illuminate\Support\Facades\Storage::disk('minio')->put('sample.png', $png);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'minio://sample.png']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, $purpose, 1);
        return $asset;
    }

    public function test_quoted_inputs_are_private_immutable_and_keep_reference_role(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $f = $q->payload_json['input_files'][0];
        $this->assertSame('reference', $f['purpose']);
        $this->assertSame('image/png', $f['mime_type']);
        $this->assertSame($f['sha256'], hash('sha256', \Illuminate\Support\Facades\Storage::disk('local')->get($f['storage_path'])));
        \Illuminate\Support\Facades\Storage::disk('minio')->put('sample.png', 'Changed original');
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'frozen-input');
        $claim = $this->runs->claim();
        $this->assertArrayNotHasKey('storage_path', $claim['input']['input_files'][0]);
        $served = $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id);
        $this->assertSame($f['sha256'], $served['sha256']);
        $this->rejected(403, fn () => $this->runs->inputFile($run->id, str_repeat('x', 64), $asset->id));
        $this->rejected(404, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id + 1));
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->rejected(409, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id));
    }

    public function test_missing_or_changed_snapshot_and_expired_lease_cannot_be_downloaded(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c, 'source');
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'changed-input');
        $claim = $this->runs->claim();
        \Illuminate\Support\Facades\Storage::disk('local')->put($q->payload_json['input_files'][0]['storage_path'], 'corrupted');
        $this->rejected(409, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id));
        DB::table('composition_runs')->where('id', $run->id)->update(['lease_expires_at' => now()->subSecond()]);
        $this->rejected(409, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id));
    }

    public function test_archived_asset_cannot_be_approved_after_snapshot(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $asset->update(['status' => 'archived']);
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'archived-input'));
        $this->assertSame(0, DB::table('api_operations')->count());
    }

    public function test_staging_rejects_remote_urls_byte_overflow_and_cleans_partial_copies(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $other = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'http://127.0.0.1/private']);
        $this->conversations->attach($this->owner, $c->id, $other->id, 'source', 2);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 3));
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('create/inputs'));
        $this->assertSame(0, ApiQuote::count()); Http::assertNothingSent();
        config(['create.input_file_bytes' => 10]);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 3));
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('create/inputs'));
    }

    public function test_followup_quote_freezes_exact_base_revision_bundle(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'First draft', 'bundle' => ['index.html' => '<h1>First</h1>']], 'private.mp4', str_repeat('a', 64));
        $current = $this->conversations->conversation($this->owner, $c->id);
        $q = $this->conversations->quote($this->owner, $c->id, (int) $current->version);
        $this->assertSame($current->head_revision_id, $q->payload_json['base_revision_id']);
        $this->assertSame(['index.html' => '<h1>First</h1>'], $q->payload_json['base_bundle']);
        $this->assertSame(hash('sha256', json_encode(['index.html' => '<h1>First</h1>'])), $q->payload_json['base_bundle_hash']);
    }

    public function test_input_http_endpoint_requires_worker_and_current_lease(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'http-input');
        $claim = $this->runs->claim(); $url = "/api/internal/create/runs/{$run->id}/inputs/{$asset->id}";
        config(['create.worker_token' => str_repeat('w', 64)]);
        $this->postJson($url, ['lease_token' => $claim['lease_token']])->assertForbidden();
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => str_repeat('z', 64)])->assertForbidden();
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => $claim['lease_token']])->assertOk()->assertHeader('Content-Type', 'image/png');
        $asset->update(['status' => 'archived']);
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => $claim['lease_token']])->assertNotFound();
    }

    public function test_restored_revision_inherits_original_bytes_after_library_change(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'inherit');
        $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'Proof', 'bundle' => ['index.html' => '<h1>Proof</h1>']], 'preview.mp4', str_repeat('a', 64));
        $revision = DB::table('composition_revisions')->value('id');
        $restored = $this->conversations->restore($this->owner, $c->id, $revision, 3);
        $asset->update(['storage_url' => 'https://invalid.example/changed.png']);
        $next = $this->conversations->quote($this->owner, $c->id, 4);
        $this->assertSame($q->payload_json['input_files'], $next->payload_json['input_files']);
        $this->assertSame($restored, $next->payload_json['base_revision_id']);
        $this->assertSame(['index.html' => '<h1>Proof</h1>'], $next->payload_json['base_bundle']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'source', 4);
        $this->rejected(409, fn () => $this->conversations->quote($this->owner, $c->id, 5));
    }

    public function test_input_quota_and_cleanup_keep_run_inputs_but_remove_expired_orphans(): void
    {
        $c = $this->brief(); $this->imageAttachment($c);
        config(['create.input_workspace_bytes' => 1]);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 2));
        config(['create.input_workspace_bytes' => 1073741824]);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $kept = $q->payload_json['input_files'][0]['storage_path'];
        $this->conversations->approve($this->owner, $c->id, $q->id, 'retention');
        $orphanQuote = $this->conversations->quote($this->owner, $c->id, 2);
        $orphan = $orphanQuote->payload_json['input_files'][0]['storage_path'];
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        touch($disk->path($kept), now()->subDays(2)->timestamp);
        touch($disk->path($orphan), now()->subDays(2)->timestamp);
        app(\App\Services\Create\ArtifactRetentionService::class)->sweep();
        $this->assertTrue($disk->exists($kept)); $this->assertTrue($disk->exists($orphan));
        $orphanQuote->update(['expires_at' => now()->subMinute()]);
        app(\App\Services\Create\ArtifactRetentionService::class)->sweep();
        $this->assertTrue($disk->exists($kept)); $this->assertFalse($disk->exists($orphan));
    }

    public function test_attempt_replay_never_reauthorizes_execution_or_changes_request(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $first = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->assertTrue($first['may_execute']);
        $again = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->assertFalse($again['may_execute']); $this->assertSame($first['id'], $again['id']);
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('b', 64)));
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'render-2', 'render', str_repeat('a', 64)));
        $this->rejected(422, fn () => $service->begin($run->id, $claim['lease_token'], 'media-1', 'media', str_repeat('a', 64)));
        $this->assertSame(1, DB::table('api_operation_jobs')->where('status', 'pending')->count());
    }

    public function test_unsettled_attempt_prevents_run_close_and_unknown_keeps_capacity(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->rejected(409, fn () => $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Stopped'], null, null));
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'unknown']);
        $this->assertSame('needs_attention', DB::table('api_operations')->value('status'));
        $this->assertTrue($service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'unknown'])['replayed']);
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertSame('needs_attention', DB::table('composition_runs')->value('status'));
        $this->assertSame(1, DB::table('api_operation_jobs')->where('status', 'pending')->count());
        $this->artisan('create:reconcile-fixture', ['run' => $run->id, '--worker-stopped' => true])->assertExitCode(0);
        $this->assertSame('failed', DB::table('composition_attempts')->value('status'));
    }

    public function test_confirmed_render_receipt_settles_once_and_can_replay_after_finish(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $receipt = ['status' => 'succeeded', 'cost_microusd' => 0];
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Artifact delivery failed'], null, null);
        $this->assertTrue($service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt)['replayed']);
        $this->rejected(409, fn () => $service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'failed', 'cost_microusd' => 0]));
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }

    public function test_synthetic_paid_policy_charges_shared_ledger_exactly_once_and_respects_allowance(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        // Synthetic approval only: no public quote path permits this yet, and no provider is contacted.
        $input = json_decode($run->input_json, true); $input['mode'] = 'agent';
        $input['execution_policy']['agent'] = ['provider' => 'test-provider', 'model' => 'test-model', 'credits' => 7, 'cost_limit_microusd' => 50000, 'max_calls' => 3];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['authorized_credits' => 10, 'reserved_credits' => 10]);
        $this->rejected(503, fn () => $service->begin($run->id, $claim['lease_token'], 'call-1', 'agent', str_repeat('a', 64)));
        config(['create.paid_execution_enabled' => true,'create.pilot_budget_id'=>'test-pilot','create.pilot_budget_microusd'=>5000000]);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'call-1', 'agent', str_repeat('a', 64));
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'call-2', 'agent', str_repeat('b', 64)));
        $receipt = ['status' => 'succeeded', 'prediction_id' => 'synthetic-id', 'cost_microusd' => 12000];
        $this->rejected(422, fn () => $service->settle($run->id, $claim['lease_token'], $attempt['id'], array_merge($receipt, ['cost_microusd' => 50001])));
        $verified = new \App\Services\Create\VerifiedAttemptReceipt($attempt['id'],'succeeded','synthetic-id',12000,'Synthetic verifier test');
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt, $verified);
        $this->assertTrue($service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt)['replayed']);
        $this->assertSame(93, (int) $this->workspace->fresh()->credits_monthly);
        $this->assertSame(1, DB::table('credit_ledger')->count());
        $this->assertSame($run->operation_id, DB::table('credit_ledger')->value('api_operation_id'));
        $this->assertSame($attempt['id'], json_decode(DB::table('credit_ledger')->value('metadata'), true)['composition_attempt_id']);
        $this->assertSame(7, (int) DB::table('api_operations')->value('spent_credits'));
        $this->assertSame(3, (int) DB::table('api_operations')->value('reserved_credits'));
    }

    public function test_verified_late_provider_receipt_reconciles_once_without_reexecution(): void
    {
        [, , $run]=$this->admitted(); $claim=$this->runs->claim(); $attempts=app(\App\Services\Create\AttemptService::class);
        $input=json_decode($run->input_json,true);$input['mode']='agent';
        $input['execution_policy']['agent']=['provider'=>'replicate','model'=>'anthropic/test','credits'=>7,'cost_limit_microusd'=>50000,'max_calls'=>2];
        DB::table('composition_runs')->where('id',$run->id)->update(['input_json'=>json_encode($input)]);
        DB::table('api_operations')->where('id',$run->operation_id)->update(['authorized_credits'=>10,'reserved_credits'=>10]);
        config(['create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>'test-pilot','create.pilot_budget_microusd'=>5000000,'services.replicate.api_token'=>'fake-offline-test']);
        $requestHash=hash('sha256',json_encode(['prompt'=>'Test prompt','system'=>'Test system','maxTokens'=>1024,'image'=>null]));
        $a=$attempts->begin($run->id,$claim['lease_token'],'agent-1','agent',$requestHash);
        $attempts->bindPrediction($run->id,$claim['lease_token'],$a['id'],'prediction-test');
        $this->rejected(409,fn()=>$attempts->bindPrediction($run->id,$claim['lease_token'],$a['id'],'other'));
        $this->rejected(409,fn()=>$attempts->settle($run->id,$claim['lease_token'],$a['id'],['status'=>'succeeded','prediction_id'=>'prediction-test','cost_microusd'=>12000]));
        $attempts->settle($run->id,$claim['lease_token'],$a['id'],['status'=>'unknown']);
        $this->assertSame(100,(int)$this->workspace->fresh()->credits_monthly);
        $a=DB::table('composition_attempts')->where('id',$a['id'])->first();
        $verifier=app(\App\Services\Create\ProviderReceiptVerifier::class);
        $this->rejected(422,fn()=>$verifier->verify($a,null,'Billing record test'));
        Http::fake(['https://api.replicate.com/v1/predictions/prediction-test'=>Http::sequence()->push(['id'=>'prediction-test','model'=>'wrong/model','status'=>'succeeded'])->push(['id'=>'prediction-test','model'=>'anthropic/test','status'=>'succeeded','input'=>['prompt'=>'Test prompt','system_prompt'=>'Test system','max_tokens'=>1024]])]);
        $this->rejected(409,fn()=>$verifier->verify($a,12000,'Billing record test'));
        $receipt=$verifier->verify($a,12000,'Billing record test');
        $service=app(\App\Services\Create\ReconciliationService::class);
        $this->rejected(403,fn()=>$service->reconcile($receipt,false));
        $service->reconcile($receipt,true);
        $this->assertTrue($service->reconcile($receipt,true)['replayed']);
        $this->assertSame(93,(int)$this->workspace->fresh()->credits_monthly);
        $this->assertSame(1,DB::table('credit_ledger')->count());
        $this->assertSame(0,(int)DB::table('api_operations')->value('reserved_credits'));
        $this->assertSame('failed',DB::table('composition_runs')->value('status'));
        $this->assertNull(DB::table('composition_runs')->value('lease_hash'));
        $this->assertSame('unknown',DB::table('composition_reconciliations')->value('previous_status'));
        Http::assertSent(fn($r)=>$r->method()==='GET');
    }

    public function test_claude_gateway_makes_the_call_reads_usage_and_settles_once(): void
    {
        [, , $run]=$this->admitted(); $claim=$this->runs->claim(); $attempts=app(\App\Services\Create\AttemptService::class);
        $input=json_decode($run->input_json,true);$input['mode']='agent';
        $input['execution_policy']['agent']=['provider'=>'anthropic','model'=>'claude-opus-5-5','credits'=>75,'cost_limit_microusd'=>300000,'max_calls'=>3];
        DB::table('composition_runs')->where('id',$run->id)->update(['input_json'=>json_encode($input)]);
        DB::table('api_operations')->where('id',$run->operation_id)->update(['authorized_credits'=>225,'reserved_credits'=>225]);
        $this->workspace->update(['credits_monthly'=>1000]);
        config(['create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>'test-pilot','create.pilot_budget_microusd'=>5000000,'services.anthropic.key'=>'test-key','create.worker_token'=>str_repeat('a',64)]);
        $call=['prompt'=>'Build it — "now"','system'=>'Rules/1','max_tokens'=>1024,'image'=>null];
        $hash=hash('sha256',json_encode(['prompt'=>$call['prompt'],'system'=>$call['system'],'maxTokens'=>1024,'image'=>null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $a=$attempts->begin($run->id,$claim['lease_token'],'agent-1','agent',$hash);
        Http::fake(['https://api.anthropic.com/*'=>Http::sequence()
            ->push(['id'=>'msg_01abc','content'=>[['type'=>'text','text'=>'{"type":"finish"}']],'usage'=>['input_tokens'=>10000,'output_tokens'=>2000,'cache_creation_input_tokens'=>4000,'cache_read_input_tokens'=>0]])
            ->push(['type'=>'error'],400,['request-id'=>'req_011refused'])
            ->push(['id'=>'msg_02def','content'=>[['type'=>'text','text'=>'ok']],'usage'=>['input_tokens'=>100,'output_tokens'=>10]])]);
        $gateway=app(\App\Services\Create\AnthropicGateway::class);
        // The worker cannot send a different call from the one it recorded.
        $this->rejected(409,fn()=>$gateway->complete($run->id,$claim['lease_token'],$a['id'],['prompt'=>'Other','system'=>'Rules/1','max_tokens'=>1024]));
        $out=$gateway->complete($run->id,$claim['lease_token'],$a['id'],$call);
        // 10000*4 + 2000*20 + 4000*5 = 100000 microdollars -> 25 credits at the pilot tariff.
        $this->assertSame(['{"type":"finish"}',100000,25],[$out['text'],$out['cost_microusd'],$out['charged_credits']]);
        $this->assertSame(975,(int)$this->workspace->fresh()->credits_monthly);
        Http::assertSent(fn($r)=>$r->hasHeader('x-api-key','test-key')&&$r['model']==='claude-opus-5-5'&&$r['system'][0]['cache_control']['type']==='ephemeral'&&$r['output_config']['effort']==='medium');
        $this->rejected(409,fn()=>$gateway->complete($run->id,$claim['lease_token'],$a['id'],$call));
        // The worker's own settle reports the gateway's record and never charges again.
        $this->withToken(str_repeat('a',64))->postJson('/api/internal/create/runs/'.$run->id.'/attempts/'.$a['id'].'/settle',['lease_token'=>$claim['lease_token'],'status'=>'succeeded','prediction_id'=>'msg_01abc'])
            ->assertOk()->assertJsonPath('data.replayed',true)->assertJsonPath('data.cost_microusd',100000);
        $this->assertSame(1,DB::table('credit_ledger')->count());
        // A refused call is recorded against its request id and costs nothing.
        $b=$attempts->begin($run->id,$claim['lease_token'],'agent-2','agent',$hash);
        $this->rejected(502,fn()=>$gateway->complete($run->id,$claim['lease_token'],$b['id'],$call));
        $row=DB::table('composition_attempts')->where('id',$b['id'])->first();
        $this->assertSame(['failed','req_011refused',0],[$row->status,$row->prediction_id,(int)$row->charged_credits]);
        $this->assertSame(975,(int)$this->workspace->fresh()->credits_monthly);
        // Over HTTP, surrounding whitespace survives the app's string trimming, so the hash still matches.
        $raw=['prompt'=>"  Build it\n",'system'=>"Rules/1\n",'max_tokens'=>1024,'image'=>null];
        $c=$attempts->begin($run->id,$claim['lease_token'],'agent-3','agent',hash('sha256',json_encode(['prompt'=>$raw['prompt'],'system'=>$raw['system'],'maxTokens'=>1024,'image'=>null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
        $this->withToken(str_repeat('a',64))->postJson('/api/internal/create/runs/'.$run->id.'/attempts/'.$c['id'].'/anthropic',[...$raw,'lease_token'=>$claim['lease_token']])
            ->assertOk()->assertJsonPath('data.text','ok')->assertJsonPath('data.message_id','msg_02def');
    }

    public function test_pilot_policy_switches_the_build_agent_to_the_claude_gateway(): void
    {
        $this->pilot(); config(['create.agent_provider'=>'replicate']);
        $this->assertSame('replicate',\App\Services\Create\PilotPolicy::execution([])['agent']['provider']);
        config(['create.agent_provider'=>'anthropic','create.agent_model'=>'claude-opus-5-5','services.anthropic.key'=>'']);
        $this->rejected(503,fn()=>\App\Services\Create\PilotPolicy::execution([]));
        config(['services.anthropic.key'=>'k']);
        $agent=\App\Services\Create\PilotPolicy::execution([])['agent'];
        $this->assertSame(['anthropic','claude-opus-5-5',300000],[$agent['provider'],$agent['model'],$agent['cost_limit_microusd']]);
    }

    public function test_expired_or_cancelled_attempt_cannot_start_but_cancel_can_settle_known_work(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'render-2', 'render', str_repeat('a', 64)));
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'failed', 'cost_microusd' => 0]);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'cancelled', 'summary' => 'Stopped'], null, null);
        $this->assertSame('cancelled', DB::table('api_operations')->value('status'));
    }

    private function pilot(): void {
        config(['create.mode'=>'agent','create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>'e3-test','create.pilot_budget_microusd'=>5000000]);
        $this->workspace->update(['credits_monthly'=>10000]);
    }
    public function test_pilot_requires_media_consent_and_a_durable_budget(): void {
        $this->pilot();$c=$this->brief();$q=$this->conversations->quote($this->owner,$c->id,1);
        $this->assertSame(600,$q->credits_max);
        $this->rejected(422,fn()=>$this->conversations->approve($this->owner,$c->id,$q->id,'without-consent'));
        config(['create.pilot_budget_microusd'=>2000000]);
        $this->rejected(402,fn()=>$this->conversations->approve($this->owner,$c->id,$q->id,'too-much',true));
        $this->assertSame(0,DB::table('composition_runs')->count());
        config(['create.pilot_budget_microusd'=>5000000]);
        $run=$this->conversations->approve($this->owner,$c->id,$q->id,'approved',true);
        $this->assertSame($run->id,$this->conversations->approve($this->owner,$c->id,$q->id,'approved',true)->id);
        $this->assertSame(600,(int)DB::table('api_operations')->value('reserved_credits'));
        // Started work remains budgeted across a process/config reload.
        config(['create.pilot_budget_microusd'=>4700000]);
        $this->rejected(402,fn()=>\App\Services\Create\PilotPolicy::admit($q->payload_json['execution_policy']));
    }
    public function test_pilot_metering_is_provider_verified_and_charged_once(): void {
        $this->pilot();$c=$this->brief();$q=$this->conversations->quote($this->owner,$c->id,1);
        $run=$this->conversations->approve($this->owner,$c->id,$q->id,'approve',true);$claim=$this->runs->claim();
        $input=['prompt'=>'draw','system'=>'instructions','maxTokens'=>4096,'image'=>null];
        $attempts=app(\App\Services\Create\AttemptService::class);
        $a=$attempts->begin($run->id,$claim['lease_token'],'agent-1','agent',hash('sha256',json_encode($input)));
        $attempts->bindPrediction($run->id,$claim['lease_token'],$a['id'],'prediction-test');
        $receipt=['id'=>'prediction-test','model'=>'anthropic/claude-4.5-sonnet','status'=>'succeeded','input'=>['prompt'=>'draw','system_prompt'=>'instructions','max_tokens'=>4096], 'metrics'=>['token_input_count'=>1000,'token_output_count'=>100]];
        Http::fake(['api.replicate.com/*'=>Http::response($receipt)]);
        $verifier=app(\App\Services\Create\ProviderReceiptVerifier::class);
        $verified=$verifier->metered(DB::table('composition_attempts')->first());
        $this->assertSame(4500,$verified->costMicrousd);
        $result=$attempts->settle($run->id,$claim['lease_token'],$a['id'],$verified->result(),$verified);
        $this->assertSame(2,$result['charged_credits']);
        $this->assertTrue($attempts->settle($run->id,$claim['lease_token'],$a['id'],$verified->result(),$verified)['replayed']);
        $this->assertSame(1,DB::table('credit_ledger')->count());
        $receipt['input']['prompt']='different';Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['api.replicate.com/*'=>Http::response($receipt)]);
        $this->rejected(409,fn()=>$verifier->metered(DB::table('composition_attempts')->first()));
    }
    public function test_image_quote_uses_shared_pricing_and_rejects_inspiration_reuse(): void {
        $this->pilot();$c=$this->conversations->create($this->owner,['output_kind'=>'image','aspect_ratio'=>'1:1']);
        $this->conversations->message($this->owner,$c->id,['content'=>'Orange geometric shapes','expected_version'=>0,'idempotency_key'=>'brief']);
        $q=$this->conversations->quote($this->owner,$c->id,1);
        $this->assertSame(app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor('nano-banana'),$q->credits_max);
        $this->assertSame('1:1',$q->payload_json['media_input']['aspect_ratio']);
        $this->assertArrayNotHasKey('agent',$q->payload_json['execution_policy']);
        $this->assertSame(100000,\App\Services\Create\PilotPolicy::ceiling($q->payload_json['execution_policy']));
    }
    public function test_settings_invalidate_quotes_and_require_caption_text(): void {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->pilot();$c=$this->brief();$q=$this->conversations->quote($this->owner,$c->id,1);
        $this->actingAs($this->owner)->patchJson('/api/v1/create/conversations/'.$c->id,['expected_version'=>1,'settings'=>['captions'=>'provided']])->assertStatus(422);
        $this->actingAs($this->owner)->patchJson('/api/v1/create/conversations/'.$c->id,['expected_version'=>1,'settings'=>['aspect_ratio'=>'1:1','duration_seconds'=>10]])->assertOk();
        $this->rejected(409,fn()=>$this->conversations->approve($this->owner,$c->id,$q->id,'stale',true));
        $fresh=$this->conversations->quote($this->owner,$c->id,2);
        $this->assertSame(10,$fresh->payload_json['settings']['duration_seconds']);
    }

    public function test_variation_admission_is_atomic_and_replay_safe(): void {
        $this->pilot();
        $c=$this->conversations->create($this->owner,['output_kind'=>'image']);
        $this->conversations->message($this->owner,$c->id,['content'=>'Orange geometry','expected_version'=>0,'idempotency_key'=>'brief']);
        $v=app(\App\Services\Create\VariantService::class);
        $q=$v->quote($this->owner,$c->id,1,3);$this->assertSame(30,$q->credits_max);
        config(['create.pilot_budget_microusd'=>250000]);
        $this->rejected(402,fn()=>$v->approve($this->owner,$c->id,$q->id,'variants',true));
        $this->assertSame(0,DB::table('composition_runs')->count());
        $this->assertSame(0,DB::table('api_operations')->count());
        config(['create.pilot_budget_microusd'=>5000000]);
        $run=$v->approve($this->owner,$c->id,$q->id,'variants',true);
        $this->assertSame($run->id,$v->approve($this->owner,$c->id,$q->id,'variants',true)->id);
        $this->assertSame(3,DB::table('composition_runs')->count());
        $this->assertSame(3,DB::table('api_operations')->count());
        $this->rejected(409,fn()=>$v->retryQuote($this->owner,$c->id,$run->id,1));
        $claim=$this->runs->claim();$this->runs->finish($claim['id'],$claim['lease_token'],['status'=>'failed','summary'=>'Confirmed pre-provider failure'],null,null);
        $retry=$v->retryQuote($this->owner,$c->id,$claim['id'],1);
        $this->assertSame($claim['id'],$retry->payload_json['retry_of']);
        $v->approve($this->owner,$c->id,$retry->id,'retry',true);
        $this->rejected(409,fn()=>$v->retryQuote($this->owner,$c->id,$claim['id'],1));
        $this->assertSame(4,DB::table('composition_runs')->count());
    }

    public function test_delivery_pins_revision_requires_consent_and_warns_on_unsatisfied_changes(): void {
        [$c,$revision,$output]=$this->registeredOutput();$service=app(\App\Services\Create\DeliveryService::class);
        $input=['action'=>'share','expected_version'=>2,'confirmed'=>false];
        $this->rejected(422,fn()=>$service->deliver($this->owner,$c->id,$revision,$input));
        $input['confirmed']=true;$shared=$service->deliver($this->owner,$c->id,$revision,$input);
        $token=basename($shared['url']);$url='/api/v1/public/creations/'.$token;
        $this->getJson($url)->assertOk()->assertJsonPath('data.version',1);
        $this->conversations->message($this->owner,$c->id,['content'=>'Change heading','expected_version'=>2,'idempotency_key'=>'edit']);$input['expected_version']=3;
        $this->rejected(409,fn()=>$service->deliver($this->owner,$c->id,$revision,$input));
        $input['allow_older']=true;$this->assertSame($shared['url'],$service->deliver($this->owner,$c->id,$revision,$input)['url']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.version',1);
        $this->owner->role='viewer';$this->rejected(403,fn()=>$service->deliver($this->owner,$c->id,$revision,$input));$this->owner->role='owner';
        $input['action']='unshare';$service->deliver($this->owner,$c->id,$revision,$input);$this->getJson($url)->assertNotFound();
        $this->workspace->update(['status'=>'suspended']);$this->getJson($url)->assertNotFound();
    }

    public function test_clarification_releases_unused_hold_and_fixture_metadata_is_honest(): void {
        [$c,,$run]=$this->admitted();$claim=$this->runs->claim();
        $this->runs->finish($run->id,$claim['lease_token'],['status'=>'needs_input','summary'=>'Please supply the price.'],null,null);
        $this->assertSame('needs_input',DB::table('composition_runs')->where('id',$run->id)->value('status'));
        $this->assertFalse(DB::table('api_operations')->where('id',$run->operation_id)->where('status','running')->exists());
        $c=$this->conversations->create($this->owner,['duration_seconds'=>30,'aspect_ratio'=>'1:1']);
        $this->conversations->message($this->owner,$c->id,['content'=>'Test','expected_version'=>0,'idempotency_key'=>'brief']);
        $q=$this->conversations->quote($this->owner,$c->id,1);$r=$this->conversations->approve($this->owner,$c->id,$q->id,'fixture');$claim=$this->runs->claim();
        $this->runs->finish($r->id,$claim['lease_token'],['status'=>'preview_ready','summary'=>'Fixture','bundle'=>['index.html'=>'fixture']],'private/path','hash');
        $m=json_decode(DB::table('composition_revisions')->where('conversation_id',$c->id)->value('metadata_json'),true);
        $this->assertSame(15,$m['settings']['duration_seconds']);$this->assertSame('9:16',$m['settings']['aspect_ratio']);
        $this->assertSame(30,$m['requested_settings']['duration_seconds']);
    }

}
