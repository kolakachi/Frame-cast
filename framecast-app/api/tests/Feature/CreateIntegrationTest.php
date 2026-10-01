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
        (require database_path('migrations/2026_10_01_120000_create_create_plan_media.php'))->up();
        (require database_path('migrations/2026_10_01_130000_create_create_styles.php'))->up();
        (require database_path('migrations/2026_10_01_140000_create_create_pronunciations.php'))->up();
        (require database_path('migrations/2026_10_01_150000_create_create_style_notes.php'))->up();
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
        $blank = ['state_in' => '', 'state_out' => '', 'reads' => [], 'layout' => '', 'field' => ''];
        $this->assertEquals([['label' => 'Hook', 'start' => 0.0, 'end' => 4.0, 'idea' => 'Take', ...$blank], ['label' => 'Too long', 'start' => 10.0, 'end' => 15.0, 'idea' => 'clamped', ...$blank]], $plan['scenes']);
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
        $this->assertNotContains('library_music', array_column(\App\Services\Create\CapabilityCatalogue::forWorkspace($this->workspace->id), 'kind'), 'placeholder tracks are not offered as licensed music');
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

    public function test_plan_media_is_bought_under_one_approval_charged_on_success_and_reused_on_retry(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief();
        $plan = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-pm');
        $imageCredits = \App\Services\Create\CapabilityCatalogue::credits('ai_image', $this->workspace->id);
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $json['media'] = [['kind' => 'ai_image', 'description' => 'Cold brew glass on ice, warm light', 'credits' => 999],
            ['kind' => 'stock_image', 'description' => 'coffee beans close up', 'credits' => 0],
            ['kind' => 'voiceover', 'description' => 'Narrate the callouts', 'credits' => 3],
            ['kind' => 'stabilize', 'description' => 'not bought: a sandbox edit', 'credits' => 0]];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);

        $q = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame(['ai_image', 'stock_image', 'voiceover'], array_column($q->payload_json['plan_media'], 'kind'), 'sandbox edits are not purchases');
        $this->assertSame($imageCredits, $q->payload_json['plan_media'][0]['credits'], 'price comes from the catalogue, not the plan');
        $media = $q->payload_json['execution_policy']['plan_media'];
        $this->assertSame([$imageCredits + 0 + 3, 3], [$media['total_credits'], $media['max_calls']]);
        $agent = $q->payload_json['execution_policy']['agent'];
        $this->assertSame($agent['credits'] * $agent['max_calls'] + $imageCredits + 3, (int) $q->credits_max, 'one approval covers the build and every item');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=');
        $calls = [];
        app()->instance(\App\Services\Create\PlanMediaExecutor::class, new class($png, $calls) extends \App\Services\Create\PlanMediaExecutor {
            public function __construct(private string $png, private array &$calls) {}
            public function produce(string $kind, string $description, array $ctx, string $dir): array {
                $this->calls[] = $kind;
                if ($kind === 'voiceover') throw new \RuntimeException('Narration needs approved lines.');
                file_put_contents($dir.'/x.png', $this->png.($kind === 'stock_image' ? 'stock' : ''));
                return ['path' => $dir.'/x.png', 'mime' => 'image/png', 'title' => ucfirst($kind), 'provider_id' => $kind.'-1'];
            }
        });
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-pm', true);
        $claim = $this->runs->claim();
        $service = app(\App\Services\Create\PlanMediaService::class);
        $before = (int) $this->workspace->fresh()->credits_monthly + (int) $this->workspace->fresh()->credits_topup;
        $image = $service->produce($run->id, $claim['lease_token'], 0);
        $this->assertSame(['succeeded', $imageCredits, false], [$image['status'], $image['charged_credits'], $image['reused']]);
        $this->assertSame($image['file']['sha256'], $this->runs->inputFile($run->id, $claim['lease_token'], $image['file']['asset_id'])['sha256'], 'the worker can download it');
        $this->assertSame(0, $service->produce($run->id, $claim['lease_token'], 1)['charged_credits']);
        $voice = $service->produce($run->id, $claim['lease_token'], 2);
        $this->assertSame(['failed', 0, 'Narration needs approved lines.'], [$voice['status'], $voice['charged_credits'], $voice['error']]);
        $replay = $service->produce($run->id, $claim['lease_token'], 0);
        $this->assertSame([true, 0], [$replay['reused'], $replay['charged_credits']], 'a replayed request never charges again');
        $this->rejected(404, fn () => $service->produce($run->id, $claim['lease_token'], 9));
        $after = (int) $this->workspace->fresh()->credits_monthly + (int) $this->workspace->fresh()->credits_topup;
        $this->assertSame($imageCredits, $before - $after, 'only the successful paid item was charged');

        // The build fails; the user retries the same plan. Bought items are reused free; the failed one is tried again.
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Stopped'], null, null);
        $q2 = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $run2 = $this->conversations->approve($this->owner, $c->id, $q2->id, 'approve-pm-2', true);
        $claim2 = $this->runs->claim();
        $again = $service->produce($run2->id, $claim2['lease_token'], 0);
        $this->assertSame([true, 0, $image['file']['asset_id']], [$again['reused'], $again['charged_credits'], $again['file']['asset_id']]);
        $this->assertSame($image['file']['sha256'], $this->runs->inputFile($run2->id, $claim2['lease_token'], $image['file']['asset_id'])['sha256']);
        $service->produce($run2->id, $claim2['lease_token'], 2);
        $this->assertSame(['ai_image', 'stock_image', 'voiceover', 'voiceover'], $calls, 'the image was not made twice');
        $this->assertSame('cold brew pour ice', \App\Services\Create\PlanMediaExecutor::searchTerms('Vertical slow-motion cold brew pour over ice, dark background'));
    }

    public function test_saved_styles_come_from_a_version_or_a_reference_and_steer_later_quotes(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $html = '<html data-composition-variables=\'[{"id":"color_background","type":"color","label":"Background","default":"#F2EDE4"},{"id":"color_accent","type":"color","label":"Accent","default":"#E07A52"},{"id":"headline","type":"string","label":"Headline","default":"Hi"}]\'><style>h1{font-family:"Inter", sans-serif}</style></html>';
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => $html]], 'private/v1.mp4', 'h');
        $rev = $this->conversations->conversation($this->owner, $c->id)->head_revision_id;
        $styles = app(\App\Services\Create\StyleService::class);
        $fromVersion = $styles->fromRevision($this->owner, $c->id, $rev, 'Paper warm');
        $this->assertSame(['#F2EDE4', '#E07A52'], $fromVersion['style']['palette']);
        $this->assertSame('Inter', $fromVersion['style']['type']);

        $ref = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'X · DreW', 'storage_url' => 'create-upload://x', 'status' => 'active',
            'metadata_json' => ['reference_analysis' => ['average_shot_seconds' => 2.7, 'notes' => ['summary' => 'Calm, epic, calm.', 'palette' => ['#2A2766', 'nope'], 'borrow' => ['Bookend the montage'], 'avoid_copying' => ['The box character']]]]]);
        $fromRef = $styles->fromReference($this->owner, $ref->id, 'Cosmic montage');
        $this->assertSame([['#2A2766'], 2.7], [$fromRef['style']['palette'], $fromRef['style']['average_shot_seconds']]);
        $other = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'plain', 'storage_url' => 'create-upload://y', 'status' => 'active']);
        $this->rejected(422, fn () => $styles->fromReference($this->owner, $other->id, 'x'));

        $edited = $styles->update($this->owner, $fromRef['id'], ['name' => 'Cosmic', 'style' => ['motion' => 'Springy hops']]);
        $this->assertSame([2, 'Cosmic', 'Springy hops', ['#2A2766']], [$edited['version'], $edited['name'], $edited['style']['motion'], $edited['style']['palette']]);

        // Choosing it on a conversation freezes it into the next quote; a style from another workspace is refused.
        $v = $this->conversations->conversation($this->owner, $c->id)->version;
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner)->patchJson('/api/v1/create/conversations/'.$c->id, ['expected_version' => $v, 'settings' => ['style_id' => (string) \Illuminate\Support\Str::uuid()]])->assertStatus(422);
        $this->actingAs($this->owner)->patchJson('/api/v1/create/conversations/'.$c->id, ['expected_version' => $v, 'settings' => ['style_id' => $fromRef['id']]])->assertOk();
        $q = $this->conversations->quote($this->owner, $c->id, $v + 1);
        $this->assertSame(['Cosmic', 2], [$q->payload_json['style']['name'], $q->payload_json['style']['version']]);

        $styles->delete($this->owner, $fromVersion['id']);
        $this->assertSame(['Cosmic'], array_column($styles->list($this->owner), 'name'));
        $this->rejected(404, fn () => $styles->delete($this->owner, $fromVersion['id']));
    }

    public function test_delivery_checks_are_kept_with_the_version_and_reduced_to_known_fields(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $checks = ['ok' => false, 'safe_area' => [['selector' => '#cta', 'time' => 13.456, 'message' => 'Collides with the caption band', 'extra' => 'dropped']],
            'edges' => 'not a list', 'contrast' => [], 'loudness' => ['status' => 'levelled', 'from' => -23.44, 'lufs' => -14.02, 'peak' => -1.6], 'injected' => '<script>'];
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<html></html>'], 'delivery_checks' => $checks], 'private/v1.mp4', 'h');
        $meta = json_decode(DB::table('composition_revisions')->where('run_id', $run->id)->value('metadata_json'), true);
        $this->assertEquals(['ok' => false, 'safe_area' => [['selector' => '#cta', 'time' => 13.46, 'message' => 'Collides with the caption band']], 'edges' => [], 'contrast' => [],
            'pacing' => [], 'loudness' => ['status' => 'levelled', 'lufs' => -14.0, 'from' => -23.4, 'peak' => -1.6]], $meta['delivery_checks']);
        $this->assertNull(\App\Services\Create\RunService::deliveryChecks('nope'));
    }

    public function test_text_and_colour_only_plans_become_free_edits_checked_against_real_fields(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'current_variables' => [['id' => 'headline', 'type' => 'string', 'label' => 'Headline', 'current' => 'Save twenty percent'],
            ['id' => 'color_accent', 'type' => 'color', 'label' => 'Accent', 'current' => '#ffcc00']]];
        $raw = ['summary' => 'Orange offer text.', 'scenes' => [['label' => 'Edit', 'start' => 0, 'end' => 1, 'idea' => 'Recolour']], 'left_out' => ''];
        $ok = $plans->normalize([...$raw, 'free_edit' => ['color_accent' => '#FF6B35', 'headline' => 'Save twenty percent']], $ctx, $this->workspace->id);
        $this->assertSame(['color_accent' => '#ff6b35'], $ok['free_edit'], 'unchanged values are dropped; colours normalised');
        $this->assertNull($plans->normalize([...$raw, 'free_edit' => ['font_size' => '200px']], $ctx, $this->workspace->id)['free_edit'], 'a field the version does not have is not free');
        $this->assertNull($plans->normalize([...$raw, 'free_edit' => ['color_accent' => 'orange']], $ctx, $this->workspace->id)['free_edit'], 'an invalid colour is not applied');
        $this->assertNull($plans->normalize([...$raw, 'free_edit' => ['headline' => 'x']], ['files' => [], 'current_variables' => []], $this->workspace->id)['free_edit'], 'a first build has nothing to edit');
    }

    public function test_a_held_run_with_every_call_settled_can_be_closed_without_repeating_anything(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $attempts->settle($run->id, $claim['lease_token'], $a['id'], ['status' => 'succeeded', 'cost_microusd' => 0]);
        $service = app(\App\Services\Create\ReconciliationService::class);
        $this->rejected(409, fn () => $service->closeSettled($run->id, true), );
        DB::table('composition_runs')->where('id', $run->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->runs->claim();
        $this->assertSame('needs_attention', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->rejected(403, fn () => $service->closeSettled($run->id, false));
        $this->assertSame('failed', $service->closeSettled($run->id, true)['status']);
        $this->assertSame(0, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        $this->assertNotSame('needs_attention', DB::table('api_operations')->where('id', $run->operation_id)->value('status'));
        // An unresolved call still requires a verified receipt.
        [, , $run2] = $this->admitted(); $claim2 = $this->runs->claim();
        $b = $attempts->begin($run2->id, $claim2['lease_token'], 'render-1', 'render', str_repeat('b', 64));
        DB::table('composition_runs')->where('id', $run2->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->runs->claim();
        $this->rejected(409, fn () => $service->closeSettled($run2->id, true));
    }

    public function test_a_long_finished_summary_is_kept_whole_on_the_version_and_shortened_for_the_run_stage(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $summary = str_repeat('Long visual review sentence. ', 60);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => $summary, 'bundle' => ['index.html' => '<html></html>']], 'private/v1.mp4', 'h');
        $this->assertLessThanOrEqual(255, mb_strlen(DB::table('composition_runs')->where('id', $run->id)->value('stage')), 'Postgres varchar(255)');
        $this->assertSame($summary, DB::table('composition_revisions')->where('run_id', $run->id)->value('summary'));
    }

    public function test_a_finished_build_whose_save_failed_can_be_recovered_without_repeating_or_charging(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Coordinator returned HTTP 500'], null, null);
        $dir = sys_get_temp_dir().'/recover-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        (new \Symfony\Component\Process\Process(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=108x192:d=15', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $dir.'/video.mp4']))->mustRun();
        file_put_contents($dir.'/completion.json', json_encode(['result' => ['status' => 'preview_ready', 'summary' => 'Built.', 'bundle' => ['index.html' => '<html>built</html>'], 'delivery_checks' => ['ok' => true]]]));
        $credits = (int) $this->workspace->fresh()->credits_monthly;
        $this->artisan('create:recover-finished', ['run' => $run->id, 'completion' => $dir.'/completion.json', 'artifact' => $dir.'/video.mp4'])->assertSuccessful();
        $this->assertSame('preview_ready', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $rev = DB::table('composition_revisions')->where('run_id', $run->id)->first();
        $this->assertSame('<html>built</html>', json_decode($rev->bundle_json, true)['index.html']);
        $this->assertSame($rev->id, $this->conversations->conversation($this->owner, $c->id)->head_revision_id);
        $this->assertSame($credits, (int) $this->workspace->fresh()->credits_monthly, 'nothing is charged again');
        $this->assertNull(DB::table('composition_runs')->where('id', $run->id)->value('lease_hash'));
        $this->artisan('create:recover-finished', ['run' => $run->id, 'completion' => $dir.'/completion.json', 'artifact' => $dir.'/video.mp4'])->assertFailed();
    }

    public function test_a_web_page_is_captured_as_a_reference_and_its_claims_are_only_suggestions(): void
    {
        $pages = \App\Services\Create\References\PageReferenceService::class;
        foreach (['http://wyvstudio.com', 'https://localhost/x', 'https://10.0.0.5/', 'https://user:pw@wyvstudio.com', 'https://intranet'] as $bad) $this->rejected(422, fn () => $pages::publicUrl($bad));
        $pages::$resolve = fn ($h) => $h === 'evil.example' ? ['192.168.1.9'] : ['104.21.1.1'];
        $this->rejected(422, fn () => $pages::publicUrl('https://evil.example/'));
        $this->assertSame('https://wyvstudio.com/', $pages::publicUrl('https://wyvstudio.com'));

        $c = $this->brief();
        $jpg = base64_decode('/9j/4AAQSkZJRgABAgAAAQABAAD//gAQTGF2YzYxLjE5LjEwMQD/2wBDAAgEBAQEBAUFBQUFBQYGBgYGBgYGBgYGBgYHBwcICAgHBwcGBgcHCAgICAkJCQgICAgJCQoKCgwMCwsODg4RERT/xABNAAEBAAAAAAAAAAAAAAAAAAAABgEBAQEAAAAAAAAAAAAAAAAAAAYHEAEAAAAAAAAAAAAAAAAAAAAAEQEAAAAAAAAAAAAAAAAAAAAA/8AAEQgAbgBAAwEiAAIRAAMRAP/aAAwDAQACEQMRAD8ArQGOroAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAB/9k=');
        \Illuminate\Support\Facades\Process::fake(function ($p) use ($jpg) {
            $cmd = $p->command;
            foreach ($cmd as $arg) if (str_starts_with($arg, '--screenshot=')) { file_put_contents(substr($arg, 13), str_repeat('p', 2000)); return \Illuminate\Support\Facades\Process::result(''); }
            if ($cmd[0] === 'ffmpeg') { file_put_contents(end($cmd), $jpg); return \Illuminate\Support\Facades\Process::result(''); }
            return \Illuminate\Support\Facades\Process::result('', 'unexpected', 1);
        });
        $this->app->instance(\App\Services\Generation\UrlContentExtractor::class, new class extends \App\Services\Generation\UrlContentExtractor {
            public function extract(string $url): string { return "Title: WyvStudio\n\nBranded short-form video without a shoot. Make ads, reels and explainers in minutes."; }
        });
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.agent_model' => 'claude-opus-5-5']);
        Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['summary' => 'AI short-video studio for brands.', 'look' => 'Dark, orange accent', 'palette' => ['#0A0A0F', '#FF6B35', 'red'],
            'claims' => [['text' => 'Branded video without a shoot', 'quote' => 'Branded short-form video without a shoot'], ['text' => 'Used by 10,000 brands', 'quote' => 'Trusted by 10,000 brands'],
                ['text' => 'Ads in minutes', 'quote' => 'make ads, reels and explainers in   minutes']]])]], 'usage' => ['input_tokens' => 2000, 'output_tokens' => 300]])]);
        $asset = app($pages)->add($this->owner, $c->id, 'https://wyvstudio.com', (int) $this->conversations->conversation($this->owner, $c->id)->version, 'page-1');
        $this->assertSame('reference', DB::table('create_attachments')->where('asset_id', $asset->id)->value('purpose'), 'the screenshot is never footage');
        $this->assertSame('Page · wyvstudio.com', $asset->title);
        $a = $asset->metadata_json['reference_analysis'];
        $this->assertSame(['Branded video without a shoot', 'Ads in minutes'], array_column($a['suggested_claims'], 'text'), 'a claim the page does not make is dropped');
        $this->assertSame(['#0A0A0F', '#FF6B35'], $a['notes']['palette']);
        $brief = \App\Services\Create\PlanService::referenceBrief($asset);
        $this->assertSame(['Branded video without a shoot', 'Ads in minutes'], $brief['page_claims_not_approved']);
        $pages::$resolve = null;
    }

    public function test_the_plan_drafts_a_sized_narration_script_with_a_voice_and_buys_the_voice(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $voices = [['key' => 'Kore', 'character' => 'Firm', 'gender' => 'female'], ['key' => 'Puck', 'character' => 'Upbeat', 'gender' => 'male']];
        $ctx = ['files' => [], 'voices' => $voices, 'settings' => ['duration_seconds' => 10, 'audio' => 'original']];
        $raw = ['summary' => 'Explainer.', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 10, 'idea' => 'x']], 'left_out' => '', 'voice' => 'Puck',
            'narration' => ['Got a product to sell?', 'WyvStudio turns it into a video that sells.', str_repeat('extra words that run long ', 4), 'This line never fits.']];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $this->assertSame(['Got a product to sell?', 'WyvStudio turns it into a video that sells.'], $p['narration'], 'the script is cut to about 2.8 words a second');
        $this->assertSame('Puck', $p['voice']);
        $this->assertSame('voiceover', collect($p['media'])->firstWhere('kind', 'voiceover')['kind'], 'a script adds the voice to buy');
        $this->assertSame('Kore', $plans->normalize([...$raw, 'voice' => 'Nobody'], $ctx, $this->workspace->id)['voice'], 'an unknown voice falls back');
        $this->assertSame([], $plans->normalize($raw, [...$ctx, 'settings' => ['duration_seconds' => 10, 'audio' => 'silent']], $this->workspace->id)['narration'], 'silent videos have no script');
    }

    public function test_the_user_edits_the_script_and_voice_and_the_voice_reads_it_from_our_storage(): void
    {
        config(['create.planner' => 'offline']);
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-n');
        $edited = $plans->select($this->owner, $c->id, $p['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['narration' => ['Line one.', ' ', 'Line two'], 'voice' => 'Charon']);
        $this->assertSame(['Line one.', 'Line two'], $edited['plan']['selections']['narration']);
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['voice' => 'NotAVoice']));
        $q = \App\Services\Create\PlanService::forQuote($this->conversations->conversation($this->owner, $c->id));
        $this->assertSame([['Line one.', 'Line two'], 'Charon'], [$q['narration'], $q['voice']]);

        // The TTS adapter stores its audio in our own storage; the executor reads it directly.
        $wav = base64_decode('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');
        $spoken = [];
        $this->app->instance(\App\Services\Generation\TTS\TTSAdapter::class, new class($spoken) implements \App\Services\Generation\TTS\TTSAdapter {
            public function __construct(public array &$spoken) {}
            public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array { $this->spoken[] = [$text, $voiceId]; return ['audio_url' => 'minio://audio/tts/test.wav', 'duration_seconds' => 1, 'provider_key' => 'gemini', 'provider_voice_id' => $voiceId]; }
            public function providerKey(): string { return 'test'; }
        });
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('isManagedUrl')->with('minio://audio/tts/test.wav')->andReturn(true);
        $storage->shouldReceive('get')->with('minio://audio/tts/test.wav')->andReturn($wav);
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        Http::fake(); Http::preventStrayRequests();
        $dir = sys_get_temp_dir().'/narr-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        $made = app(\App\Services\Create\PlanMediaExecutor::class)->produce('voiceover', 'Narration', ['workspace_id' => $this->workspace->id, 'narration' => ['Line one.', 'Line two'], 'voice' => 'Charon', 'approved_copy' => ['On screen']], $dir);
        $this->assertSame($wav, file_get_contents($made['path']));
        $this->assertSame([['Line one. Line two.', 'Charon']], $spoken, 'the approved script is spoken, not the on-screen copy');
        DB::table('create_pronunciations')->insert(['workspace_id' => $this->workspace->id, 'written' => 'WyvStudio', 'spoken' => 'Weave Studio', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('Try Weave Studio, then Weave Studio again. WyvStudioX stays.', \App\Services\Create\PlanMediaExecutor::pronounce('Try wyvstudio, then WyvStudio again. WyvStudioX stays.', $this->workspace->id), 'whole words only, any case');
    }

    public function test_music_and_a_sound_sheet_are_generated_priced_and_cut_into_cues(): void
    {
        $this->assertSame(34, \App\Services\Create\CapabilityCatalogue::musicCredits(15));
        $this->assertSame(65, \App\Services\Create\CapabilityCatalogue::musicCredits(30), 'music is priced by length');
        config(['services.replicate.api_token' => 'r8-test']);
        $sheet = file_get_contents(base_path('tests/Fixtures/create/sfx-sheet.wav'));
        Http::fake([
            'https://api.replicate.com/v1/models/elevenlabs/music/predictions' => Http::response(['id' => 'p1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/music.mp3']),
            'https://api.replicate.com/v1/models/stability-ai/stable-audio-2.5/predictions' => Http::response(['id' => 'p2', 'status' => 'processing']),
            'https://api.replicate.com/v1/predictions/p2' => Http::response(['id' => 'p2', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/sfx.wav']),
            'https://replicate.delivery/music.mp3' => Http::response($sheet),
            'https://replicate.delivery/sfx.wav' => Http::response($sheet),
        ]);
        $exec = app(\App\Services\Create\PlanMediaExecutor::class);
        $dir = sys_get_temp_dir().'/audio-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        $music = $exec->produce('music', 'Upbeat electronic, 118 bpm', ['workspace_id' => $this->workspace->id, 'duration_seconds' => 15], $dir);
        $this->assertFileExists($music['path']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'elevenlabs/music') && $r['input']['music_length_ms'] === 16000 && $r['input']['force_instrumental'] === true);
        $sfx = $exec->produce('sfx', 'UI sounds: soft click, card pop, quick whoosh', ['workspace_id' => $this->workspace->id], $dir);
        $this->assertSame(['soft click', 'card pop', 'quick whoosh'], array_column($sfx['cues'], 'name'));
        $this->assertEqualsWithDelta(0.0, $sfx['cues'][0]['start'], 0.05);
        $this->assertEqualsWithDelta(1.3, $sfx['cues'][1]['start'], 0.05);
        $this->assertEqualsWithDelta(2.6, $sfx['cues'][2]['start'], 0.05);
        $ev = fn ($t, $v) => [0 => '', 1 => $t, 2 => (string) $v];
        $ranges = \App\Services\Create\PlanMediaExecutor::cueRanges([$ev('start', 0.1), $ev('end', 0.3), $ev('start', 0.4), $ev('end', 1.5), $ev('start', 1.55), $ev('end', 3.0), $ev('start', 3.3)], 4.0);
        $this->assertSame([[0.0, 0.4], [3.0, 3.3]], $ranges, 'fragments merge into one cue, specks are dropped, and trailing silence adds nothing');
        // A song that ends early is looped with a crossfade to fill the bed.
        $short = $dir.'/short.wav';
        (new \Symfony\Component\Process\Process(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=f=220:d=5', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-filter_complex', '[0][1]concat=n=2:v=0:a=1', '-t', '12', $short]))->mustRun();
        $bed = \App\Services\Create\PlanMediaExecutor::fillMusic($short, 12, $dir);
        $probe = new \Symfony\Component\Process\Process(['ffmpeg', '-hide_banner', '-nostats', '-ss', '8', '-t', '3', '-i', $bed, '-af', 'volumedetect', '-f', 'null', '-']); $probe->run();
        preg_match('/mean_volume: ([-0-9.]+) dB/', $probe->getErrorOutput(), $mv);
        $this->assertGreaterThan(-30, (float) $mv[1], 'the bed is audible after the song would have ended');
    }

    public function test_a_failed_audio_generation_is_reported_not_charged(): void
    {
        config(['services.replicate.api_token' => 'r8-test']);
        Http::fake(['https://api.replicate.com/*' => Http::response(['id' => 'p3', 'status' => 'failed', 'error' => 'Prompt rejected'])]);
        $dir = sys_get_temp_dir().'/audio-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        try { app(\App\Services\Create\PlanMediaExecutor::class)->produce('music', 'x', ['workspace_id' => $this->workspace->id, 'duration_seconds' => 15], $dir); $this->fail('Expected a failure'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Prompt rejected', $e->getMessage()); }
    }

    public function test_a_pose_sheet_keeps_one_character_cuts_out_each_pose_and_stores_every_file(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=');
        $asked = [];
        $this->app->instance(\App\Services\Generation\Image\NanoBananaProImageAdapter::class, new class($asked) extends \App\Services\Generation\Image\NanoBananaProImageAdapter {
            public function __construct(public array &$asked) {}
            public function generate(string $prompt, string $style, string $aspectRatio = '9:16', array $options = []): array { $this->asked[] = [$prompt, $options['reference_image_url'] ?? null]; return ['image_url' => 'https://replicate.delivery/img-'.count($this->asked).'.png']; }
        });
        config(['services.replicate.api_token' => 'r8-test']);
        Http::fake([
            'https://api.replicate.com/v1/models/851-labs/background-remover/predictions' => Http::response(['detail' => 'Not found'], 404),
            'https://api.replicate.com/v1/models/851-labs/background-remover' => Http::response(['latest_version' => ['id' => 'ver123']]),
            'https://api.replicate.com/v1/predictions' => Http::response(['id' => 'bg', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/cut.png']),
            'https://replicate.delivery/cut.png' => Http::response($png),
        ]);
        $dir = sys_get_temp_dir().'/poses-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        $made = app(\App\Services\Create\PlanMediaExecutor::class)->produce('character_poses', 'A small round orange mascot: talking, pointing to the card, surprised', ['workspace_id' => $this->workspace->id], $dir);
        $this->assertSame(['talking', 'pointing to the card', 'surprised'], $made['poses']);
        $this->assertCount(2, $made['extra'], 'one file per pose');
        $this->assertStringStartsWith('A small round orange mascot', $asked[0][0], 'with no saved character or photo, a base character is drawn first');
        $this->assertNull($asked[0][1]);
        $this->assertSame(['https://replicate.delivery/img-1.png'], array_unique(array_column(array_slice($asked, 1), 1)), 'every pose uses the same reference');
        Http::assertSentCount(12);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.replicate.com/v1/predictions' && $r['version'] === 'ver123');
        $this->assertSame(210, \App\Services\Create\CapabilityCatalogue::credits('character_poses', $this->workspace->id));
    }

    public function test_style_routes_pick_a_pack_a_saved_style_a_reference_or_free_design_and_freeze_into_the_run(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'silent']];
        $raw = ['summary' => 'Explainer.', 'scenes' => [], 'left_out' => ''];
        $this->assertContains('product-ui', array_column(\App\Services\Create\StylePacks::catalogue(), 'slug'), 'the built-in packs are listed');
        $pack = $plans->normalize([...$raw, 'style' => ['route' => 'pack', 'pack' => 'kinetic-type', 'why' => 'Words carry this one']], $ctx, $this->workspace->id)['style'];
        $this->assertSame(['pack', 'kinetic-type', 'Kinetic type'], [$pack['route'], $pack['pack'], $pack['name']]);
        $this->assertSame('free', $plans->normalize([...$raw, 'style' => ['route' => 'pack', 'pack' => 'made-up']], $ctx, $this->workspace->id)['style']['route'], 'an unknown pack falls back to free design');
        $this->assertSame('free', $plans->normalize([...$raw, 'style' => ['route' => 'reference']], $ctx, $this->workspace->id)['style']['route'], 'no studied reference, no reference route');
        $this->assertSame('free', $plans->normalize($raw, $ctx, $this->workspace->id)['style']['route'], 'no pick means free design');
        $pinned = $plans->normalize([...$raw, 'style' => ['route' => 'free']], [...$ctx, 'settings' => [...$ctx['settings'], 'style_pack' => 'data-story']], $this->workspace->id)['style'];
        $this->assertSame(['pack', 'data-story'], [$pinned['route'], $pinned['pack']], 'the user\'s pick in the composer wins');
        $saved = $plans->normalize([...$raw, 'style' => ['route' => 'pack', 'pack' => 'kinetic-type']], [...$ctx, 'house_style' => ['name' => 'Our look']], $this->workspace->id)['style'];
        $this->assertSame(['saved', 'Our look'], [$saved['route'], $saved['name']], 'a chosen saved style wins over a planner pick');
        $this->rejected(422, fn () => \App\Services\Create\OutputSettings::normalize(['style_pack' => 'made-up']));

        // The user switches the route on the plan card; the pack's rules and example are frozen into the quote.
        config(['create.planner' => 'offline']);
        $c = $this->brief();
        $p = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-style');
        $plans->select($this->owner, $c->id, $p['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['style' => ['route' => 'pack', 'pack' => 'editorial-frame']]);
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['style' => ['route' => 'saved']]));
        $q = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $frozen = $q->payload_json['style_pack'];
        $this->assertSame(['pack', 'editorial-frame'], [$frozen['route'], $frozen['slug']]);
        $this->assertStringContainsString('# Editorial frame', $frozen['rules']);
        $this->assertNotEmpty($frozen['version']);
    }

    public function test_plan_lines_not_in_the_users_words_are_marked_as_new_wording(): void
    {
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Making video is slow and hard. WyvStudio fixes it.'], ['role' => 'assistant', 'content' => 'reach buyers']],
            'approved_facts' => ['One video, 4 formats'], 'files' => [['reference' => ['page_claims_not_approved' => ['Voiced, captioned, ready-to-post videos']]]]];
        $this->assertSame(['One video, 4 formats, reach more buyers'], \App\Services\Create\PlanService::newWording(
            ['VIDEO? SLOW. HARD.', 'One video, 4 formats', 'Voiced, captioned videos', 'One video, 4 formats, reach more buyers'], $ctx), 'only the line with new words is marked; assistant text is not the user\'s words');
    }

    public function test_delivery_checks_keep_pacing_findings_with_known_codes_only(): void
    {
        $c = \App\Services\Create\RunService::deliveryChecks(['ok' => false, 'pacing' => [
            ['code' => 'reading_time', 'time' => 6.84, 'message' => '"No camera" is fully on screen for 1.8 s'],
            ['code' => 'made_up', 'time' => 1, 'message' => 'x'], 'not an array']]);
        $this->assertSame([['selector' => '', 'time' => 6.84, 'message' => '"No camera" is fully on screen for 1.8 s', 'code' => 'reading_time'],
            ['selector' => '', 'time' => 1.0, 'message' => 'x', 'code' => 'reading_time']], $c['pacing']);
    }

    public function test_the_plan_carries_a_beat_sheet_with_states_and_reads(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'silent']];
        $raw = ['summary' => 'Explainer.', 'left_out' => '', 'scenes' => [
            ['label' => 'Hook', 'start' => 0, 'end' => 2.5, 'idea' => 'A word slams in', 'state_in' => 'Black field', 'state_out' => 'VIDEO? huge, centred', 'reads' => ['Making video is the problem', ' ', 'Too many', 'Reads', 'Here', 'Dropped']],
            ['label' => 'Bad', 'start' => 5, 'end' => 4],
            ['label' => 'Turn', 'start' => 2.5, 'end' => 6, 'idea' => 'The brand lands']]];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $this->assertSame(['Hook', 'Turn'], array_column($p['scenes'], 'label'), 'a beat that ends before it starts is dropped');
        $this->assertSame(['Black field', 'VIDEO? huge, centred', ['Making video is the problem', 'Too many', 'Reads', 'Here']], [$p['scenes'][0]['state_in'], $p['scenes'][0]['state_out'], $p['scenes'][0]['reads']], 'at most four reads, blanks dropped');
        $this->assertSame(['', '', []], [$p['scenes'][1]['state_in'], $p['scenes'][1]['state_out'], $p['scenes'][1]['reads']], 'beats without a director\'s plan still work');
    }

    public function test_the_claude_planner_thinks_at_the_configured_effort(): void
    {
        config(['create.planner_effort' => 'high']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['id' => 'msg_p', 'content' => [['type' => 'text', 'text' => '{"summary":"A plan.","scenes":[]}']], 'usage' => []])
            // A reply cut off before its JSON is retried once at medium effort.
            ->push(['id' => 'msg_a', 'content' => [['type' => 'text', 'text' => 'thinking…']], 'stop_reason' => 'max_tokens', 'usage' => []])
            ->push(['id' => 'msg_b', 'content' => [['type' => 'text', 'text' => '{"summary":"Second try.","scenes":[]}']], 'usage' => []])]);
        $planner = new \App\Services\Create\Planning\AnthropicPlanner('claude-opus-5-5', 'k');
        $this->assertSame('A plan.', $planner->plan(['files' => []])['plan']['summary']);
        Http::assertSent(fn ($r) => $r['output_config']['effort'] === 'high' && $r['max_tokens'] === 12000);
        $this->assertSame('Second try.', $planner->plan(['files' => []])['plan']['summary']);
        Http::assertSent(fn ($r) => $r['output_config']['effort'] === 'medium' && $r['max_tokens'] === 8000);
        $this->assertSame(3, count(Http::recorded()));
    }

    public function test_the_talking_shot_lip_syncs_the_first_line_from_the_talking_pose_and_the_narration(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'original']];
        $raw = ['summary' => 'x', 'left_out' => '', 'narration' => ['Got an idea?'], 'media' => [['kind' => 'talking_shot', 'description' => 'hook'], ['kind' => 'character_poses', 'description' => 'Mascot: talking, waving'], ['kind' => 'voiceover', 'description' => 'n']]];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $this->assertSame(['character_poses', 'voiceover', 'talking_shot'], array_column($p['media'], 'kind'), 'the talking shot is bought after what it is made from');
        $this->assertSame(\App\Services\CreditService::spokespersonCost(4.0), collect($p['media'])->firstWhere('kind', 'talking_shot')['credits']);

        $planId = (string) \Illuminate\Support\Str::uuid();
        $mk = fn (string $type, string $url, string $mime) => Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => $type, 'title' => 't', 'status' => 'active', 'storage_url' => $url, 'mime_type' => $mime]);
        $neutral = $mk('image', 'minio://p/neutral.png', 'image/png'); $talking = $mk('image', 'minio://p/talking.png', 'image/png'); $voice = $mk('audio', 'minio://p/narration.wav', 'audio/wav');
        $row = fn (string $kind, array $record) => DB::table('create_plan_media')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => (string) \Illuminate\Support\Str::uuid(), 'plan_id' => $planId, 'item_index' => $kind === 'voiceover' ? 1 : 0,
            'kind' => $kind, 'description_hash' => 'h', 'status' => 'succeeded', 'asset_id' => $record['file']['asset_id'], 'record_json' => json_encode($record), 'run_id' => null, 'charged_credits' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row('character_poses', ['file' => ['asset_id' => $neutral->id], 'more_files' => [['asset_id' => $talking->id]], 'poses' => ['neutral', 'talking to camera']]);
        $row('voiceover', ['file' => ['asset_id' => $voice->id]]);
        $tmp = sys_get_temp_dir().'/talk-'.\Illuminate\Support\Str::uuid(); mkdir($tmp);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=16000', '-t', '4', $tmp.'/n.wav']);
        $wav = file_get_contents($tmp.'/n.wav');
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('isManagedUrl')->andReturnUsing(fn ($u) => str_starts_with($u, 'minio://'));
        $storage->shouldReceive('get')->with('minio://p/talking.png')->andReturn('PNG-TALKING');
        $storage->shouldReceive('get')->with('minio://p/narration.wav')->andReturn($wav);
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $stt = \Mockery::mock(\App\Services\Media\MediaTranscriptionService::class);
        $stt->shouldReceive('transcribeLocalMediaWithTimestamps')->once()->andReturn(['provider_key' => 'openai', 'words' => [['text' => 'Got', 'start' => 0.3, 'end' => 0.5], ['text' => 'an', 'start' => 0.5, 'end' => 0.6], ['text' => 'idea?', 'start' => 0.6, 'end' => 1.1], ['text' => 'Turn', 'start' => 1.6, 'end' => 1.9]]]);
        $this->app->instance(\App\Services\Media\MediaTranscriptionService::class, $stt);
        config(['services.replicate.api_token' => 'tok']);
        $uploads = 0;
        Http::fake(['api.replicate.com/v1/files' => function () use (&$uploads) { $uploads++; return Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/f'.$uploads]]); },
            'api.replicate.com/v1/models/bytedance/omni-human/predictions' => Http::response(['id' => 'pred_talk1', 'status' => 'starting']),
            'api.replicate.com/v1/predictions/pred_talk1' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/x/talk.mp4']),
            'replicate.delivery/*' => Http::response('MP4BYTES')]);
        $made = app(\App\Services\Create\PlanMediaExecutor::class)->produce('talking_shot', 'hook', ['workspace_id' => $this->workspace->id, 'narration' => ['Got an idea?', 'Turn any idea into a video.'], 'plan_id' => $planId], $tmp);
        $this->assertSame(['video/mp4', 'MP4BYTES', 'Got an idea?', 1.5], [$made['mime'], file_get_contents($made['path']), $made['line'], $made['seconds']], 'the line ends at 1.1 s plus a breath, held to the 1.5 s a lip-sync clip needs');
        $this->assertStringContainsString('Talking shot', $made['title']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'omni-human/predictions') && $r['input']['image'] === 'https://api.replicate.com/v1/files/f1' && $r['input']['audio'] === 'https://api.replicate.com/v1/files/f2');
        $cut = trim(\Illuminate\Support\Facades\Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $tmp.'/line.wav'])->output());
        $this->assertEqualsWithDelta(1.5, (float) $cut, 0.05, 'the uploaded audio is the first line only');
        $this->assertSame(2, $uploads, 'the talking pose was chosen, not the first pose');
    }

    public function test_every_catalogue_item_can_be_made_by_the_executor(): void
    {
        // Priced items are bought by the executor; free sandbox edits (transcript, stabilize, ...) are not.
        $purchasable = array_column(array_filter(\App\Services\Create\CapabilityCatalogue::forWorkspace($this->workspace->id), fn ($t) => (int) $t['credits'] > 0), 'kind');
        $this->assertSame([], array_values(array_diff($purchasable, \App\Services\Create\PlanMediaExecutor::KINDS)), 'a catalogue item the executor cannot make would be dropped from the quote silently');
    }

    public function test_a_build_with_a_character_gets_twenty_calls(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.agent_provider' => 'anthropic', 'create.agent_model' => 'claude-opus-5-5', 'services.anthropic.key' => 'k']);
        $c = $this->brief();
        $plan = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-20');
        $plain = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame(16, $plain->payload_json['execution_policy']['agent']['max_calls']);
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $json['media'] = [['kind' => 'character_poses', 'description' => 'Mascot: talking', 'credits' => 210]];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $withCharacter = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame(20, $withCharacter->payload_json['execution_policy']['agent']['max_calls']);
        $this->assertGreaterThan($plain->credits_max, $withCharacter->credits_max, 'the extra calls are reserved up front');
    }

    public function test_the_planner_sees_reference_frames_and_the_page_capture_as_images(): void
    {
        $sheet = base64_encode('JPEGBYTES');
        $ctx = ['files' => [], 'settings' => [], '_images' => [['label' => 'Reference video "X": 20 frames', 'media_type' => 'image/jpeg', 'data' => $sheet]]];
        $blocks = \App\Services\Create\Planning\PlanPrompt::userContent($ctx);
        $this->assertSame(['text', 'image', 'text'], array_column($blocks, 'type'));
        $this->assertSame($sheet, $blocks[1]['source']['data']);
        $this->assertStringNotContainsString('_images', $blocks[2]['text'], 'pictures never leak into the JSON brief');
        $this->assertStringNotContainsString('JPEGBYTES', \App\Services\Create\Planning\PlanPrompt::user($ctx));
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_i', 'content' => [['type' => 'text', 'text' => '{"summary":"Designed from the frames.","scenes":[]}']], 'usage' => []])]);
        (new \App\Services\Create\Planning\AnthropicPlanner('claude-opus-5-5', 'k'))->plan($ctx);
        Http::assertSent(fn ($r) => is_array($r['messages'][0]['content']) && $r['messages'][0]['content'][1]['type'] === 'image');
    }

    public function test_a_reference_video_gets_a_cached_contact_sheet(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $tmp = sys_get_temp_dir().'/refsheet-'.\Illuminate\Support\Str::uuid(); mkdir($tmp);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=10', '-t', '3', '-pix_fmt', 'yuv420p', $tmp.'/ref.mp4']);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'X · ref', 'status' => 'active', 'storage_url' => 'minio://r/ref.mp4']);
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('get')->with('minio://r/ref.mp4')->once()->andReturn(file_get_contents($tmp.'/ref.mp4'));
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $sheets = app(\App\Services\Create\References\ReferenceSheets::class);
        $path = $sheets->pathFor($asset);
        $this->assertSame('create/references/'.$asset->id.'/sheet.jpg', $path);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($path));
        $this->assertSame($path, $sheets->pathFor($asset), 'the second call reuses the cached sheet without reading the video again');
    }

    public function test_the_plan_carries_art_direction_per_beat_and_a_signature_move(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'silent']];
        $raw = ['summary' => 'x', 'left_out' => '', 'signature_move' => 'A giant-type wipe of FLOW into the dashboard beat',
            'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 3, 'layout' => 'Two columns: bust left at half height, headline right', 'field' => '#0E0B12']]];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $this->assertSame(['Two columns: bust left at half height, headline right', '#0E0B12'], [$p['scenes'][0]['layout'], $p['scenes'][0]['field']]);
        $this->assertSame('A giant-type wipe of FLOW into the dashboard beat', $p['signature_move']);
    }

    public function test_design_first_makes_a_cheap_look_run_and_approving_it_builds_the_motion(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.agent_provider' => 'anthropic', 'create.agent_model' => 'claude-opus-5-5', 'services.anthropic.key' => 'k']);
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $plan = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-look');
        $this->assertFalse($plan['plan']['selections']['look_first'], 'the offline planner does not ask for a look run');
        $plans->select($this->owner, $c->id, $plan['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['look_first' => true]);
        $q = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame([true, false, 8], [$q->payload_json['look_first'], $q->payload_json['from_look'], $q->payload_json['execution_policy']['agent']['max_calls']], 'the look run is short');

        // The look version is recorded as such; approving it quotes the motion build from it.
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-look', true);
        $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'The look', 'bundle' => ['index.html' => '<html>look</html>']], 'private/look.mp4', 'h1');
        $head = DB::table('composition_revisions')->where('run_id', $run->id)->first();
        $this->assertTrue(json_decode($head->metadata_json, true)['look']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Approve the look and build the motion.', 'expected_version' => (int) $this->conversations->conversation($this->owner, $c->id)->version, 'idempotency_key' => 'm-approve']);
        $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-motion');
        $q2 = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame([false, true], [$q2->payload_json['look_first'], $q2->payload_json['from_look']]);
        $this->assertSame(['index.html' => '<html>look</html>'], $q2->payload_json['base_bundle'], 'the motion is built from the approved stills');
        $this->assertSame(16, $q2->payload_json['execution_policy']['agent']['max_calls']);
    }

    public function test_notes_on_a_version_feed_the_next_plan_and_build_in_that_style(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.agent_provider' => 'anthropic', 'create.agent_model' => 'claude-opus-5-5', 'services.anthropic.key' => 'k', 'create.pilot_budget_microusd' => 60_000_000]);
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $plan = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-notes');
        $plans->select($this->owner, $c->id, $plan['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['style' => ['route' => 'pack', 'pack' => 'kinetic-type']]);
        $q = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame([], $q->payload_json['style_notes']);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-notes', true);
        $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<html></html>'],
            'review' => [['time' => 1, 'score' => 9, 'problems' => []], ['time' => 7, 'score' => 6, 'problems' => ['Word too small', 'x', 'y', 'dropped fourth']], 'junk']], 'private/v1.mp4', 'h');
        $rev = DB::table('composition_revisions')->where('run_id', $run->id)->first();
        $this->assertEquals([['time' => 1, 'score' => 9, 'problems' => []], ['time' => 7, 'score' => 6, 'problems' => ['Word too small', 'x', 'y']]], json_decode($rev->metadata_json, true)['review'], 'review scores travel with the version, bounded');

        $notes = app(\App\Services\Create\StyleNotes::class);
        $out = $notes->add($this->owner, $c->id, $rev->id, '  Loved the field flip; the last line was too small.  ');
        $this->assertSame('pack:kinetic-type', $out['style_key']);
        $this->assertSame(['Loved the field flip; the last line was too small.'], $out['notes']);
        $this->rejected(422, fn () => $notes->add($this->owner, $c->id, $rev->id, '   '));

        // The next plan and quote in that style carry the note.
        $this->conversations->message($this->owner, $c->id, ['content' => 'Another one in the same style.', 'expected_version' => (int) $this->conversations->conversation($this->owner, $c->id)->version, 'idempotency_key' => 'm-notes']);
        $plan2 = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-notes-2');
        $plans->select($this->owner, $c->id, $plan2['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['style' => ['route' => 'pack', 'pack' => 'kinetic-type']]);
        $q2 = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame(['Loved the field flip; the last line was too small.'], $q2->payload_json['style_notes']);
        $this->assertSame(['pack:kinetic-type' => ['Loved the field flip; the last line was too small.']], $notes->all($this->workspace->id));
    }

    public function test_a_studied_reference_becomes_a_pack_with_a_fingerprint(): void
    {
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'X · ref', 'storage_url' => 'create-upload://x', 'status' => 'active',
            'metadata_json' => ['reference_analysis' => ['average_shot_seconds' => 4.5, 'notes' => ['summary' => 'A halftone mascot explainer', 'look' => 'Flat fields', 'palette' => ['#FFFFFF'],
                'fingerprint' => ['structure' => 'hook, store, dashboard, tagline, lockup', 'opening' => 'whispered character hook', 'signature_shot' => 'giant-type wipe into the dashboard', 'camera_path' => 'static two-column grid', 'score_shape' => 'punchy voice over a bed', 'ending' => 'logo lockup on white'],
                'recipes' => ['giant-type wipe', 'stamp', 'field flip']]]]]);
        $pack = \App\Services\Create\StylePacks::resolve(['route' => 'reference', 'name' => 'From your reference'], $this->workspace->id, [], [$asset->id]);
        $this->assertSame('reference', $pack['route']);
        $this->assertStringContainsString('differ from it on at least four', $pack['fingerprint']);
        $this->assertStringContainsString('- Signature shot: giant-type wipe into the dashboard', $pack['fingerprint']);
        $this->assertStringContainsString('Moves worth building (see the motion kit): giant-type wipe; stamp; field flip', $pack['rules']);
        $saved = app(\App\Services\Create\StyleService::class)->fromReference($this->owner, $asset->id, 'Pocket look');
        $this->assertSame(['giant-type wipe', 'stamp', 'field flip'], $saved['style']['recipes']);
        $this->assertSame('logo lockup on white', $saved['style']['fingerprint']['ending']);
    }

    public function test_the_gateway_runs_tool_mode_with_a_hashed_history_and_returns_tool_calls(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $attempts = app(\App\Services\Create\AttemptService::class);
        $input = json_decode($run->input_json, true); $input['mode'] = 'agent';
        $input['execution_policy']['agent'] = ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'credits' => 75, 'cost_limit_microusd' => 300000, 'max_calls' => 3];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['authorized_credits' => 225, 'reserved_credits' => 225]);
        $this->workspace->update(['credits_monthly' => 1000]);
        config(['create.paid_execution_enabled' => true, 'create.pilot_budget_id' => 'test-pilot', 'create.pilot_budget_microusd' => 5000000, 'services.anthropic.key' => 'test-key', 'create.worker_token' => str_repeat('a', 64)]);
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => '{"context":{}}']]]];
        $tools = [['name' => 'write', 'description' => 'Write a file', 'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]]];
        $messagesJson = json_encode($messages, JSON_UNESCAPED_SLASHES); $toolsJson = json_encode($tools, JSON_UNESCAPED_SLASHES);
        // The worker hashes {prompt, system, maxTokens, image, messagesJson, toolsJson} as JSON; the gateway must agree.
        $hash = hash('sha256', json_encode(['prompt' => 'tool-mode call 1', 'system' => 'sys', 'maxTokens' => 4096, 'image' => null, 'messagesJson' => $messagesJson, 'toolsJson' => $toolsJson], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
        $a = $attempts->begin($run->id, $claim['lease_token'], 'agent-1', 'agent', $hash);
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_tool', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'content' => [['type' => 'text', 'text' => 'Writing.'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'write', 'input' => ['path' => 'index.html']], ['type' => 'server_tool_use', 'id' => 'x']]])]);
        $out = app(\App\Services\Create\AnthropicGateway::class)->complete($run->id, $claim['lease_token'], $a['id'], ['prompt' => 'tool-mode call 1', 'system' => 'sys', 'max_tokens' => 4096, 'image' => null, 'messages_json' => $messagesJson, 'tools_json' => $toolsJson]);
        $this->assertSame(['text', 'tool_use'], array_column($out['content'], 'type'), 'content blocks come back; unknown block types are dropped');
        $this->assertSame('tool_use', $out['stop_reason']);
        Http::assertSent(fn ($r) => $r['messages'] === $messages && $r['tools'] === $tools && ! isset($r['messages'][0]['content'][1]));
        // A history with an unknown block type is refused before any call.
        $b = $attempts->begin($run->id, $claim['lease_token'], 'agent-2', 'agent', 'h2');
        $this->rejected(409, fn () => app(\App\Services\Create\AnthropicGateway::class)->complete($run->id, $claim['lease_token'], $b['id'], ['prompt' => 'p', 'system' => 'sys', 'max_tokens' => 4096, 'image' => null, 'messages_json' => json_encode([['role' => 'user', 'content' => [['type' => 'document']]]]), 'tools_json' => '[]']));
        $this->rejected(422, fn () => \App\Services\Create\AnthropicGateway::checkToolMessages([['role' => 'user', 'content' => [['type' => 'document']]]], []));
        $this->rejected(422, fn () => \App\Services\Create\AnthropicGateway::checkToolMessages([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]]], [['name' => 'Bad Name', 'input_schema' => []]]));
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
        // A refused call is recorded against its request id and costs nothing; the worker is told so.
        $b=$attempts->begin($run->id,$claim['lease_token'],'agent-2','agent',$hash);
        $this->rejected(503,fn()=>$gateway->complete($run->id,$claim['lease_token'],$b['id'],$call));
        $row=DB::table('composition_attempts')->where('id',$b['id'])->first();
        $this->assertSame(['failed','req_011refused',0],[$row->status,$row->prediction_id,(int)$row->charged_credits]);
        $this->assertSame(975,(int)$this->workspace->fresh()->credits_monthly);
        // Over HTTP, surrounding whitespace survives the app's string trimming, so the hash still matches.
        $raw=['prompt'=>"  Build it\n",'system'=>"Rules/1\n",'max_tokens'=>1024,'image'=>null];
        $c=$attempts->begin($run->id,$claim['lease_token'],'agent-3','agent',hash('sha256',json_encode(['prompt'=>$raw['prompt'],'system'=>$raw['system'],'maxTokens'=>1024,'image'=>null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
        $this->withToken(str_repeat('a',64))->postJson('/api/internal/create/runs/'.$run->id.'/attempts/'.$c['id'].'/anthropic',[...$raw,'lease_token'=>$claim['lease_token']])
            ->assertOk()->assertJsonPath('data.text','ok')->assertJsonPath('data.message_id','msg_02def');
    }

    public function test_gateway_retries_a_failed_connection_and_records_an_unreachable_call_as_not_sent(): void
    {
        [, , $run]=$this->admitted(); $claim=$this->runs->claim(); $attempts=app(\App\Services\Create\AttemptService::class);
        $input=json_decode($run->input_json,true);$input['mode']='agent';
        $input['execution_policy']['agent']=['provider'=>'anthropic','model'=>'claude-opus-5-5','credits'=>75,'cost_limit_microusd'=>300000,'max_calls'=>3];
        DB::table('composition_runs')->where('id',$run->id)->update(['input_json'=>json_encode($input)]);
        DB::table('api_operations')->where('id',$run->operation_id)->update(['authorized_credits'=>225,'reserved_credits'=>225]);
        $this->workspace->update(['credits_monthly'=>1000]);
        config(['create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>'test-pilot','create.pilot_budget_microusd'=>5000000,'services.anthropic.key'=>'test-key']);
        $call=['prompt'=>'p','system'=>'s','max_tokens'=>1024,'image'=>null];
        $hash=hash('sha256',json_encode(['prompt'=>'p','system'=>'s','maxTokens'=>1024,'image'=>null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $gateway=app(\App\Services\Create\AnthropicGateway::class);
        $tries=0;
        Http::fake(function () use (&$tries) {
            if (++$tries === 1) throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Failed to connect to api.anthropic.com port 443 after 10000 ms');
            return Http::response(['id'=>'msg_ok','content'=>[['type'=>'text','text'=>'ok']],'usage'=>['input_tokens'=>100,'output_tokens'=>10]]);
        });
        $a=$attempts->begin($run->id,$claim['lease_token'],'agent-1','agent',$hash);
        $this->assertSame('ok',$gateway->complete($run->id,$claim['lease_token'],$a['id'],$call)['text'],'a blip is retried');
        $this->assertSame(2,$tries);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to api.anthropic.com'));
        $b=$attempts->begin($run->id,$claim['lease_token'],'agent-2','agent',$hash);
        $this->rejected(503,fn()=>$gateway->complete($run->id,$claim['lease_token'],$b['id'],$call));
        $row=DB::table('composition_attempts')->where('id',$b['id'])->first();
        $this->assertSame(['failed',0],[$row->status,(int)$row->charged_credits],'never sent, never charged, nothing held');
        $this->assertFalse(\App\Services\Create\AttemptService::unresolved($run->id));
    }

    public function test_pilot_policy_switches_the_build_agent_to_the_claude_gateway(): void
    {
        $this->pilot(); config(['create.agent_provider'=>'replicate']);
        $this->assertSame('replicate',\App\Services\Create\PilotPolicy::execution([])['agent']['provider']);
        config(['create.agent_provider'=>'anthropic','create.agent_model'=>'claude-opus-5-5','services.anthropic.key'=>'']);
        $this->rejected(503,fn()=>\App\Services\Create\PilotPolicy::execution([]));
        config(['services.anthropic.key'=>'k']);
        $agent=\App\Services\Create\PilotPolicy::execution([])['agent'];
        $this->assertSame(['anthropic','claude-opus-5-5',450000,16384,'medium'],[$agent['provider'],$agent['model'],$agent['cost_limit_microusd'],$agent['max_output_tokens'],$agent['effort']]);
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
