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
        (require database_path('migrations/2026_10_03_120000_add_create_dispatch_journal.php'))->up();
        (require database_path('migrations/2026_10_03_150000_create_composition_trace_events.php'))->up();
        (require database_path('migrations/2026_10_03_160000_widen_create_plan_media_item_index.php'))->up();
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

    public function test_character_requirements_keep_user_evidence_and_reject_invented_quotes(): void
    {
        $plan = app(\App\Services\Create\PlanService::class)->normalize([
            'summary' => 'Redraw Maya in halftone.', 'character_style' => 'halftone illustration',
            'requirements' => [['text' => 'Maya is a halftone illustration', 'source_quote' => 'make Maya halftone'],
                ['text' => 'Use a red background', 'source_quote' => 'red background']],
        ], ['settings' => ['output_kind' => 'video', 'duration_seconds' => 15], 'files' => [], 'messages' => [
            ['role' => 'user', 'content' => 'Please make Maya halftone and preserve her outfit.'],
            ['role' => 'assistant', 'content' => 'red background'],
        ]], (int) $this->workspace->id);
        $this->assertCount(1, $plan['requirements']);
        $this->assertSame('make Maya halftone', $plan['requirements'][0]['source_quote']);
        $this->assertSame('halftone illustration', $plan['character_style']);
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
        $blank = ['state_in' => '', 'state_out' => '', 'reads' => [], 'layout' => '', 'field' => '', 'uses' => []];
        $this->assertEquals([['label' => 'Hook', 'start' => 0.0, 'end' => 4.0, 'idea' => 'Take', ...$blank], ['label' => 'Too long', 'start' => 10.0, 'end' => 15.0, 'idea' => 'clamped', ...$blank]], array_map(fn ($scene) => array_diff_key($scene, array_flip(['id', 'requirement_ids'])), $plan['scenes']));
        $this->assertCount(2, array_unique(array_column($plan['scenes'], 'id')));
        $this->assertCount(1, $plan['decisions'], 'a decision with one option is dropped');
        $this->assertSame('introstyle', $plan['decisions'][0]['id']);
        $gen = $plan['decisions'][0]['options'][1];
        $this->assertSame(\App\Services\CreditService::animationCost('quick', '480p', 5), $gen['credits'], 'price comes from the catalogue, not the model');
        $this->assertSame(['ai_image'], array_column($plan['media'], 'kind'), 'unknown tools are dropped');
        $this->assertSame(app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null), $plan['media'][0]['credits']);
        // The hole between 4 s and 10 s sends the plan back once for repair, so two calls are recorded.
        $this->assertSame(1800, json_decode(DB::table('create_plans')->where('id', $p['id'])->value('usage_json'), true)['cache_read_tokens']);
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
        // A file the run action generated from scratch has no parent; only run may do that.
        $made = \Illuminate\Http\UploadedFile::fake()->createWithContent('sprite.png', $png.'x');
        $this->rejected(422, fn () => $this->runs->derived($run->id, $lease, $made, null, 'grade', []));
        $generated = $this->runs->derived($run->id, $lease, $made, null, 'run', ['cmd' => 'node', 'args' => 'sprite.mjs']);
        $this->assertNull($generated['derived_from_asset_id']);
        $this->assertStringStartsWith('Run · generated', Asset::find($generated['asset_id'])->title);

        // Finish; the next run inherits the derived file alongside the original source.
        $this->runs->finish($run->id, $lease, ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<img src="'.$record['name'].'">']], 'private/v1.mp4', 'h');
        $head = $this->conversations->conversation($this->owner, $c->id)->head_revision_id;
        $inherited = app(\App\Services\Create\InputSnapshotService::class)->inherited($c->id, $head);
        $this->assertEqualsCanonicalizing([$source->id, $record['asset_id'], $generated['asset_id']], array_column($inherited, 'asset_id'));
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
        // Media is approved as a ceiling: 1.5x the estimate, with room for six more purchases.
        $this->assertSame([(int) ceil(($imageCredits + 0 + 3) * 1.5), 3 + 6], [$media['total_credits'], $media['max_calls']]);
        $agent = $q->payload_json['execution_policy']['agent'];
        // The critic comes with the Anthropic build policy; a Replicate build has none.
        $critic = $q->payload_json['execution_policy']['critic'] ?? ['credits' => 0, 'max_calls' => 0];
        if ($agent['provider'] === 'anthropic') $this->assertSame([2, 'low'], [$critic['max_calls'], $critic['effort']], 'two low-effort critic rounds come with every paid build');
        $this->assertSame($agent['credits'] * $agent['max_calls'] + $critic['credits'] * $critic['max_calls'] + $media['total_credits'], (int) $q->credits_max, 'one approval covers the build, the critic and the media ceiling');

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
        $replay = $service->produce($run->id, $claim['lease_token'], 0);
        $this->assertSame([true, 0], [$replay['reused'], $replay['charged_credits']], 'a replayed request never charges again');
        $this->rejected(404, fn () => $service->produce($run->id, $claim['lease_token'], 9));
        $after = (int) $this->workspace->fresh()->credits_monthly + (int) $this->workspace->fresh()->credits_topup;
        $this->assertSame($imageCredits, $before - $after, 'only the successful paid item was charged');

        // The build stops before voice generation; another approved run reuses known successful media.
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Stopped'], null, null);
        $q2 = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $run2 = $this->conversations->approve($this->owner, $c->id, $q2->id, 'approve-pm-2', true);
        $claim2 = $this->runs->claim();
        $again = $service->produce($run2->id, $claim2['lease_token'], 0);
        $this->assertSame([true, 0, $image['file']['asset_id']], [$again['reused'], $again['charged_credits'], $again['file']['asset_id']]);
        $this->assertSame($image['file']['sha256'], $this->runs->inputFile($run2->id, $claim2['lease_token'], $image['file']['asset_id'])['sha256']);
        $this->rejected(409, fn () => $service->produce($run2->id, $claim2['lease_token'], 2));
        $this->assertSame('needs_attention', DB::table('composition_runs')->where('id', $run2->id)->value('status'));
        $this->assertSame('unknown', DB::table('create_plan_media')->where('plan_id', $plan['id'])->where('kind', 'voiceover')->value('status'));
        $this->assertSame(['ai_image', 'stock_image', 'voiceover'], $calls, 'the image was not made twice and uncertain voice was not retried');
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
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<html></html>'], 'delivery_checks' => $checks, 'creative_review' => ['status' => 'passed', 'findings' => ['Fix the headline'], 'unknown' => 'discard']], 'private/v1.mp4', 'h');
        $meta = json_decode(DB::table('composition_revisions')->where('run_id', $run->id)->value('metadata_json'), true);
        $this->assertEquals(['ok' => false, 'safe_area' => [['selector' => '#cta', 'time' => 13.46, 'message' => 'Collides with the caption band']], 'edges' => [], 'contrast' => [],
            'pacing' => [], 'loudness' => ['status' => 'levelled', 'lufs' => -14.0, 'from' => -23.4, 'peak' => -1.6]], $meta['delivery_checks']);
        $this->assertNull(\App\Services\Create\RunService::deliveryChecks('nope'));
        $heard = \App\Services\Create\RunService::deliveryChecks(['ok' => true, 'audio' => ['ok' => false, 'problems' => ['The music may be too loud under the voice.', 42], 'script_coverage' => 0.96123, 'mix' => ['secret' => 1]]]);
        $this->assertSame(['ok' => false, 'problems' => ['The music may be too loud under the voice.'], 'script_coverage' => 0.961], $heard['audio'], 'what was heard is kept in plain words only');
        $this->assertSame(['status' => 'incomplete', 'findings' => ['Fix the headline']], $meta['creative_review']);
        $this->assertSame('incomplete', RunService::creativeReview(null)['status']);
        $this->assertSame('passed', RunService::creativeReview(['status' => 'passed'])['status']);
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
        // A legacy interrupted close may leave the job pending despite a real receipt.
        DB::table('api_operation_jobs')->where('id', 'create-call-'.$a['id'])->update(['status' => 'pending']);
        $this->assertSame('failed', $service->closeSettled($run->id, true)['status']);
        $this->assertSame('completed', DB::table('api_operation_jobs')->where('id', 'create-call-'.$a['id'])->value('status'));
        $this->assertSame(0, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        $this->assertNotSame('needs_attention', DB::table('api_operations')->where('id', $run->operation_id)->value('status'));
        // An unresolved call still requires a verified receipt.
        [, , $run2] = $this->admitted(); $claim2 = $this->runs->claim();
        $b = $attempts->begin($run2->id, $claim2['lease_token'], 'render-1', 'render', str_repeat('b', 64));
        DB::table('composition_runs')->where('id', $run2->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->runs->claim();
        $this->rejected(409, fn () => $service->closeSettled($run2->id, true));
        // A manual status change without a receipt is NOT a settlement.
        DB::table('composition_attempts')->where('id', $b['id'])->update(['status' => 'failed', 'cost_microusd' => 0]);
        $this->rejected(409, fn () => $service->closeSettled($run2->id, true));
    }

    public function test_stopped_run_releases_only_unstarted_allowance_and_keeps_uncertain_call_ceiling(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $a = app(\App\Services\Create\AttemptService::class)->begin($run->id, $claim['lease_token'], 'legacy-call', 'render', str_repeat('a', 64));
        DB::table('composition_attempts')->where('id', $a['id'])->update(['status' => 'failed', 'credit_limit' => 210, 'cost_microusd' => 0]);
        DB::table('composition_runs')->where('id', $run->id)->update(['status' => 'failed']);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['authorized_credits' => 1037, 'reserved_credits' => 1037]);
        $balance = $this->workspace->fresh()->creditsBalance();
        $service = app(\App\Services\Create\ReconciliationService::class);
        $this->rejected(403, fn () => $service->releaseUnstarted($run->id, false));
        $this->assertSame(['released_credits' => 827, 'retained_credits' => 210], $service->releaseUnstarted($run->id, true));
        $this->assertSame(['released_credits' => 0, 'retained_credits' => 210], $service->releaseUnstarted($run->id, true));
        $this->assertSame($balance, $this->workspace->fresh()->creditsBalance());
        $op = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $this->assertSame(['needs_attention', 0, 210], [$op->status, (int) $op->capacity_slots, (int) $op->reserved_credits]);
        $this->assertNull(DB::table('composition_runs')->where('id', $run->id)->value('lease_hash'));
        $this->assertSame('pending', DB::table('api_operation_jobs')->where('id', 'create-call-'.$a['id'])->value('status'));
        $this->rejected(409, fn () => $service->closeSettled($run->id, true));
        // A dependency outside the attempt journal blocks narrowing the reservation.
        DB::table('api_operation_jobs')->insert(['id' => 'unmapped', 'operation_id' => $op->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->rejected(409, fn () => $service->releaseUnstarted($run->id, true));
        $this->assertSame(210, (int) DB::table('api_operations')->where('id', $op->id)->value('reserved_credits'));
    }

    public function test_create_credit_breakdown_and_shortfall_include_existing_reservations(): void
    {
        [$c, , $run] = $this->admitted();
        DB::table('composition_runs')->where('id', $run->id)->update(['status' => 'failed']);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['reserved_credits' => 1889]);
        $this->workspace->update(['credits_monthly' => 0, 'credits_topup' => 2510]);
        $breakdown = ['total' => 2510, 'reserved' => 1889, 'available' => 621];
        $this->assertSame($breakdown, $this->conversations->creditAvailability($this->owner));
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner)->getJson('/api/v1/create/conversations/'.$c->id)->assertOk()->assertJsonPath('data.credit_availability', $breakdown)
            ->assertJsonPath('data.runs.0.held_credits', 1889); // what this run holds, shown beside an active build
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->actingAs($this->owner)->postJson('/api/v1/create/conversations/'.$c->id.'/quotes', ['expected_version' => $version])->assertOk()->assertJsonPath('data.credit_availability', $breakdown);
        $q = $this->conversations->quote($this->owner, $c->id, $version);
        $q->update(['credits_max' => 625]);
        try {
            $this->conversations->approve($this->owner, $c->id, $q->id, 'shortfall');
            $this->fail('Insufficient available credits should prevent approval.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(402, $e->getResponse()->getStatusCode());
            $json = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame($breakdown, $json['credit_availability']);
            $this->assertSame(4, $json['shortfall']);
            $this->assertStringContainsString('621 available (2,510 total; 1,889 reserved', $json['message']);
        }
        $this->assertNull($q->fresh()->consumed_at);
        $this->assertSame(1, DB::table('composition_runs')->count());
        $this->assertSame(2510, $this->workspace->fresh()->creditsBalance());
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

    public function test_character_master_is_single_and_all_later_poses_use_its_exact_bytes(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=');
        $asked = [];
        $this->app->instance(\App\Services\Generation\Image\NanoBananaProImageAdapter::class, new class($asked) extends \App\Services\Generation\Image\NanoBananaProImageAdapter {
            public function __construct(public array &$asked) {}
            public function generate(string $prompt, string $style, string $aspectRatio = '9:16', array $options = []): array { $this->asked[] = [$prompt, $options]; return ['image_url' => 'https://replicate.delivery/img-'.count($this->asked).'.png']; }
        });
        config(['services.replicate.api_token' => 'r8-test']);
        $uploads = 0;
        Http::fake([
            'https://api.replicate.com/v1/files' => function () use (&$uploads) { return Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/ref-'.(++$uploads)]]); },
            'https://api.replicate.com/v1/models/851-labs/background-remover/predictions' => Http::response(['detail' => 'Not found'], 404),
            'https://api.replicate.com/v1/models/851-labs/background-remover' => Http::response(['latest_version' => ['id' => 'ver123']]),
            'https://api.replicate.com/v1/predictions' => Http::response(['id' => 'bg', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/cut.png']),
            'https://replicate.delivery/cut.png' => Http::response($png),
        ]);
        $dir = sys_get_temp_dir().'/poses-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        file_put_contents($dir.'/identity.png', $png); file_put_contents($dir.'/style.png', $png);
        $description = 'A small round orange mascot: talking, pointing to the card, surprised';
        $executor = app(\App\Services\Create\PlanMediaExecutor::class);
        $made = $executor->produce('character_poses', $description, ['workspace_id' => $this->workspace->id,
            'source_images' => [$dir.'/identity.png'], 'character_style_images' => [$dir.'/style.png'], 'character_style' => 'halftone puppet'], $dir);
        $this->assertCount(1, $asked, 'the storyboard buys only one master, not a pose sheet');
        $this->assertSame([], $made['extra']);
        $this->assertSame(\App\Services\Create\CharacterApproval::CONTRACT, $made['character_contract']);
        $this->assertSame(['https://api.replicate.com/v1/files/ref-1', 'https://api.replicate.com/v1/files/ref-2'], $asked[0][1]['reference_image_urls']);
        $this->assertStringContainsString('Image 1 supplies identity only', $asked[0][0]);
        $this->assertStringContainsString('STYLE REFERENCES ONLY', $asked[0][0]);
        $this->assertStringContainsString('halftone puppet', $asked[0][0]);
        $path = 'create/uploads/master-test-'.\Illuminate\Support\Str::uuid().'.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $png);
        $file = ['storage_path' => $path, 'bytes' => strlen($png), 'sha256' => hash('sha256', $png), 'mime_type' => 'image/png'];
        $variants = $executor->produce('character_variants', $description, ['approved_character_files' => [$file]], $dir);
        $this->assertCount(4, $asked);
        $this->assertSame(['talking', 'pointing to the card', 'surprised'], $variants['poses']);
        $this->assertCount(2, $variants['extra']);
        foreach (array_slice($asked, 1) as [$prompt, $options]) {
            $this->assertSame('https://api.replicate.com/v1/files/ref-3', $options['reference_image_url']);
            $this->assertStringContainsString('Change only pose/expression', $prompt);
        }
        $this->assertSame($file['sha256'], $variants['master_sha256']);
        $this->assertSame(35, \App\Services\Create\CapabilityCatalogue::credits('character_poses', $this->workspace->id));
    }

    public function test_brand_palettes_only_expose_workspace_colours(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('brand_kits')) {
            \Illuminate\Support\Facades\Schema::create('brand_kits', function ($table) {
                $table->id(); $table->unsignedBigInteger('workspace_id'); $table->string('name');
                $table->string('primary_color')->nullable(); $table->string('secondary_color')->nullable(); $table->string('accent_color')->nullable();
            });
        }
        DB::table('brand_kits')->insert([
            ['workspace_id' => $this->workspace->id, 'name' => 'Local', 'primary_color' => '#123abc', 'secondary_color' => 'invalid'],
            ['workspace_id' => $this->workspace->id + 1000, 'name' => 'Other', 'primary_color' => '#ff0000', 'secondary_color' => null],
        ]);
        $kits = \App\Services\Create\CapabilityCatalogue::brandPalettes($this->workspace->id);
        $this->assertCount(1, $kits);
        $this->assertSame('Local', $kits[0]['name']);
        $this->assertSame(['primary_color' => '#123abc'], $kits[0]['colours']);
    }

    public function test_colour_treatment_survives_replanning_and_quote_handoff(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['audio' => 'silent']];
        $treatment = ['source' => 'user', 'source_note' => 'Use cream with a fixed blue accent',
            'roles' => ['background' => ['hex' => '#fff'], 'accent' => ['hex' => '#123ABC', 'locked' => true]], 'usage' => 'Light fields; blue for the call to action'];
        $p = $plans->normalize(['summary' => 'Test', 'colour_treatment' => $treatment], $ctx, $this->workspace->id);
        $this->assertSame('#FFFFFF', $p['colour_treatment']['roles']['background']['hex']);
        $this->assertTrue($p['colour_treatment']['roles']['accent']['locked']);
        $editCtx = [...$ctx, 'previous_plan' => ['colour_treatment' => $p['colour_treatment']]];
        $same = $plans->normalize(['summary' => 'Shorten the ending'], $editCtx, $this->workspace->id);
        $this->assertSame($p['colour_treatment'], $same['colour_treatment']);
        $changed = $plans->normalize(['summary' => 'Change the background', 'colour_treatment' => [
            'source' => 'user', 'roles' => ['background' => ['hex' => '#eee'], 'accent' => ['hex' => '#ff0000', 'locked' => false]]]], $editCtx, $this->workspace->id);
        $this->assertSame('#EEEEEE', $changed['colour_treatment']['roles']['background']['hex']);
        $this->assertSame($p['colour_treatment']['roles']['accent'], $changed['colour_treatment']['roles']['accent']);
        $bad = $plans->normalize(['summary' => 'Test', 'colour_treatment' => ['roles' => ['background' => ['hex' => 'url(https://example.com)']]]], $ctx, $this->workspace->id);
        $this->assertNull($bad['colour_treatment']);
        config(['create.planner' => 'offline']);
        $c = $this->brief();
        $made = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'colour-plan');
        $stored = $made['plan']; $stored['colour_treatment'] = $p['colour_treatment'];
        DB::table('create_plans')->where('id', $made['id'])->update(['plan_json' => json_encode($stored)]);
        $quote = \App\Services\Create\PlanService::forQuote($this->conversations->conversation($this->owner, $c->id));
        $this->assertSame($p['colour_treatment'], $quote['colour_treatment']);
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
        $this->assertSame(['pack', 'Kinetic type'], [$saved['route'], $saved['name']], 'a saved default does not override a deliberate planner route');
        $house = [...$ctx, 'house_style' => ['name' => 'Our look']];
        $this->assertSame('saved', $plans->normalize($raw, $house, $this->workspace->id)['style']['route'], 'use the saved default when the planner supplies no route');
        $this->assertSame('free', $plans->normalize([...$raw, 'style' => ['route' => 'free']], $house, $this->workspace->id)['style']['route'], 'an explicit free treatment survives a saved default');
        $reference = [...$house, 'files' => [['purpose' => 'reference', 'reference' => ['look' => 'Light editorial']]]];
        $this->assertSame('reference', $plans->normalize([...$raw, 'style' => ['route' => 'reference']], $reference, $this->workspace->id)['style']['route'], 'a studied reference survives a saved default');
        $this->assertSame('saved', $plans->normalize([...$raw, 'style' => ['route' => 'saved']], $house, $this->workspace->id)['style']['route'], 'the planner may still choose the saved style');
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

    public function test_requirements_survive_saved_followups_and_link_to_frozen_scene_and_media_tasks(): void
    {
        config(['create.planner' => 'anthropic', 'create.mode' => 'agent', 'services.anthropic.key' => 'fake']);
        $reply = ['summary' => 'A halftone mascot and title',
            'requirements' => [['id' => 'style', 'text' => 'Halftone mascot', 'source_quote' => 'halftone mascot', 'category' => 'appearance'],
                ['id' => 'title', 'text' => 'Show Offer', 'source_quote' => 'Show Offer', 'category' => 'text']],
            'scenes' => [['label' => 'Offer', 'start' => 0, 'end' => 15, 'idea' => 'Mascot with title', 'requirement_ids' => ['style', 'title']]],
            'media' => [['kind' => 'ai_image', 'description' => 'A halftone mascot', 'requirement_ids' => ['style']]]];
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['id' => 'first', 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'usage' => []])
            ->push(['id' => 'second', 'content' => [['type' => 'text', 'text' => '{"summary":"Make it calmer","scenes":[]}']], 'usage' => []])]);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Use a halftone mascot. Show Offer', 'expected_version' => 0, 'idempotency_key' => 'req-brief']);
        $service = app(\App\Services\Create\PlanService::class);
        $first = $service->propose($this->owner, $c->id, 1, 'req-plan-1');
        $plan = $first['plan']; $ids = array_column($plan['requirements'], 'id');
        $this->assertCount(2, $ids);
        $this->assertSame($ids, $plan['scenes'][0]['requirement_ids']);
        $frozen = \App\Services\Create\PlanService::quotePlan($plan, $first['id']);
        $this->assertSame([$ids[0]], $frozen['media'][0]['requirement_ids']);
        $this->assertSame('Halftone mascot', $frozen['media'][0]['requirements'][0]['text']);
        $this->assertStringStartsWith('task-', $frozen['media'][0]['id']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Make it calmer', 'expected_version' => 2, 'idempotency_key' => 'req-followup']);
        $second = $service->propose($this->owner, $c->id, 3, 'req-plan-2');
        $this->assertSame($ids, array_column($second['plan']['requirements'], 'id'));
        $quote = \App\Services\Create\PlanService::quotePlan($second['plan'], $second['id']);
        $this->assertSame($ids, array_column($quote['requirements'], 'id'));
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
        Http::assertSent(fn ($r) => $r['output_config']['effort'] === 'high' && $r['max_tokens'] === 24000);
        $this->assertSame('Second try.', $planner->plan(['files' => []])['plan']['summary']);
        Http::assertSent(fn ($r) => $r['output_config']['effort'] === 'medium' && $r['max_tokens'] === 16000);
        $this->assertSame(3, count(Http::recorded()));
    }

    public function test_native_talking_uses_script_image_and_native_audio_without_buying_tts(): void
    {
        config(['create.native_talking_engine' => 'omni']);
        $planId = (string) \Illuminate\Support\Str::uuid();
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'title' => 'Maya', 'status' => 'active', 'storage_url' => 'minio://p/maya.png', 'mime_type' => 'image/png']);
        DB::table('create_plan_media')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => (string) \Illuminate\Support\Str::uuid(), 'plan_id' => $planId, 'item_index' => 0,
            'kind' => 'character_poses', 'description_hash' => 'h', 'status' => 'succeeded', 'record_json' => json_encode(['file' => ['asset_id' => $asset->id], 'poses' => ['talking']]), 'charged_credits' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('get')->with('minio://p/maya.png')->andReturn('MAYA');
        $storage->shouldReceive('isManagedUrl')->andReturnUsing(fn ($u) => str_starts_with($u, 'minio://'));
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $adapter = \Mockery::mock(\App\Services\Generation\Video\ReplicateVeoAdapter::class);
        $adapter->shouldReceive('start')->once()->withArgs(fn ($prompt, $seconds, $image, $engine, $refs, $seed, $resolution, $videos, $aspect) => str_contains($prompt, 'Hello from WyvStudio.') && $seconds === 15 && $engine === 'omni' && $aspect === '16:9')->andReturn('native-take');
        $adapter->shouldReceive('pollUntilDone')->with('native-take', 840)->andReturn('https://replicate.delivery/x/native.mp4');
        $this->app->instance(\App\Services\Generation\Video\ReplicateVeoAdapter::class, $adapter);
        $dir = sys_get_temp_dir().'/native-talking-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        try {
            \Illuminate\Support\Facades\Process::run(['ffmpeg','-v','error','-y','-f','lavfi','-i','color=size=160x90:rate=24','-f','lavfi','-i','sine=frequency=440','-t','1','-c:v','libx264','-c:a','aac',$dir.'/fixture.mp4']);
            Http::fake(['api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/image']]), 'replicate.delivery/*' => Http::response(file_get_contents($dir.'/fixture.mp4'))]);
            $made = app(\App\Services\Create\PlanMediaExecutor::class)->produce('talking_take', 'Presenter', ['approved_character_media_id' => DB::table('create_plan_media')->where('plan_id', $planId)->where('kind', 'character_poses')->value('id'), 'workspace_id' => $this->workspace->id, 'plan_id' => $planId, 'narration' => ['Hello from WyvStudio.'], 'aspect_ratio' => '16:9'], $dir);
            $this->assertSame(['native', 'omni', 'Hello from WyvStudio.'], [$made['speech_mode'], $made['engine'], $made['line']]);
            $this->assertSame(1, DB::table('create_plan_media')->where('plan_id', $planId)->count(), 'No voiceover dependency');
            Http::assertSentCount(2);
        } finally { foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
    }

    public function test_cloned_talking_keeps_audio_driven_route_and_quote_price(): void
    {
        $route = \App\Services\Create\TalkingPresenter::route('talking_take', 'clone');
        $this->assertSame('cloned_lipsync', $route['speech_mode']);
        $this->assertSame(\App\Services\CreditService::spokespersonCost(15.0), $route['credits']);
        $media = \App\Services\Create\PlanService::selectedMedia(['media' => [['kind' => 'talking_take', 'description' => 'Maya', 'credits' => 330], ['kind' => 'voiceover', 'description' => 'Script', 'credits' => 3]], 'selections' => ['voice' => 'clone']]);
        $this->assertSame(['cloned_voiceover', 'talking_take'], array_column($media, 'kind'));
        $this->assertSame($route['credits'], $media[1]['credits']);
    }

    public function test_the_talking_shot_lip_syncs_the_first_line_from_the_talking_pose_and_the_narration(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'original']];
        $raw = ['summary' => 'x', 'left_out' => '', 'narration' => ['Got an idea?'], 'media' => [['kind' => 'talking_shot', 'description' => 'hook'], ['kind' => 'character_poses', 'description' => 'Mascot: talking, waving'], ['kind' => 'voiceover', 'description' => 'n']]];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $this->assertSame(['character_poses', 'voiceover', 'talking_shot'], array_column($p['media'], 'kind'), 'the talking shot is bought after what it is made from');
        $this->assertSame(\App\Services\Create\TalkingPresenter::route('talking_shot', null)['credits'], collect($p['media'])->firstWhere('kind', 'talking_shot')['credits']);

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
            // The shot first, then the take: the earliest stub for a URL wins in Laravel's fake, so both replies are sequenced here.
            'api.replicate.com/v1/models/veed/fabric-1.0/predictions' => Http::sequence()->push(['id' => 'pred_talk1', 'status' => 'starting'])->push(['id' => 'pred_take1', 'status' => 'starting']),
            'api.replicate.com/v1/predictions/pred_talk1' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/x/talk.mp4']),
            'api.replicate.com/v1/predictions/pred_take1' => Http::response(['status' => 'succeeded', 'output' => 'https://replicate.delivery/x/take.mp4']),
            'replicate.delivery/*' => Http::sequence()->push('MP4BYTES')->push('TAKEBYTES')]);
        $made = app(\App\Services\Create\PlanMediaExecutor::class)->produce('talking_shot', 'hook', ['approved_character_media_id' => DB::table('create_plan_media')->where('plan_id', $planId)->where('kind', 'character_poses')->value('id'), 'workspace_id' => $this->workspace->id, 'narration' => ['Got an idea?', 'Turn any idea into a video.'], 'plan_id' => $planId, 'talking_route' => ['speech_mode' => 'legacy_lipsync', 'seconds' => 15]], $tmp);
        $this->assertSame(['video/mp4', 'MP4BYTES', 'Got an idea?', 1.5], [$made['mime'], file_get_contents($made['path']), $made['line'], $made['seconds']], 'the line ends at 1.1 s plus a breath, held to the 1.5 s a lip-sync clip needs');
        $this->assertStringContainsString('Talking shot', $made['title']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fabric-1.0/predictions') && $r['input']['image'] === 'https://api.replicate.com/v1/files/f1' && $r['input']['audio'] === 'https://api.replicate.com/v1/files/f2');
        $cut = trim(\Illuminate\Support\Facades\Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $tmp.'/line.wav'])->output());
        $this->assertEqualsWithDelta(1.5, (float) $cut, 0.05, 'the uploaded audio is the first line only');
        $this->assertSame(2, $uploads, 'the talking pose was chosen, not the first pose');

        // The take: the whole narration, no transcription, at most 15 s; priced at the 15 s tariff.
        $take = app(\App\Services\Create\PlanMediaExecutor::class)->produce('talking_take', 'a-roll', ['approved_character_media_id' => DB::table('create_plan_media')->where('plan_id', $planId)->where('kind', 'character_poses')->value('id'), 'workspace_id' => $this->workspace->id, 'narration' => ['Got an idea?', 'Turn any idea into a video.'], 'plan_id' => $planId, 'talking_route' => ['speech_mode' => 'legacy_lipsync', 'seconds' => 15]], $tmp);
        $this->assertSame(['TAKEBYTES', 'Got an idea? Turn any idea into a video.', 4.0], [file_get_contents($take['path']), $take['line'], $take['seconds']]);
        $this->assertStringContainsString('Talking take', $take['title']);
        $this->assertEqualsWithDelta(4.0, (float) trim(\Illuminate\Support\Facades\Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $tmp.'/line.wav'])->output()), 0.05, 'the whole narration is uploaded');
        $this->assertSame(330, \App\Services\Create\CapabilityCatalogue::credits('talking_take', $this->workspace->id));
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
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, $version, 'full_video'));
        $candidate = $this->seedCharacter($plan['id'], $json, $c);
        $json['selections']['character_approval'] = $candidate['token'];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $withCharacter = $this->conversations->quote($this->owner, $c->id, $version, 'full_video');
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
        $storage->shouldReceive('get')->with('minio://r/ref.mp4')->andReturn(file_get_contents($tmp.'/ref.mp4'));
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $sheets = app(\App\Services\Create\References\ReferenceSheets::class);
        $path = $sheets->pathFor($asset);
        $this->assertSame('create/references/'.$asset->id.'/'.hash_file('sha256', $tmp.'/ref.mp4').'/sheet.jpg', $path);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($path));
        $this->assertSame($path, $sheets->pathFor($asset), 'unchanged source bytes reuse the cached sheet');
        $asset->metadata_json = ['reference_analysis' => ['duration_seconds' => 3, 'cuts' => [1, 2]]];
        $detail = $sheets->transitionsFor($asset);
        $this->assertSame([0.88, 1.0, 1.12, 1.88, 2.0, 2.12], $detail['times']);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($detail['path']));
        $bytes = file_get_contents($tmp.'/ref.mp4');
        \Illuminate\Support\Facades\Storage::disk('local')->put('frozen-reference.mp4', $bytes);
        $frozen = ['purpose' => 'reference', 'asset_type' => 'video', 'storage_path' => 'frozen-reference.mp4',
            'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $frames = $sheets->characterStyleImages([
            ['purpose' => 'reference', 'asset_type' => 'image', 'reference' => ['from' => 'page']],
            $frozen,
            ['purpose' => 'source', 'asset_type' => 'image'],
        ], $tmp);
        $this->assertCount(2, $frames, 'Only the uploaded style reference supplies character treatment, never the brand page or source image');
        foreach ($frames as $frame) $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($frame));
        \Illuminate\Support\Facades\Storage::disk('local')->put('frozen-reference.mp4', 'changed');
        $this->rejected(409, fn () => $sheets->characterStyleImages([$frozen], $tmp));
        foreach (glob($tmp.'/*') as $f) unlink($f); rmdir($tmp);
    }

    public function test_the_plan_carries_art_direction_per_beat_and_a_signature_move(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'silent']];
        $raw = ['summary' => 'x', 'left_out' => '', 'signature_move' => 'A giant-type wipe of FLOW into the dashboard beat',
            'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 3, 'layout' => 'Two columns: bust left at half height, headline right', 'field' => '#0E0B12',
                'uses' => ['browser-device-stage', 'not-a-real-item', 'cta-lockup', 'browser-device-stage', 'light-leak']]]];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $this->assertSame(['Two columns: bust left at half height, headline right', '#0E0B12'], [$p['scenes'][0]['layout'], $p['scenes'][0]['field']]);
        $this->assertSame('A giant-type wipe of FLOW into the dashboard beat', $p['signature_move']);
        $this->assertSame(['browser-device-stage', 'cta-lockup'], $p['scenes'][0]['uses'], 'only shipped registry items, at most two, no repeats');
        // The planner's shortlist: the core set plus the tags the brief suggests, bounded.
        $list = \App\Services\Create\RegistryCatalogue::shortlist('A 15 s demo of our SaaS dashboard for an ad', ['aspect_ratio' => '9:16']);
        $names = array_column($list, 'name');
        $this->assertLessThanOrEqual(70, count($list));
        $this->assertContains('cta-lockup', $names);
        $this->assertContains('browser-device-stage', $names);
        $this->assertTrue(count(array_filter($list, fn ($i) => in_array('captions', $i['tags'], true) || in_array('mock-ui', $i['tags'], true) || in_array('product-demo', $i['tags'], true))) >= 5, 'the brief\'s kinds are represented');
        $this->assertSame(['name', 'type', 'what', 'tags', 'duration', 'mount', 'variables'], array_keys($list[0]));
    }

    public function test_storyboard_defers_production_media_and_selected_talking_option_is_executed(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'stage-plan');
        $json = $p['plan'];
        $json['narration'] = $json['selections']['narration'] = ['Hello there.'];
        $json['media'] = [
            ['kind' => 'character_poses', 'description' => 'Maya: talking', 'credits' => 210],
            ['kind' => 'voiceover', 'description' => 'Approved narration', 'credits' => 3],
            ['kind' => 'music', 'description' => 'Light score', 'credits' => 34],
            ['kind' => 'ai_image', 'description' => 'Product', 'credits' => 20],
        ];
        $json['decisions'] = [['id' => 'presenter', 'question' => 'Presenter?', 'options' => [
            ['id' => 'talk', 'kind' => 'media', 'tool' => 'talking_take', 'label' => 'Talking presenter', 'detail' => 'Full script', 'credits' => 420],
            ['id' => 'still', 'kind' => 'included', 'tool' => null, 'label' => 'Stills', 'credits' => 0],
        ]]];
        $json['selections']['choices'] = ['presenter' => 'talk'];
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $look = $this->conversations->quote($this->owner, $c->id, $version, 'storyboard')->payload_json;
        $this->assertSame(['character_poses', 'ai_image'], array_column($look['plan_media'], 'kind'));
        $this->assertSame([0, 2], array_column($look['plan_media'], 'plan_item_index'), 'deferral preserves media cache identity');
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, $version, 'full_video'));
        $candidate = $this->seedCharacter($p['id'], $json, $c);
        $this->rejected(409, fn () => $plans->select($this->owner, $c->id, $p['id'], $version, ['character_approval' => str_repeat('0', 64)]));
        $approved = $plans->select($this->owner, $c->id, $p['id'], $version, ['character_approval' => $candidate['token']]);
        $this->assertTrue($approved['character_preview']['approved']);
        $json = $approved['plan']; $version++;
        $full = $this->conversations->quote($this->owner, $c->id, $version, 'full_video')->payload_json;
        $this->assertSame(['character_poses', 'music', 'ai_image', 'talking_take', 'character_variants'], array_column($full['plan_media'], 'kind'));
        $this->assertSame(35, collect($full['plan_media'])->firstWhere('kind', 'character_variants')['credits']);
        $this->assertSame(0, collect($full['plan_media'])->firstWhere('kind', 'character_poses')['credits']);
        $this->assertSame('native', collect($full['plan_media'])->firstWhere('kind', 'talking_take')['speech_mode']);
        $this->assertGreaterThan($look['media_estimate'], $full['media_estimate']);
        $json['selections']['choices']['presenter'] = 'still';
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $without = $this->conversations->quote($this->owner, $c->id, $version, 'full_video')->payload_json;
        $this->assertNotContains('talking_take', array_column($without['plan_media'], 'kind'));
        $storyQuote = $this->conversations->quote($this->owner, $c->id, $version, 'storyboard');
        $run = $this->conversations->approve($this->owner, $c->id, $storyQuote->id, 'story-only', true);
        $claim = $this->runs->claim();
        $this->rejected(422, fn () => app(\App\Services\Create\PlanMediaService::class)->produceAdHoc($run->id, $claim['lease_token'], 'music', 'An unapproved score'));
        $this->assertSame(0, DB::table('composition_attempts')->where('run_id', $run->id)->count(), 'rejection happens before a paid attempt');
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
        $this->assertSame(1, $q->payload_json['execution_policy']['critic']['max_calls'], 'one critic round on the stills');

        // The look version is recorded as such; approving it quotes the motion build from it.
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-look', true);
        $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'The look', 'creative_review' => ['status' => 'needs_attention', 'findings' => ['Maya is still photoreal.']], 'bundle' => ['index.html' => '<html>look</html>']], 'private/look.mp4', 'h1');
        $head = DB::table('composition_revisions')->where('run_id', $run->id)->first();
        $this->assertTrue(json_decode($head->metadata_json, true)['look']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Approve the look and build the motion.', 'expected_version' => (int) $this->conversations->conversation($this->owner, $c->id)->version, 'idempotency_key' => 'm-approve']);
        $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-motion');
        $still = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'storyboard');
        $this->assertSame([true, false], [$still->payload_json['look_first'], $still->payload_json['from_look']], 'approval words cannot override the explicit stage');
        $q2 = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'full_video');
        $this->assertSame([false, true], [$q2->payload_json['look_first'], $q2->payload_json['from_look']]);
        $this->assertContains('Maya is still photoreal.', $q2->payload_json['base_review']['findings']);
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
        // Empty objects (a tool with no fields, a tool_use with no input) are sent as the worker wrote them.
        $messagesJson = '[{"role":"user","content":[{"type":"text","text":"{\\"context\\":{}}"}]},{"role":"assistant","content":[{"type":"tool_use","id":"tu_0","name":"check","input":{}}]},{"role":"user","content":[{"type":"tool_result","tool_use_id":"tu_0","content":"{\\"ok\\":true}"}]}]';
        $toolsJson = '[{"name":"write","description":"Write a file","input_schema":{"type":"object","properties":{"path":{"type":"string"}},"required":["path"]}},{"name":"check","description":"Check","input_schema":{"type":"object","properties":{},"required":[]}}]';
        $messages = json_decode($messagesJson, true); $tools = json_decode($toolsJson, true);
        // The worker hashes {prompt, system, maxTokens, image, messagesJson, toolsJson} as JSON; the gateway must agree.
        $hash = hash('sha256', json_encode(['prompt' => 'tool-mode call 1', 'system' => 'sys', 'maxTokens' => 4096, 'image' => null, 'messagesJson' => $messagesJson, 'toolsJson' => $toolsJson], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
        $a = $attempts->begin($run->id, $claim['lease_token'], 'agent-1', 'agent', $hash);
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['id' => 'msg_tool', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'content' => [['type' => 'text', 'text' => 'Writing.'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'write', 'input' => ['path' => 'index.html']], ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'check', 'input' => []], ['type' => 'server_tool_use', 'id' => 'x']]])
            ->push(['id' => 'msg_critic', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 90, 'output_tokens' => 40], 'content' => [['type' => 'text', 'text' => '{"scores":{}}']]])]);
        $out = app(\App\Services\Create\AnthropicGateway::class)->complete($run->id, $claim['lease_token'], $a['id'], ['prompt' => 'tool-mode call 1', 'system' => 'sys', 'max_tokens' => 4096, 'image' => null, 'messages_json' => $messagesJson, 'tools_json' => $toolsJson]);
        $this->assertSame(['text', 'tool_use', 'tool_use'], array_column($out['content'], 'type'), 'content blocks come back; unknown block types are dropped');
        $this->assertStringContainsString('"input":{}', json_encode($out['content'][2]), 'a tool call with no fields goes back out as an object');
        $this->assertSame('tool_use', $out['stop_reason']);
        $sent = Http::recorded()[0][0]; $sentBody = json_decode($sent->body(), true);
        $this->assertSame($messages, $sentBody['messages'], 'the history goes to the provider as the worker wrote it');
        $this->assertSame($tools, $sentBody['tools']);
        $this->assertStringContainsString('"properties":{}', $sent->body(), 'an empty schema stays an object');
        $this->assertStringContainsString('"input":{}', $sent->body(), 'an empty tool_use input stays an object');
        // A history with an unknown block type is refused before any call.
        $b = $attempts->begin($run->id, $claim['lease_token'], 'agent-2', 'agent', 'h2');
        // The critic is a separate kind with its own, smaller policy; the gateway reads that policy's limits.
        $input['execution_policy']['critic'] = ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'credits' => 25, 'effort' => 'low', 'cost_limit_microusd' => 100000, 'max_calls' => 2, 'max_output_tokens' => 2048];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        $criticHash = hash('sha256', json_encode(['prompt' => 'critic call 1', 'system' => 'critic', 'maxTokens' => 2048, 'image' => null, 'messagesJson' => $messagesJson, 'toolsJson' => '[]'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
        $cr = $attempts->begin($run->id, $claim['lease_token'], 'critic-1', 'critic', $criticHash);
        // The output limit is part of the recorded request: a call with a different one is a different call.
        $this->rejected(409, fn () => app(\App\Services\Create\AnthropicGateway::class)->complete($run->id, $claim['lease_token'], $cr['id'], ['prompt' => 'critic call 1', 'system' => 'critic', 'max_tokens' => 4096, 'image' => null, 'messages_json' => $messagesJson, 'tools_json' => '[]']));
        $verdict = app(\App\Services\Create\AnthropicGateway::class)->complete($run->id, $claim['lease_token'], $cr['id'], ['prompt' => 'critic call 1', 'system' => 'critic', 'max_tokens' => 2048, 'image' => null, 'messages_json' => $messagesJson, 'tools_json' => '[]']);
        $this->assertSame('end_turn', $verdict['stop_reason']);
        $settled = DB::table('composition_attempts')->where('id', $cr['id'])->first();
        $this->assertSame((int) ceil($settled->cost_microusd / 4000), (int) $settled->charged_credits, 'a critic call is charged its real cost, not its reservation');
        $this->assertLessThan(25, (int) $settled->charged_credits);
        Http::assertSent(fn ($r) => $r['max_tokens'] === 2048 && $r['output_config']['effort'] === 'low' && ! isset($r['tools']));
        $this->rejected(409, fn () => app(\App\Services\Create\AnthropicGateway::class)->complete($run->id, $claim['lease_token'], $b['id'], ['prompt' => 'p', 'system' => 'sys', 'max_tokens' => 4096, 'image' => null, 'messages_json' => json_encode([['role' => 'user', 'content' => [['type' => 'document']]]]), 'tools_json' => '[]']));
        $this->rejected(422, fn () => \App\Services\Create\AnthropicGateway::checkToolMessages([['role' => 'user', 'content' => [['type' => 'document']]]], []));
        $this->rejected(422, fn () => \App\Services\Create\AnthropicGateway::checkToolMessages([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]]], [['name' => 'Bad Name', 'input_schema' => []]]));
    }

    public function test_media_is_approved_as_a_ceiling_and_the_agent_may_buy_under_it(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.agent_provider' => 'anthropic', 'create.agent_model' => 'claude-opus-5-5', 'services.anthropic.key' => 'k', 'create.pilot_budget_microusd' => 60_000_000]);
        $c = $this->brief();
        $plan = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-ceiling');
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $json['media'] = [['kind' => 'voiceover', 'description' => 'Narration', 'credits' => 3], ['kind' => 'sfx', 'description' => 'clicks', 'credits' => 50]];
        $requirementId = 'req-'.str_repeat('a', 20);
        $json['requirements_schema'] = 1;
        $json['requirements'] = [['id' => $requirementId, 'text' => 'Whoosh on the reveal', 'category' => 'audio', 'version' => 1]];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $q = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame([53, 80], [$q->payload_json['media_estimate'], $q->payload_json['media_ceiling']], '1.5x the estimate by default');
        $this->assertSame(80, $q->payload_json['execution_policy']['plan_media']['total_credits']);
        $this->assertSame(8, $q->payload_json['execution_policy']['plan_media']['max_calls'], 'room for ad-hoc purchases');
        $this->assertGreaterThanOrEqual(210, $q->payload_json['execution_policy']['plan_media']['credits'], 'any single catalogue item fits');

        // The conversation can set its own ceiling, never under the estimate.
        $cv = $this->conversations->conversation($this->owner, $c->id);
        DB::table('create_conversations')->where('id', $c->id)->update(['settings_json' => json_encode(\App\Services\Create\OutputSettings::normalize([...json_decode($cv->settings_json, true), 'media_ceiling_credits' => 100])), 'version' => $cv->version + 1]);
        $q2 = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $this->assertSame(100, $q2->payload_json['media_ceiling']);

        // A purchase within the ceiling appends to the plan list and is bought; one over it is refused with 402.
        $run = $this->conversations->approve($this->owner, $c->id, $q2->id, 'approve-ceiling', true);
        $claim = $this->runs->claim();
        $service = app(\App\Services\Create\PlanMediaService::class);
        $this->rejected(422, fn () => $service->produceAdHoc($run->id, $claim['lease_token'], 'transcript', 'free thing'));
        $this->rejected(422, fn () => $service->produceAdHoc($run->id, $claim['lease_token'], 'made_up', 'x'));
        $this->rejected(422, fn () => $service->produceAdHoc($run->id, $claim['lease_token'], 'sfx', 'extra whoosh', ['req-'.str_repeat('b', 20)]));
        $executor = \Mockery::mock(\App\Services\Create\PlanMediaExecutor::class);
        $executor->shouldReceive('produce')->once()->with('sfx', 'extra whoosh', \Mockery::any(), \Mockery::any())->andReturnUsing(function ($k, $d, $ctx, $dir) {
            $this->assertSame('Whoosh on the reveal', $ctx['task_requirements'][0]['text']);
            \Illuminate\Support\Facades\Process::run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=16000', '-t', '0.3', $dir.'/s.wav']);
            return ['path' => $dir.'/s.wav', 'mime' => 'audio/wav', 'title' => 'SFX', 'provider_id' => 'sfx-1']; });
        $this->app->instance(\App\Services\Create\PlanMediaExecutor::class, $executor);
        $bought = $service->produceAdHoc($run->id, $claim['lease_token'], 'sfx', 'extra whoosh', [$requirementId]);
        $this->assertSame(['succeeded', 50], [$bought['status'], $bought['charged_credits']]);
        $this->assertSame([$requirementId], $bought['requirement_ids']);
        $items = json_decode(DB::table('composition_runs')->where('id', $run->id)->value('input_json'), true)['plan_media'];
        $this->assertSame(['voiceover', 'sfx', 'sfx'], array_column($items, 'kind'));
        $this->assertTrue($items[2]['ad_hoc']);
        $this->assertSame($items[2]['id'], $bought['task_id']);
        $this->assertSame([$requirementId], $items[2]['requirement_ids']);
        // Character replacement is never an ad-hoc purchase, even when budget remains.
        $this->rejected(422, fn () => $service->produceAdHoc($run->id, $claim['lease_token'], 'character_poses', 'x'));
    }

    public function test_replicate_gateway_binds_calls_to_the_app_attempt_and_replays_receipts(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim()['lease_token'];
        $input = json_decode($run->input_json, true); $input['mode'] = 'agent';
        $input['execution_policy']['agent'] = ['provider' => 'replicate', 'model' => 'anthropic/claude-4.5-sonnet', 'credits' => 75, 'cost_limit_microusd' => 300000, 'max_calls' => 3];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['authorized_credits' => 225, 'reserved_credits' => 225]);
        config(['create.paid_execution_enabled' => true, 'create.pilot_budget_id' => 'test', 'create.pilot_budget_microusd' => 5000000]);
        $body = ['prompt' => 'Create a scene', 'system' => 'Use the app tools', 'maxTokens' => 1024, 'image' => null];
        $attempt = app(\App\Services\Create\AttemptService::class)->begin($run->id, $lease, 'agent-1', 'agent', hash('sha256', json_encode($body)));
        $gateway = app(\App\Services\Create\ReplicateGateway::class);
        $this->rejected(409, fn () => $gateway->prediction($run->id, $lease, $attempt['id'], [...$body, 'prompt' => 'Changed']));
        $this->rejected(422, fn () => $gateway->prediction($run->id, $lease, $attempt['id'], [...$body, 'image' => 'https://example.test/private']));
        Http::assertNothingSent();
        Http::fake(['api.replicate.com/v1/models/anthropic/claude-4.5-sonnet/predictions' => Http::response([
            'id' => 'gateway-1', 'model' => 'anthropic/claude-4.5-sonnet', 'version' => '459655107e29a683cb6deb73a9640cf9aeae39ea7c87803a2ae81c311f6ef44f', 'status' => 'succeeded', 'output' => ['Scene'], 'metrics' => ['token_input_count' => 10, 'token_output_count' => 5]])]);
        $first = $gateway->prediction($run->id, $lease, $attempt['id'], $body);
        $this->assertSame($first, $gateway->prediction($run->id, $lease, $attempt['id'], $body));
        Http::assertSentCount(1);
        $this->assertSame('gateway-1', DB::table('composition_attempts')->where('id', $attempt['id'])->value('prediction_id'));
    }

    public function test_character_reference_preparation_failure_never_buys_media_or_leaves_an_unknown_attempt(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief();
        $p = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, 1, 'character-preflight');
        $json = $p['plan'];
        $json['media'] = [['kind' => 'character_poses', 'description' => 'Maya: talking', 'credits' => 35]];
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $q = $this->conversations->quote($this->owner, $c->id, $version, 'storyboard');
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'character-preflight', true);
        $claim = $this->runs->claim();
        $sheets = \Mockery::mock(\App\Services\Create\References\ReferenceSheets::class);
        $sheets->shouldReceive('characterStyleImages')->once()->andThrow(new \RuntimeException('Cannot decode the local reference'));
        $this->app->instance(\App\Services\Create\References\ReferenceSheets::class, $sheets);
        $executor = \Mockery::mock(\App\Services\Create\PlanMediaExecutor::class);
        $executor->shouldNotReceive('produce');
        $this->app->instance(\App\Services\Create\PlanMediaExecutor::class, $executor);
        $result = app(\App\Services\Create\PlanMediaService::class)->produce($run->id, $claim['lease_token'], 0);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, $result['charged_credits']);
        $this->assertSame('failed', DB::table('composition_attempts')->where('run_id', $run->id)->value('status'));
        $this->assertSame('failed', DB::table('create_plan_media')->where('plan_id', $p['id'])->value('status'));
        Http::assertNothingSent();
    }

    public function test_character_approval_expires_when_the_plan_or_generated_bytes_change(): void
    {
        $c = $this->brief(); $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, 1, 'character'); $json = $p['plan'];
        $json['media'] = [['kind' => 'character_poses', 'description' => 'Maya in halftone', 'credits' => 210]];
        $json['character_style'] = 'halftone illustration';
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $candidate = $this->seedCharacter($p['id'], $json, $c);
        $plan = \App\Services\Create\PlanService::quotePlan($json, $p['id']);
        $settings = json_decode($c->settings_json, true);
        $this->rejected(422, fn () => \App\Services\Create\CharacterApproval::requireApproved($plan, $settings, $this->workspace->id));
        $plan['character_approval'] = $candidate['token'];
        $this->assertSame($candidate['media_id'], \App\Services\Create\CharacterApproval::requireApproved($plan, $settings, $this->workspace->id)['media_id']);
        $this->rejected(422, fn () => \App\Services\Create\CharacterApproval::requireApproved([...$plan, 'character_style' => 'photograph'], $settings, $this->workspace->id));
        \Illuminate\Support\Facades\Storage::disk('local')->put($candidate['files'][0]['storage_path'], 'replaced');
        $this->rejected(409, fn () => \App\Services\Create\CharacterApproval::requireApproved($plan, $settings, $this->workspace->id));
        Http::assertNothingSent();
    }

    public function test_timing_only_educational_edit_keeps_approved_voice_script_and_closing_cta(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $intent = ['format' => 'educational', 'motion' => 'kinetic', 'timing_driver' => 'narration', 'reason' => 'Text and screenshots explain the steps.', 'source_quote' => 'Teach people to make UGC videos'];
        $script = ['If I had to make UGC content fast', 'I would start with WyvStudio', 'Pick your idea', 'Generate your script', 'Choose your voice and visuals', 'Render the video', 'Publish it', 'Create. Render. Publish. Make your next UGC video with WyvStudio.'];
        $ctx = ['files' => [], 'voices' => [['key' => 'Puck'], ['key' => 'Achird']], 'settings' => ['duration_seconds' => 30],
            'messages' => [['role' => 'user', 'content' => 'Teach people to make UGC videos'], ['role' => 'user', 'content' => 'The visuals are faster than the voice']],
            'previous_plan' => ['creative_intent' => $intent, 'approved_narration' => $script, 'approved_voice' => 'Puck', 'approved_copy' => ['Create. Render. Publish.']]];
        $p = $plans->normalize(['summary' => 'Fix the visual timings.', 'creative_intent' => ['edit_scope' => 'timing_only', 'edit_source_quote' => 'The visuals are faster than the voice'],
            'narration' => ['Different words'], 'voice' => 'Achird', 'callouts' => ['Different copy']], $ctx, $this->workspace->id);
        $q = \App\Services\Create\PlanService::quotePlan($p, 'timing-plan');
        $this->assertSame('educational', $q['creative_intent']['format']);
        $this->assertSame($script, $q['narration']);
        $this->assertSame('Puck', $q['voice']);
        $this->assertSame(['Create. Render. Publish.'], $q['on_screen_copy']);
        $this->assertSame([], $q['character_performance']);
        $this->assertSame(['voiceover'], array_column($q['media'], 'kind'), 'no avatar or video-generation task is added for the UGC topic');
        $changed = $plans->normalize(['summary' => 'A requested rewrite.', 'creative_intent' => [...$intent, 'edit_scope' => 'content'], 'narration' => ['New script'], 'voice' => 'Achird'], $ctx, $this->workspace->id);
        $this->assertSame(['New script'], $changed['narration']);
        $this->assertSame('Achird', $changed['voice']);
        Http::assertNothingSent();
    }

    public function test_character_performance_survives_normalization_choices_replanning_and_quote_handoff(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Have Maya blink then smile']], 'files' => [], 'settings' => ['duration_seconds' => 15]];
        $raw = ['summary' => 'Maya blinks then smiles.',
            'character_performance' => [['action' => 'Blink then smile', 'source_quote' => 'Have Maya blink then smile', 'kind' => 'facial', 'start' => 0, 'end' => 5, 'route' => 'generated_video', 'tool' => 'animate_image']],
            'media' => [['kind' => 'character_poses', 'description' => 'Maya master'], ['kind' => 'animate_image', 'description' => 'Unrelated product motion', 'subject' => 'source']],
            'decisions' => [['id' => 'motion', 'question' => 'Animate Maya?', 'options' => [
                ['id' => 'yes', 'label' => 'Blink and smile', 'detail' => 'Blink then smile', 'kind' => 'media', 'tool' => 'animate_image', 'subject' => 'approved_character'],
                ['id' => 'no', 'label' => 'Still', 'kind' => 'included'],
            ]]]];
        $p = $plans->normalize($raw, $ctx, $this->workspace->id);
        $q = \App\Services\Create\PlanService::quotePlan($p, 'test-plan');
        $this->assertCount(1, $q['character_performance']);
        $this->assertSame(['source', 'approved_character'], array_column(array_values(array_filter($q['media'], fn ($m) => $m['kind'] === 'animate_image')), 'subject'));
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues($q, $ctx['settings'], $q['media']));
        $next = $plans->normalize(['summary' => 'Change the headline.'], [...$ctx, 'previous_plan' => ['character_performance' => $p['character_performance']]], $this->workspace->id);
        $this->assertSame($p['character_performance'], $next['character_performance']);
        $this->assertNotEmpty(\App\Services\Create\CharacterPerformance::issues(\App\Services\Create\PlanService::quotePlan($next, 'next'), $ctx['settings'], []));
        Http::assertNothingSent();
    }

    public function test_character_performance_blocks_full_purchase_but_allows_storyboard_and_explicit_omission(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief(); $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, 1, 'motion-plan'); $json = $p['plan'];
        $json['character_performance'] = [['id' => 'perf-blink', 'action' => 'Blink and smile', 'kind' => 'facial', 'source_quote' => 'Blink and smile', 'start' => 0, 'end' => 5, 'route' => 'generated_video', 'tool' => 'animate_image']];
        $json['media'] = [];
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, $version, 'full_video'));
        $this->assertSame(0, DB::table('api_operations')->count(), 'missing motion cannot reserve credits');
        $story = $this->conversations->quote($this->owner, $c->id, $version, 'storyboard');
        $this->assertSame('perf-blink', $story->payload_json['plan']['character_performance'][0]['id']);
        $this->assertSame([], $story->payload_json['plan_media']);
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], $version, ['omitted_performance' => ['not-an-action']]));
        $edited = $plans->select($this->owner, $c->id, $p['id'], $version, ['omitted_performance' => ['perf-blink']]);
        $this->assertSame([], $edited['performance_issues']);
        $this->assertCount(1, $edited['plan']['character_performance'], 'keep the original promise visible when omitted');
        $full = $this->conversations->quote($this->owner, $c->id, $version + 1, 'full_video');
        $this->assertSame([], $full->payload_json['plan']['character_performance']);
        $this->assertSame('perf-blink', $full->payload_json['plan']['omitted_character_performance'][0]['id'], 'the builder and critic receive the explicit exception to the earlier brief');
        Http::assertNothingSent();
    }

    public function test_character_motion_quote_and_executor_use_the_approved_master_not_an_unrelated_photo(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief(); $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, 1, 'approved-motion'); $json = $p['plan'];
        $json['media'] = [
            ['kind' => 'character_poses', 'description' => 'Maya in halftone', 'credits' => 210],
            ['kind' => 'animate_image', 'description' => 'Blink and smile', 'subject' => 'approved_character', 'credits' => 90],
        ];
        $json['character_performance'] = [['id' => 'perf-blink', 'action' => 'Blink and smile', 'kind' => 'facial', 'start' => 0, 'end' => 5, 'route' => 'generated_video', 'tool' => 'animate_image']];
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $candidate = $this->seedCharacter($p['id'], $json, $c);
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $plans->select($this->owner, $c->id, $p['id'], $version, ['character_approval' => $candidate['token']]);
        $quote = $this->conversations->quote($this->owner, $c->id, $version + 1, 'full_video');
        $motion = collect($quote->payload_json['plan_media'])->firstWhere('kind', 'animate_image');
        $this->assertSame($candidate['files'][0]['sha256'], $motion['master_sha256']);
        $ctx = ['animation_subject' => 'approved_character', 'approved_character_files' => $candidate['files'], 'source_images' => ['/unrelated-product.png']];
        $this->assertSame(\Illuminate\Support\Facades\Storage::disk('local')->path($candidate['files'][0]['storage_path']), \App\Services\Create\PlanMediaExecutor::animationSource($ctx));
        $this->assertSame('/unrelated-product.png', \App\Services\Create\PlanMediaExecutor::animationSource([...$ctx, 'animation_subject' => 'source']));
        \Illuminate\Support\Facades\Storage::disk('local')->put($candidate['files'][0]['storage_path'], 'changed');
        $this->rejected(409, fn () => \App\Services\Create\PlanMediaExecutor::animationSource($ctx));
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $quote->id, 'changed-master', true));
        $this->assertSame(0, DB::table('api_operations')->count());
        Http::assertNothingSent();
    }

    public function test_character_animation_cannot_skip_approval_when_planner_omits_the_master_task(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief(); $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, 1, 'missing-master'); $json = $p['plan'];
        $json['media'] = [['kind' => 'animate_image', 'description' => 'Blink', 'subject' => 'approved_character', 'credits' => 90]];
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 2, 'full_video'));
        $this->assertSame(0, DB::table('api_operations')->count());
        Http::assertNothingSent();
    }

    private function seedCharacter(string $planId, array $p, object $c): array
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=');
        $path = 'create/uploads/character-proof-'.\Illuminate\Support\Str::uuid().'.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);
        $a = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'title' => 'Approved halftone pose', 'status' => 'active', 'storage_url' => 'create-upload://'.substr($path, strlen('create/uploads/')), 'mime_type' => 'image/png']);
        $plan = \App\Services\Create\PlanService::quotePlan($p, $planId);
        $settings = json_decode($c->settings_json, true);
        $file = ['asset_id' => (int) $a->id, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'storage_path' => $path, 'mime_type' => 'image/png'];
        $hash = \App\Services\Create\CharacterApproval::mediaHash(collect($plan['media'])->firstWhere('kind', 'character_poses'), ['narration' => $plan['narration'], 'voice' => $plan['voice'], 'aspect_ratio' => $settings['aspect_ratio'], 'character_style' => $plan['character_style']]);
        DB::table('create_plan_media')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => $c->id, 'plan_id' => $planId, 'item_index' => 0, 'kind' => 'character_poses', 'description_hash' => $hash, 'status' => 'succeeded', 'record_json' => json_encode(['file' => $file, 'poses' => ['talking'], 'character_contract' => \App\Services\Create\CharacterApproval::CONTRACT]), 'charged_credits' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return \App\Services\Create\CharacterApproval::candidate($plan, $settings, (int) $this->workspace->id);
    }

    public function test_stopped_uncertain_work_releases_the_host_but_not_its_billing_hold(): void
    {
        config(['developer.limits.max_active_videos' => 1]);
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $claim['lease_token'], 'agent-1', 'agent', str_repeat('a', 64));
        $attempts->settle($run->id, $claim['lease_token'], $a['id'], ['status' => 'unknown']);
        $held = DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits');
        $this->assertNull($this->runs->claim());
        $this->rejected(403, fn () => $this->runs->workerStopped($run->id, str_repeat('x', 64)));
        $this->assertTrue($this->runs->workerStopped($run->id, $claim['lease_token'])['hold_retained']);
        $this->assertSame('unknown', DB::table('composition_attempts')->where('id', $a['id'])->value('status'));
        $this->assertSame('needs_attention', DB::table('api_operations')->where('id', $run->operation_id)->value('status'));
        $this->assertSame(0, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('capacity_slots'));
        $this->assertEquals($held, DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, $claim['lease_token'], 2, 'Late worker'));
        [, , $next] = $this->admitted();
        $this->assertSame($next->id, $this->runs->claim()['id'], 'Other conversations can run while the original hold remains');
    }

    public function test_local_quarantine_releases_capacity_idempotently_without_releasing_credits(): void
    {
        config(['developer.limits.max_active_videos' => 1]);
        [, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $claim['lease_token'], 'agent-1', 'agent', str_repeat('a', 64));
        $attempts->settle($run->id, $claim['lease_token'], $a['id'], ['status' => 'unknown']);
        $before = DB::table('api_operations')->where('id', $run->operation_id)->first();
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('create:quarantine-run', ['run' => $run->id, '--worker-stopped' => true])->assertSuccessful();
        }
        $after = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $this->assertSame('needs_attention', $after->status);
        $this->assertSame(0, (int) $after->capacity_slots);
        $this->assertEquals($before->reserved_credits, $after->reserved_credits);
        $this->assertEquals($before->spent_credits, $after->spent_credits);
        $this->assertSame('unknown', DB::table('composition_attempts')->where('id', $a['id'])->value('status'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, $claim['lease_token'], 2, 'Late worker'));
        [, , $next] = $this->admitted();
        $this->assertSame($next->id, $this->runs->claim()['id']);
    }

    public function test_shared_capacity_rejection_is_readable_and_does_not_consume_quote(): void
    {
        config(['developer.limits.max_active_videos' => 1]);
        [, , $prior] = $this->admitted();
        // Simulate another operation family holding capacity, with no active Create run.
        DB::table('composition_runs')->where('id', $prior->id)->update(['status' => 'failed']);
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        try {
            $this->conversations->approve($this->owner, $c->id, $q->id, 'capacity-blocked');
            $this->fail('Expected a concurrency rejection');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertStringContainsString('simultaneous video limit', $e->getMessage());
        }
        $this->assertNull($q->fresh()->consumed_at);
        $this->assertSame(1, DB::table('api_operations')->count());
        $this->assertSame(1, DB::table('composition_runs')->count());
    }

    public function test_saved_anthropic_receipt_recovers_without_another_model_call(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim()['lease_token'];
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $lease, 'agent-1', 'agent', str_repeat('a', 64));
        DB::table('composition_attempts')->where('id', $a['id'])->update(['provider' => 'anthropic', 'credit_limit' => 75, 'cost_limit_microusd' => 300000]);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['authorized_credits' => 75, 'reserved_credits' => 75]);
        app(\App\Services\Create\DispatchJournal::class)->save($a['id'], ['status' => 200, 'headers' => [], 'rates' => ['input' => 4, 'output' => 20, 'cache_write' => 5, 'cache_read' => .4], 'body' => json_encode(['id' => 'msg_saved', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10], 'content' => [['type' => 'text', 'text' => 'Saved answer']]])]);
        $attempts->settle($run->id, $lease, $a['id'], ['status' => 'unknown']);
        $this->artisan('create:recover-provider-receipt', ['attempt' => $a['id'], '--worker-stopped' => true])->assertSuccessful();
        $settled = DB::table('composition_attempts')->where('id', $a['id'])->first();
        $this->assertSame(['succeeded', 600, 1], [$settled->status, (int) $settled->cost_microusd, (int) $settled->charged_credits]);
        $this->assertSame('failed', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->artisan('create:recover-provider-receipt', ['attempt' => $a['id'], '--worker-stopped' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('credit_ledger')->count());
        Http::assertNothingSent();
    }

    public function test_dispatch_claim_never_grants_a_second_external_call(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim()['lease_token'];
        $a = app(\App\Services\Create\AttemptService::class)->begin($run->id, $lease, 'agent-1', 'agent', str_repeat('a', 64));
        $journal = app(\App\Services\Create\DispatchJournal::class);
        $this->assertNull($journal->claim($a['id']));
        $this->rejected(409, fn () => $journal->claim($a['id']));
        $journal->save($a['id'], ['id' => 'saved-receipt']);
        $journal->save($a['id'], ['id' => 'must-not-replace']);
        $this->assertSame(['id' => 'saved-receipt'], $journal->claim($a['id']));
        Http::assertNothingSent();
    }

    private function brief(): object
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Keep my source audio.', 'expected_version' => 0, 'idempotency_key' => 'message-1']);
        return $this->conversations->conversation($this->owner, $c->id);
    }

    public function test_trajectory_is_lease_bound_idempotent_bounded_and_redacted(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        config(['create.worker_token' => str_repeat('a', 64)]);
        $url = '/api/internal/create/runs/'.$run->id.'/trajectory';
        $event = ['sequence' => 1, 'at' => now()->toISOString(), 'phase' => 'tool', 'status' => 'failed', 'tool' => 'inspect_reference',
            'summary' => 'Reference failed', 'detail' => 'Bearer private-secret https://private.test/token /Users/me/key', 'call' => 1, 'revision' => 0];
        $payload = ['lease_token' => $claim['lease_token'], 'events' => [$event]];
        $this->withToken(str_repeat('b', 64))->postJson($url, $payload)->assertForbidden();
        $this->withToken(str_repeat('a', 64))->postJson($url, [...$payload, 'lease_token' => str_repeat('0', 64)])->assertForbidden();
        $this->withToken(str_repeat('a', 64))->postJson($url, $payload)->assertOk();
        $this->withToken(str_repeat('a', 64))->postJson($url, $payload)->assertOk();
        $this->assertSame(1, DB::table('composition_trace_events')->count());
        $this->withToken(str_repeat('a', 64))->postJson($url, [...$payload, 'events' => [[...$event, 'summary' => 'Changed']]])->assertStatus(409);
        $this->withToken(str_repeat('a', 64))->postJson($url, [...$payload, 'events' => [[...$event, 'sequence' => 2001]]])->assertStatus(422);
        $report = app(\App\Services\Create\TrajectoryService::class)->show($c->id);
        $encoded = json_encode($report);
        foreach (['private-secret', 'private.test', '/Users/me/key', $claim['lease_token']] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame($run->id, $report['runs'][0]['id']);
        $this->assertSame(1, $report['runs'][0]['trace_events']);
        $this->assertNotNull($report['runs'][0]['message_id']);
        DB::table('composition_runs')->where('id', $run->id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->withToken(str_repeat('a', 64))->postJson($url, $payload)->assertStatus(409);
    }

    public function test_trajectory_view_is_admin_only_and_legacy_runs_report_missing_tool_history(): void
    {
        [$c, , $run] = $this->admitted();
        $url = '/api/v1/admin/create-trajectories/'.$c->id;
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner)->getJson($url)->assertForbidden();
        $this->owner->forceFill(['role' => 'super_admin'])->save();
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('data.runs.0.trace_events', 0)
            ->assertJsonPath('data.runs.0.trace_coverage', 'No detailed trace recorded; database evidence only');
        $this->getJson('/api/v1/admin/create-trajectories?q='.$run->id)->assertOk()->assertJsonPath('data.0.id', $c->id);
        $this->getJson('/api/v1/admin/create-trajectories?q=nonexistent-title')->assertOk()->assertJsonCount(0, 'data');
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
        // Links last 5 to 10 minutes (one shared link per five-minute window).
        $this->travel(11)->minutes();
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

    public function test_running_cancellation_waits_for_worker_and_may_end_cancelled_or_with_the_last_checked_version(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertSame('running', DB::table('api_operations')->value('status'));
        $this->assertTrue($this->runs->heartbeat($run->id, $lease['lease_token'], 1, 'Rendering')['cancel_requested']);
        $this->rejected(422, fn () => $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Ready', 'bundle' => []], 'fake', 'hash'));
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'failed', 'summary' => 'x'], null, null));
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'cancelled', 'summary' => 'Stopped'], null, null);
        $this->assertSame('cancelled', DB::table('composition_runs')->value('status'));
    }

    public function test_stop_keeps_the_last_checked_version_when_the_worker_delivers_it(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Stopped at your request. This is the last version that passed every check.', 'bundle' => ['index.html' => '<html></html>']], 'private/v1.mp4', 'h');
        $this->assertSame('preview_ready', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->assertSame(1, DB::table('composition_revisions')->where('run_id', $run->id)->count());
        $this->assertSame(0, (int) DB::table('api_operations')->value('reserved_credits'), 'the hold is released');
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

    public function test_a_call_under_way_when_stop_is_pressed_keeps_its_receipt(): void
    {
        [, , $run]=$this->admitted(); $claim=$this->runs->claim(); $attempts=app(\App\Services\Create\AttemptService::class);
        $a=$attempts->begin($run->id,$claim['lease_token'],'agent-1','agent',str_repeat('a',64));
        DB::table('composition_runs')->where('id',$run->id)->update(['status'=>'cancel_requested']);
        $attempts->bindPrediction($run->id,$claim['lease_token'],$a['id'],'msg_under_way');
        $this->assertSame('msg_under_way',DB::table('composition_attempts')->where('id',$a['id'])->value('prediction_id'));
        // No new call starts while stopping.
        $this->rejected(409,fn()=>$attempts->begin($run->id,$claim['lease_token'],'agent-2','agent',str_repeat('b',64)));
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
        $this->assertSame($out, $gateway->complete($run->id,$claim['lease_token'],$a['id'],$call), 'Lost delivery replays the saved response without another provider call');
        Http::assertSentCount(1);
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
    public function test_the_planner_accounts_for_every_reference_moment_and_states_a_length_choice(): void
    {
        $study = ['summary' => 's', 'duration_seconds' => 26, 'moments' => [['id' => 'm1', 'start' => 0, 'end' => 2, 'kind' => 'hook'], ['id' => 'm2', 'start' => 2.8, 'end' => 3.5, 'kind' => 'sticker'], ['id' => 'm3', 'start' => 4, 'end' => 6, 'kind' => 'text']],
            'speech' => ['text' => 'x', 'words' => [['x', 0.1, 0.4]], 'pauses' => []], 'pacing' => ['shots' => 9]];
        $brief = \App\Services\Create\PlanService::studyBrief(1523, $study);
        $this->assertSame(['1523:m1', '1523:m2', '1523:m3'], array_column($brief['moments'], 'id'), 'moment ids carry the asset id');
        $ctx = ['files' => [['asset_id' => 1523, 'purpose' => 'reference', 'asset_type' => 'video', 'reference' => ['study' => $brief]]], 'voices' => [], 'settings' => ['duration_seconds' => 30, 'audio' => 'original']];
        $raw = ['summary' => 'x', 'left_out' => '', 'narration' => ['Want to create your first UGC ad? Start here.', 'First, give WyvStudio your product and a clear idea.'],
            'reference_decisions' => [['moment' => '1523:m1', 'decision' => 'keep', 'beat' => 'Hook', 'how' => 'Same bold two-line hook'],
                ['moment' => '1523:m2', 'decision' => 'drop', 'beat' => '', 'how' => 'No mascot or sticker in the brief'], ['moment' => '1523:m9', 'decision' => 'keep', 'beat' => 'x', 'how' => 'invented'],
                ['moment' => '1523:m1', 'decision' => 'replace', 'beat' => 'x', 'how' => 'duplicate']]];
        $p = app(\App\Services\Create\PlanService::class)->normalize($raw, $ctx, (int) $this->workspace->id);
        $this->assertSame(['1523:m1', '1523:m2'], array_column($p['reference_decisions'], 'moment'), 'unknown and duplicate moments are dropped');
        $this->assertSame(['1523:m3'], $p['reference_unaccounted'], 'a moment the plan did not decide on is listed, not lost');
        $this->assertMatchesRegularExpression('/narration runs about \d+ s of this 30 s video/', (string) $p['length_note']);
        $p2 = app(\App\Services\Create\PlanService::class)->normalize([...$raw, 'length_choice' => 'A shorter 20 s video'], $ctx, (int) $this->workspace->id);
        $this->assertStringContainsString('A shorter 20 s video', $p2['length_note']);
    }

    public function test_recurring_systems_get_one_spec_and_a_dropped_moment_says_what_carries_its_job(): void
    {
        $study = ['summary' => 's', 'duration_seconds' => 26, 'pacing' => ['shots' => 9, 'average_shot_seconds' => 2.92, 'cuts_per_10_seconds' => 3.0, 'words_per_second' => 3.63, 'text_to_speech_delay_seconds' => -0.05],
            'music' => ['present' => true, 'tempo_bpm' => 133.3, 'beat_seconds' => 0.45, 'cuts_on_beat' => 0.5, 'confidence' => 0.108, 'beats' => [0.1, 0.55]],
            'systems' => [['id' => 's1', 'name' => 'Step card', 'look' => 'Black frame, white bold Step N, boxed serif label', 'entry' => 'bounces in', 'active' => 'holds', 'hold' => '1.2 s', 'exit' => 'hard cut']],
            'moments' => [['id' => 'm1', 'start' => 4.6, 'end' => 5.6, 'kind' => 'text', 'purpose' => 'names the first step', 'system' => 's1'],
                ['id' => 'm2', 'start' => 2.8, 'end' => 3.5, 'kind' => 'sticker', 'purpose' => 'adds a beat of fun', 'system' => '']]];
        $brief = \App\Services\Create\PlanService::studyBrief(1523, $study);
        $this->assertSame('1523:s1', $brief['moments'][0]['system'], 'a moment points at its system by full id');
        $this->assertSame('1523:s1', $brief['systems'][0]['id']);
        $this->assertSame('adds a beat of fun', $brief['moments'][1]['purpose']);
        $ctx = ['files' => [['asset_id' => 1523, 'purpose' => 'reference', 'asset_type' => 'video', 'reference' => ['study' => $brief]]], 'voices' => [], 'settings' => ['duration_seconds' => 30, 'audio' => 'original']];
        $raw = ['summary' => 'x', 'left_out' => '',
            'reference_decisions' => [['moment' => '1523:m1', 'decision' => 'keep', 'beat' => 'Step 1', 'how' => 'Same card'],
                ['moment' => '1523:m2', 'decision' => 'drop', 'beat' => '', 'how' => 'No mascot', 'carried_by' => 'Hook: "first" pops in orange with a wink']],
            'reference_systems' => [['system' => '1523:s1', 'decision' => 'keep', 'spec' => 'Black card, white Step N, serif label box, bounce in', 'beats' => ['Step 1', 'Step 2']], ['system' => '1523:s9', 'decision' => 'keep', 'spec' => 'invented']]];
        $p = app(\App\Services\Create\PlanService::class)->normalize($raw, $ctx, (int) $this->workspace->id);
        $this->assertSame('Hook: "first" pops in orange with a wink', $p['reference_decisions'][1]['carried_by']);
        $this->assertSame(['average_shot_seconds' => 2.92, 'cuts_per_10_seconds' => 3.0, 'words_per_second' => 3.63, 'text_to_speech_delay_seconds' => -0.05, 'tempo_bpm' => 133.3, 'cuts_on_beat' => 0.5], $p['reference_pacing'], 'the reference rhythm travels to the build');
        $this->assertSame(['tempo_bpm' => 133.3, 'beat_seconds' => 0.45, 'cuts_on_beat' => 0.5, 'confidence' => 0.108], $brief['music'], 'the planner reads the pulse, not every beat');
        $this->assertArrayNotHasKey('carried_by', $p['reference_decisions'][0], 'only a dropped moment carries its job elsewhere');
        $this->assertCount(1, $p['reference_systems'], 'unknown systems are dropped');
        $this->assertSame(['system' => '1523:s1', 'name' => 'Step card', 'decision' => 'keep', 'spec' => 'Black card, white Step N, serif label box, bounce in', 'beats' => ['Step 1', 'Step 2'],
            'reference' => ['look' => 'Black frame, white bold Step N, boxed serif label', 'entry' => 'bounces in', 'active' => 'holds', 'hold' => '1.2 s', 'exit' => 'hard cut']], $p['reference_systems'][0]);
        $uncarried = [...$raw, 'reference_decisions' => [$raw['reference_decisions'][0], array_diff_key($raw['reference_decisions'][1], ['carried_by' => 1])], 'scenes' => [['label' => 'Step 1', 'start' => 0, 'end' => 30]]];
        $this->assertStringContainsString('do not say what now does their job (carried_by): 1523:m2', implode(' ', \App\Services\Create\Planning\PlanPrompt::problems($uncarried, $ctx)));
    }

    public function test_an_attached_talking_face_performs_speech_and_expressions_without_new_media(): void
    {
        $perf = fn ($kind, $route) => ['id' => 'perf-'.$kind.$route, 'kind' => $kind, 'action' => $kind, 'start' => 0, 'end' => 3, 'route' => $route, 'tool' => null];
        $plan = ['narration' => ['Want a video ad that sells?'], 'character_performance' => [$perf('speech', 'face_kit'), $perf('facial', 'face_kit')]];
        $settings = ['duration_seconds' => 15, 'audio' => 'original'];
        $kit = [['face_kit' => ['width' => 10, 'height' => 10, 'patches' => [['name' => 'mouth-open', 'asset_id' => 1]]], 'rig' => null]];
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues($plan, $settings, [], $kit), 'no talking take or character master is needed');
        $this->assertStringContainsString('talking face', \App\Services\Create\CharacterPerformance::issues($plan, $settings, [], [])[0]['message'], 'without the kit the route is not available');
        $body = ['narration' => ['x'], 'character_performance' => [$perf('body', 'face_kit')]];
        $this->assertStringContainsString('not body actions', \App\Services\Create\CharacterPerformance::issues($body, $settings, [], $kit)[0]['message']);
        $silent = \App\Services\Create\CharacterPerformance::issues($plan, ['audio' => 'silent'] + $settings, [], $kit);
        $this->assertStringContainsString('script and audio', $silent[0]['message']);
        // Re-planning the same words of the brief through the face replaces the earlier route; untouched promises carry on.
        $ctx = ['messages' => [['role' => 'user', 'content' => 'she talks with the narration, blinks, winks on the punchline']], 'settings' => $settings,
            'previous_plan' => ['character_performance' => [['kind' => 'speech', 'action' => 'speaks', 'source_quote' => 'she talks with the narration, blinks', 'start' => 0, 'end' => 15, 'route' => 'generated_video', 'tool' => 'talking_take'],
                ['kind' => 'facial', 'action' => 'winks', 'source_quote' => 'winks on the punchline', 'start' => 1, 'end' => 2, 'route' => 'generated_video', 'tool' => 'talking_take']]]];
        $now = \App\Services\Create\CharacterPerformance::normalize([['kind' => 'speech', 'action' => 'speaks via her face kit', 'source_quote' => 'she talks with the narration, blinks', 'start' => 0, 'end' => 12, 'route' => 'face_kit', 'tool' => null]], $ctx);
        $this->assertSame([['facial', 'generated_video'], ['speech', 'face_kit']], array_map(fn ($r) => [$r['kind'], $r['route']], $now));
        // Gestures by cutting between attached poses: body actions only, and only with a pose image that is not one of the kit's own patches.
        $gesture = ['narration' => ['x'], 'character_performance' => [$perf('body', 'poses')]];
        $kitOnly = [['asset_id' => 7, 'asset_type' => 'image', 'face_kit' => ['patches' => [['name' => 'mouth-open', 'asset_id' => 8]]]], ['asset_id' => 8, 'asset_type' => 'image']];
        $this->assertStringContainsString('body poses', \App\Services\Create\CharacterPerformance::issues($gesture, $settings, [], $kitOnly)[0]['message'], 'a face patch is not a pose');
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues($gesture, $settings, [], [...$kitOnly, ['asset_id' => 9, 'asset_type' => 'image']]));
        $this->assertStringContainsString('Cutting between poses', \App\Services\Create\CharacterPerformance::issues(['narration' => ['x'], 'character_performance' => [$perf('speech', 'poses')]], $settings, [], [['asset_id' => 9, 'asset_type' => 'image']])[0]['message']);
        // A ready layered rig performs facial actions too; a flat image still cannot.
        $rig = ['narration' => ['x'], 'character_performance' => [$perf('facial', 'prepared_rig')]];
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues($rig, $settings, [], [['rig' => ['ready' => true]]]));
        $this->assertNotSame([], \App\Services\Create\CharacterPerformance::issues($rig, $settings, [], [['rig' => ['ready' => false]]]));
    }

    public function test_the_reference_moves_travel_from_study_to_plan(): void
    {
        $sys = \App\Services\Create\References\ReferenceStudy::normalizeSystems([
            ['id' => 's1', 'name' => 'iris wipe', 'look' => 'blue circle', 'move' => 'iris'], ['id' => 's2', 'name' => 'phone', 'move' => 'teleport'], ['id' => 's3', 'name' => 'button', 'move' => 'through']]);
        $this->assertSame(['iris', null, 'through'], array_map(fn ($x) => $x['move'] ?? null, $sys), 'only moves from the kit are kept');
        $moments = \App\Services\Create\References\ReferenceStudy::normalizeMoments([['start' => 10.6, 'end' => 11, 'kind' => 'sticker', 'move' => 'stamp'], ['start' => 1, 'end' => 2, 'kind' => 'text', 'move' => 'nope']], 15, 30, ['s1']);
        $byId = collect($moments)->keyBy('id');
        $this->assertSame([null, 'stamp'], [$byId['m1']['move'] ?? null, $byId['m2']['move'] ?? null], 'a moment keeps only a move from the kit');
        $brief = \App\Services\Create\PlanService::studyBrief(1471, ['summary' => 's', 'duration_seconds' => 15, 'pacing' => [], 'systems' => $sys,
            'moments' => array_map(fn ($m) => $m + ['system' => ''], $moments)]);
        $ctx = ['files' => [['asset_id' => 1471, 'purpose' => 'reference', 'asset_type' => 'video', 'reference' => ['study' => $brief]]], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'original']];
        $raw = ['summary' => 'x', 'left_out' => '',
            'reference_decisions' => [['moment' => '1471:m1', 'decision' => 'replace', 'beat' => 'Payoff', 'how' => 'Done. stamp', 'move' => 'stamp'],
                ['moment' => '1471:m2', 'decision' => 'drop', 'beat' => '', 'how' => 'x', 'carried_by' => 'not needed: y', 'move' => 'words']],
            'reference_systems' => [['system' => '1471:s1', 'decision' => 'keep', 'spec' => 'orange iris'], ['system' => '1471:s3', 'decision' => 'adapt', 'spec' => 'Create video into the plan header', 'move' => 'through'],
                ['system' => '1471:s2', 'decision' => 'adapt', 'spec' => 'panel to phone', 'move' => 'device']]];
        $p = app(\App\Services\Create\PlanService::class)->normalize($raw, $ctx, (int) $this->workspace->id);
        $moves = array_column($p['reference_systems'], 'move', 'system');
        $this->assertSame('iris', $moves['1471:s1'], "the study's move is kept when the planner names none");
        $this->assertSame('through', $moves['1471:s3']);
        $this->assertSame('device', $moves['1471:s2'], "the planner's move wins where the study had none");
        $this->assertSame('stamp', $p['reference_decisions'][0]['move']);
        $this->assertArrayNotHasKey('move', $p['reference_decisions'][1], 'a dropped moment asks for no move');
        $prompt = \App\Services\Create\Planning\PlanPrompt::system();
        $this->assertStringNotContainsString('{MOVES}', $prompt);
        $this->assertStringContainsString('through (push into an element', $prompt);
        $this->assertSame(3, \App\Services\Create\References\ReferenceStudy::VERSION, 'studies made before moves were tagged are made again');
    }

    public function test_unlimited_local_testing_lifts_limits_and_the_spend_cap_but_never_outside_local(): void {
        $this->pilot();
        config(['create.unlimited'=>true,'create.agent_provider'=>'anthropic','create.agent_model'=>'claude-opus-5-5','services.anthropic.key'=>'k','create.pilot_budget_microusd'=>0]);
        $this->assertTrue(\App\Services\Create\PilotPolicy::unlimited());
        $this->assertTrue(\App\Services\Create\PilotPolicy::enabled(), 'no spend cap is needed to enable paid testing');
        $p = \App\Services\Create\PilotPolicy::execution(['output_kind'=>'video','duration_seconds'=>15]);
        $this->assertSame([200, 32000, 600000, true, 5000000, 1250], [$p['agent']['max_calls'], $p['agent']['max_output_tokens'], $p['agent']['context_bytes'], $p['agent']['unlimited'], $p['agent']['cost_limit_microusd'], $p['agent']['credits']]);
        $this->assertSame(10, $p['critic']['max_calls']);
        \App\Services\Create\PilotPolicy::admit($p); // no budget check
        // Each call still settles at its real cost within the raised per-call ceiling.
        $this->assertSame(1250 * 4000, $p['agent']['cost_limit_microusd']);
        // Outside local/testing the switch is ignored.
        $env = app()['env']; app()['env'] = 'production';
        try { $this->assertFalse(\App\Services\Create\PilotPolicy::unlimited()); } finally { app()['env'] = $env; }
        config(['create.unlimited'=>false]);
        $this->assertFalse(\App\Services\Create\PilotPolicy::enabled(), 'without the switch, paid testing needs a spend cap again');
        config(['create.pilot_budget_microusd'=>5000000]);
        $this->assertSame(16, \App\Services\Create\PilotPolicy::execution(['output_kind'=>'video','duration_seconds'=>15])['agent']['max_calls']);
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

    public function test_a_private_media_link_stays_the_same_for_a_while_so_pages_keep_their_cached_images(): void
    {
        Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'title' => 'Thumb', 'storage_url' => 'create-upload://thumb', 'status' => 'active']);
        $storage = app(\App\Services\Media\StorageService::class);
        $this->travelTo(now()->startOfHour()->addSeconds(10));
        $first = $storage->url('create-upload://thumb');
        $this->travel(2)->minutes();
        $this->assertSame($first, $storage->url('create-upload://thumb'), 'same link within the window');
        $this->travel(4)->minutes();
        $this->assertNotSame($first, $storage->url('create-upload://thumb'), 'a fresh link after it');
        parse_str((string) parse_url($first, PHP_URL_QUERY), $q);
        $this->assertGreaterThanOrEqual(now()->subMinutes(6)->addMinutes(5)->timestamp, (int) $q['expires'], 'never valid for less than five minutes');
    }

    public function test_the_worker_can_have_the_export_listened_to_with_the_script_as_written_and_as_spoken(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $input = json_decode(DB::table('composition_runs')->where('id', $run->id)->value('input_json'), true);
        $input['plan']['narration'] = ['Start creating with WyvStudio.'];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        DB::table('create_pronunciations')->insert(['workspace_id' => $this->workspace->id, 'written' => 'WyvStudio', 'spoken' => 'Wiv Studio', 'created_at' => now(), 'updated_at' => now()]);
        config(['create.worker_token' => str_repeat('a', 64)]);
        $stt = \Mockery::mock(\App\Services\Media\MediaTranscriptionService::class);
        $stt->shouldReceive('transcribeLocalMediaWithTimestamps')->once()->andReturn(['provider_key' => 'openai', 'model' => 'whisper', 'transcript' => 'Start creating with Wiv Studio.',
            'words' => [['text' => 'Start', 'start' => 0.3, 'end' => 0.6], ['text' => 'creating', 'start' => 0.6, 'end' => 1.0]], 'segments' => []]);
        $this->app->instance(\App\Services\Media\MediaTranscriptionService::class, $stt);
        $dir = sys_get_temp_dir().'/listen-'.uniqid(); mkdir($dir);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=2:sample_rate=16000', $dir.'/a.wav']);
        $file = new \Illuminate\Http\UploadedFile($dir.'/a.wav', 'a.wav', 'audio/wav', null, true);
        $url = '/api/internal/create/runs/'.$run->id.'/listen';
        $this->withToken(str_repeat('a', 64))->post($url, ['lease_token' => str_repeat('0', 64), 'file' => $file], ['Accept' => 'application/json'])->assertForbidden();
        $r = $this->withToken(str_repeat('a', 64))->post($url, ['lease_token' => $claim['lease_token'], 'file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertEquals([['text' => 'Start', 'start' => 0.3, 'end' => 0.6], ['text' => 'creating', 'start' => 0.6, 'end' => 1.0]], $r['words']);
        $this->assertSame(['Start creating with WyvStudio.'], $r['script']['written']);
        $this->assertSame(['Start creating with Wiv Studio.'], $r['script']['spoken']);
        $this->assertEqualsWithDelta(2.0, $r['seconds'], 0.1);
        @unlink($dir.'/a.wav'); @rmdir($dir);
    }

    public function test_the_listening_check_may_pass_or_fail_what_is_heard_and_by_ear_is_not_a_pass(): void
    {
        $plan = ['requirements' => [['id' => 'req-'.str_repeat('a', 20), 'text' => 'Clear voice and quiet music', 'category' => 'audio', 'version' => 1],
            ['id' => 'req-'.str_repeat('b', 20), 'text' => 'Use the narration verbatim', 'category' => 'text', 'version' => 1]]];
        $heard = \App\Services\Create\RequirementContract::review([
            ['id' => 'req-'.str_repeat('a', 20), 'version' => 1, 'status' => 'fulfilled', 'evidence' => 'Voice 12 dB over music', 'source' => 'audio_review'],
            ['id' => 'req-'.str_repeat('b', 20), 'version' => 1, 'status' => 'by_ear', 'evidence' => 'Checked by listening']], $plan, false);
        $this->assertSame(['fulfilled', 'by_ear'], array_column($heard, 'status'));
        $this->assertSame(['audio_review', 'critic_interpretation'], array_column($heard, 'source'));
        $critic = \App\Services\Create\RequirementContract::review([['id' => 'req-'.str_repeat('a', 20), 'version' => 1, 'status' => 'fulfilled', 'evidence' => 'Sounds fine']], $plan, false);
        $this->assertSame('unverified', $critic[0]['status'], 'pictures alone still cannot pass sound');
        $review = \App\Services\Create\RunService::creativeReview(['status' => 'passed', 'findings' => [], 'requirement_checks' => [
            ['id' => 'req-'.str_repeat('a', 20), 'version' => 1, 'status' => 'fulfilled', 'evidence' => 'ok', 'source' => 'audio_review'],
            ['id' => 'req-'.str_repeat('b', 20), 'version' => 1, 'status' => 'by_ear', 'evidence' => 'listen']]], $plan);
        $this->assertSame('incomplete', $review['status'], 'something still to listen to is not a pass');
    }

    public function test_each_version_streams_from_a_signed_link_that_expires(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $path = 'create/previews/'.$run->id.'/v.mp4';
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, 'video bytes');
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<h1>x</h1>']], $path, hash('sha256', 'video bytes'));
        $show = app(\App\Http\Controllers\Api\V1\Create\CreateController::class)->show(tap(\Illuminate\Http\Request::create('/x'), fn ($r) => $r->setUserResolver(fn () => $this->owner)), $c->id)->getData(true);
        $url = $show['data']['revisions'][0]['preview_url'];
        $this->assertStringContainsString('/media/create-versions/', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
        $this->travel(46)->minutes();
        try { $this->get($url)->assertForbidden(); } finally { $this->travelBack(); }
    }

    public function test_a_beat_keeps_the_words_it_starts_on_only_when_the_script_says_them(): void
    {
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 30, 'audio' => 'original']];
        $raw = ['summary' => 'x', 'left_out' => '', 'narration' => ['Want to create your first UGC ad? Start here.', 'First, give WyvStudio your product and a clear idea.'],
            'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 4, 'idea' => 'x', 'starts_on' => 'Want to create'], ['label' => 'Step 1', 'start' => 4, 'end' => 9, 'idea' => 'x', 'starts_on' => 'First, give'],
                ['label' => 'Step 1 UI', 'start' => 9, 'end' => 15, 'idea' => 'x', 'starts_on' => 'Paste your link'], ['label' => 'Close', 'start' => 15, 'end' => 30, 'idea' => 'x']]];
        $p = app(\App\Services\Create\PlanService::class)->normalize($raw, $ctx, (int) $this->workspace->id);
        $this->assertSame(['Want to create', 'First, give', null, null], array_map(fn ($s) => $s['starts_on'] ?? null, $p['scenes']), 'words the script never says are dropped');
    }

    public function test_a_conversation_chooses_how_closely_reference_videos_are_studied(): void
    {
        $this->assertSame('high', \App\Services\Create\OutputSettings::normalize(['reference_effort' => 'high'])['reference_effort']);
        $this->assertArrayNotHasKey('reference_effort', \App\Services\Create\OutputSettings::normalize([]), 'automatic unless chosen');
        try { \App\Services\Create\OutputSettings::normalize(['reference_effort' => 'ultra']); $this->fail('An unknown effort is refused'); } catch (\Illuminate\Validation\ValidationException) { $this->addToAssertionCount(1); }
        \Illuminate\Support\Facades\Bus::fake();
        config(['create.mode' => 'agent', 'create.reference_coverage' => '']);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'reference_effort' => 'high']);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'Ref', 'storage_url' => 'create-upload://ref', 'status' => 'active']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'reference', 0);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\StudyCreateReference::class, fn ($job) => $job->assetId === $asset->id && $job->mode === 'high');
    }

    public function test_upload_requests_are_listed_answered_by_an_attachment_or_gone_without_and_reach_the_build(): void
    {
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 30, 'audio' => 'original']];
        $ask = fn ($what, $kind, $beat = 'Step 2') => ['what' => $what, 'kind' => $kind, 'why' => 'Shows the real thing', 'beat' => $beat, 'fallback' => 'Rebuilt from the site capture'];
        $raw = ['summary' => 'x', 'left_out' => '', 'scenes' => [['label' => 'Step 2', 'start' => 0, 'end' => 30, 'idea' => 'x']],
            'asks' => [$ask('Your script editor screen', 'screen'), $ask('A cat sticker', 'sticker'), $ask('Your logo', 'logo', 'Not a beat'), $ask('Product photo', 'photo'), $ask('Export flow recording', 'recording'), $ask('Founder', 'person'), $ask('Another screen', 'screen')]];
        $n = app(\App\Services\Create\PlanService::class)->normalize($raw, $ctx, (int) $this->workspace->id);
        $this->assertCount(5, $n['asks'], 'at most five');
        $this->assertNotContains('A cat sticker', array_column($n['asks'], 'what'), 'never stickers or generated things');
        $this->assertSame('', collect($n['asks'])->firstWhere('what', 'Your logo')['beat'], 'an unknown beat is cleared');
        $this->assertMatchesRegularExpression('/^ask-[a-f0-9]{8}$/', $n['asks'][0]['id']);

        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A tutorial video.', 'expected_version' => 0, 'idempotency_key' => 'ask-b1']);
        $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'ask-plan');
        $plan = json_decode(DB::table('create_plans')->where('id', $p['id'])->value('plan_json'), true);
        $plan['asks'] = array_slice($n['asks'], 0, 2);
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($plan)]);
        [$screen, $logo] = $plan['asks'];
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'title' => 'Editor', 'storage_url' => 'create-upload://editor', 'status' => 'active']);
        $v = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], $v, ['asks' => [['id' => $screen['id'], 'asset_id' => $asset->id]]]), 'the file must be attached first');
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'source', $v);
        $v++;
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], $v, ['asks' => [['id' => 'ask-00000000', 'skip' => true]]]));
        $plans->select($this->owner, $c->id, $p['id'], $v, ['asks' => [['id' => $screen['id'], 'asset_id' => $asset->id], ['id' => $logo['id'], 'skip' => true]]]);
        $quoted = \App\Services\Create\PlanService::quotePlan(json_decode(DB::table('create_plans')->where('id', $p['id'])->value('plan_json'), true), $p['id']);
        $this->assertSame($asset->id, $quoted['asks'][0]['asset_id'], 'the build learns which file answers which request');
        $this->assertTrue($quoted['asks'][1]['skipped']);
    }

    public function test_an_uploaded_character_svg_is_stored_clean_with_its_rig_check_and_reaches_the_build(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" onload="x()"><g id="head"><circle r="1"/></g></svg>';
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('maya.svg', $svg);
        $asset = app(\App\Services\Create\AttachmentUploadService::class)->upload($this->owner, $c->id, $file, 'source', 'svg-1', 0);
        $this->assertSame('image/svg+xml', $asset->mime_type);
        $this->assertStringEndsWith('.svg', $asset->storage_url);
        $stored = app(\App\Services\Media\StorageService::class)->get($asset->storage_url);
        $this->assertStringNotContainsString('onload', $stored, 'the stored file is the cleaned one');
        $this->assertFalse($asset->metadata_json['rig']['ready']);
        $this->assertContains('No "left-eye" layer.', $asset->metadata_json['rig']['problems']);
        $this->assertSame(hash('sha256', $stored), $asset->metadata_json['sha256']);
    }

    public function test_a_natural_script_for_fifteen_seconds_keeps_all_its_lines(): void
    {
        $ctx = ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'original']];
        $lines = ['Want a video ad that actually sells?', 'No camera. No editing skills. No time.', 'Paste an idea, and WyvStudio turns it into a voiced, captioned video.', 'One video, four formats.', 'Start creating with WyvStudio.'];
        $p = app(\App\Services\Create\PlanService::class)->normalize(['summary' => 'x', 'left_out' => '', 'narration' => $lines], $ctx, (int) $this->workspace->id);
        $this->assertSame($lines, $p['narration'], 'about 37 words fit 15 seconds at a natural pace');
        $long = [...$lines, 'And another line that is far too much for a fifteen second video to carry well.'];
        $this->assertCount(5, app(\App\Services\Create\PlanService::class)->normalize(['summary' => 'x', 'left_out' => '', 'narration' => $long], $ctx, (int) $this->workspace->id)['narration'], 'a script past the cap is still trimmed');
    }
}
