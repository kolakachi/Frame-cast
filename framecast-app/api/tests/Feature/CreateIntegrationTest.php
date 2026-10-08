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
        ], 'cache.default' => 'array', 'services.posthog.key' => '', 'create.enabled' => true, 'create.runtime_controls_enabled' => false, 'create.worker_ownership_required' => false, 'create.mode' => 'fixture', 'developer.operation_accounting' => true]);
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
        (require database_path('migrations/2026_10_06_160000_create_vendor_incidents.php'))->up();
        (require database_path('migrations/2026_10_06_220000_create_create_planning_jobs.php'))->up();
        (require database_path('migrations/2026_10_06_230000_create_create_stored_files.php'))->up();
        (require database_path('migrations/2026_10_07_000000_create_create_runtime_controls.php'))->up();
        (require database_path('migrations/2026_10_07_010000_create_create_worker_assignments.php'))->up();
        (require database_path('migrations/2026_10_07_120000_add_notes_to_create_attachments.php'))->up();
        $this->workspace = Workspace::create(['name' => 'Local', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $this->owner = User::create(['email' => 'local@example.test', 'name' => 'Local', 'role' => 'owner', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $this->workspace->id])->save();
        config(['create.workspaces' => [(int) $this->workspace->id], 'create.allowed_emails' => ['local@example.test', 'viewer@example.test']]);
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

    public function test_the_planner_can_design_a_3d_mascot_from_parts(): void
    {
        $spec = \App\Services\Create\MascotSpec::normalize(['seed' => 11, 'head' => ['shape' => 'egg', 'skin' => '#E9E2DA'], 'hair' => ['style' => 'curls', 'color' => '#626262', 'volume' => 3],
            'eyes' => ['style' => 'laser'], 'body' => ['color' => 'orange', 'collar' => 'turtleneck', 'pocket' => true], 'finish' => 'dither', 'arms' => ['wave']]);
        $this->assertSame(['seed' => 11, 'head' => ['shape' => 'egg', 'skin' => '#e9e2da'], 'hair' => ['style' => 'curls', 'color' => '#626262', 'volume' => 1.3],
            'body' => ['collar' => 'turtleneck', 'pocket' => true], 'finish' => 'dither'], $spec, 'only parts the builder has; colours as hex; numbers clamped');
        $this->assertNull(\App\Services\Create\MascotSpec::normalize('a cute mascot'));
        $this->assertStringContainsString('hair.style: curls|waves|bob|spikes|bun|none', \App\Services\Create\Planning\PlanPrompt::system());
        $this->assertStringNotContainsString('{MASCOT}', \App\Services\Create\Planning\PlanPrompt::system());
        $p = app(\App\Services\Create\PlanService::class)->normalize(['summary' => 'x', 'left_out' => '', 'mascot3d' => ['spec' => ['hair' => ['style' => 'bob'], 'finish' => 'toon'], 'why' => 'A friendly guide in brand orange']],
            ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'original']], (int) $this->workspace->id);
        $this->assertSame(['spec' => ['seed' => 7, 'hair' => ['style' => 'bob'], 'finish' => 'toon'], 'why' => 'A friendly guide in brand orange'], $p['mascot3d']);
        // Its turnaround is the approval, so a character image is never bought for it; 3D objects are modelled, not bought.
        $q = app(\App\Services\Create\PlanService::class)->normalize(['summary' => 'x', 'left_out' => '',
            'look_first' => true, 'mascot3d' => ['spec' => ['head' => ['shape' => 'sphere'], 'hair' => ['style' => 'none'], 'finish' => 'dither'], 'why' => 'From the avatar', 'missing' => 'round ears and fur'],
            'media' => [['kind' => 'character_poses', 'description' => 'Mascot master preview'], ['kind' => 'sfx', 'description' => 'Card thuds']],
            'props3d' => [['name' => 'laptop', 'looks' => 'open laptop, black screen with a play mark', 'moments' => ['1590:m6'], 'spin' => true], ['name' => ''], 'nonsense']],
            ['files' => [], 'voices' => [], 'settings' => ['duration_seconds' => 15, 'audio' => 'original']], (int) $this->workspace->id);
        $this->assertSame('round ears and fur', $q['mascot3d']['missing']);
        $this->assertSame(['sfx'], array_column($q['media'], 'kind'));
        $this->assertFalse($q['look_first'], 'no storyboard when nothing expensive depends on an approved look');
        $this->assertSame([['name' => 'laptop', 'looks' => 'open laptop, black screen with a play mark', 'moments' => ['1590:m6'], 'spin' => true]], $q['props3d']);
        $this->assertStringContainsString('design the mascot from that image', \App\Services\Create\Planning\PlanPrompt::system());
        // The approved plan the build receives carries the mascot and its objects (it once dropped them, so builds never saw them).
        $approved = \App\Services\Create\PlanService::quotePlan($q + ['reference_match' => 'exact', 'reference_layout' => [['moment' => '1590:m6']], 'reference_decisions' => [['moment' => '1590:m6', 'decision' => 'keep']]], 'plan-1');
        foreach (['mascot3d', 'props3d', 'reference_match', 'reference_layout', 'reference_decisions'] as $key) $this->assertArrayHasKey($key, $approved, $key);
        // Its speech and expressions need nothing bought; it has no arms for gestures.
        $perf = fn ($kind) => ['id' => 'perf-'.$kind, 'kind' => $kind, 'action' => $kind, 'start' => 0, 'end' => 3, 'route' => 'mascot3d', 'tool' => null];
        $plan = ['narration' => ['Want a video ad that sells?'], 'mascot3d' => $p['mascot3d'], 'character_performance' => [$perf('speech'), $perf('facial')]];
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues($plan, ['duration_seconds' => 15, 'audio' => 'original'], []));
        $this->assertStringContainsString('no arms', \App\Services\Create\CharacterPerformance::issues(['character_performance' => [['action' => 'waves at the viewer'] + $perf('body')]] + $plan, ['duration_seconds' => 15], [])[0]['message']);
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues(['character_performance' => [['action' => 'slides in from the edge and turns to face the viewer'] + $perf('body')]] + $plan, ['duration_seconds' => 15, 'audio' => 'original'], []), 'moving the whole figure needs no arms');
        $this->assertSame([], \App\Services\Create\CharacterPerformance::issues(['character_performance' => [['start' => null, 'end' => null] + $perf('speech')]] + $plan, ['duration_seconds' => 15, 'audio' => 'original'], []), 'a drawn character talking throughout needs no chosen span');
        $this->assertStringContainsString('no 3D mascot', \App\Services\Create\CharacterPerformance::issues(['narration' => ['x'], 'character_performance' => [$perf('facial')]], ['duration_seconds' => 15], [])[0]['message']);
    }

    public function test_a_script_too_long_for_the_video_is_sent_back_at_planning(): void
    {
        $scenes = [['label' => 'All', 'start' => 0, 'end' => 15]];
        $long = ['Want a video ad that sells?', 'No camera. No editing skills. No time.', 'WyvStudio turns your idea into a video.',
            'One video becomes four ready-to-post formats, voiced and captioned.', 'No camera needed. No editing required. Start creating.'];
        $problems = \App\Services\Create\Planning\PlanPrompt::problems(['scenes' => $scenes, 'narration' => $long], ['settings' => ['duration_seconds' => 15]]);
        $this->assertNotEmpty(array_filter($problems, fn ($p) => str_contains($p, 'cut it to about 33 words')));
        $this->assertSame([], \App\Services\Create\Planning\PlanPrompt::problems(['scenes' => $scenes, 'narration' => ['Want a video ad that sells?', 'Start creating.']], ['settings' => ['duration_seconds' => 15]]));
    }

    public function test_copying_exactly_turns_each_kept_moment_into_a_layout_requirement(): void
    {
        $this->assertSame(7.85, \App\Services\Create\References\ReferenceStudy::keyTime(['start' => 7.4, 'end' => 8.1], 15), 'late in the moment, once its elements have arrived');
        $this->assertSame(14.95, \App\Services\Create\References\ReferenceStudy::keyTime(['start' => 14.6, 'end' => 15.2], 15), 'never past the end');
        $tile = ['role' => 'tile', 'label' => 'checkout tile', 'box' => [0.05, 0.09, 0.29, 0.84]];
        $study = ['summary' => 's', 'duration_seconds' => 15, 'pacing' => [],
            'moments' => [['id' => 'm14', 'start' => 7.4, 'end' => 8.1, 'kind' => 'ui', 'move' => 'type'], ['id' => 'm18', 'start' => 9.7, 'end' => 10.6, 'kind' => 'ui']],
            'layout' => ['version' => 1, 'moments' => [['moment' => 'm14', 'at' => 7.85, 'background' => 'light grid', 'elements' => [$tile]]]]];
        $brief = \App\Services\Create\PlanService::studyBrief(1471, $study);
        $this->assertSame([$tile], $brief['moments'][0]['layout']['elements'], 'the planner sees where each element sits');
        $ctx = ['files' => [['asset_id' => 1471, 'purpose' => 'reference', 'asset_type' => 'video', 'reference' => ['study' => $brief]]], 'voices' => [],
            'settings' => ['duration_seconds' => 15, 'audio' => 'original', 'reference_match' => 'exact']];
        $raw = ['summary' => 'x', 'left_out' => '', 'reference_decisions' => [
            ['moment' => '1471:m14', 'decision' => 'replace', 'beat' => 'Dashboard', 'how' => 'Script tile types in the checkout slot'],
            ['moment' => '1471:m18', 'decision' => 'drop', 'beat' => '', 'how' => 'x', 'carried_by' => 'not needed: no figure']]];
        $p = app(\App\Services\Create\PlanService::class)->normalize($raw, $ctx, (int) $this->workspace->id);
        $this->assertSame('exact', $p['reference_match']);
        $this->assertSame([['moment' => '1471:m14', 'beat' => 'Dashboard', 'start' => 7.4, 'end' => 8.1, 'at' => 7.85, 'move' => 'type', 'content' => 'Script tile types in the checkout slot',
            'background' => 'light grid', 'elements' => [$tile]]], $p['reference_layout'], 'kept and replaced moments only, with the reference slots');
        $loose = app(\App\Services\Create\PlanService::class)->normalize($raw, ['settings' => ['reference_match' => 'inspired'] + $ctx['settings']] + $ctx, (int) $this->workspace->id);
        $this->assertArrayNotHasKey('reference_layout', $loose, 'inspired plans are not held to the layout');
    }

    public function test_how_closely_to_follow_a_reference_video_is_settled_before_planning(): void
    {
        $m = fn ($t) => \App\Services\Create\ReferenceMatch::infer($t);
        $this->assertSame('exact', $m('The plan is to create a video from the reference video, copy it frame by frame, but replace the avatar with ours. The component placements should remain.'));
        $this->assertSame('exact', $m('Recreate the attached reference move for move with my brand.'));
        $this->assertSame('inspired', $m('Something inspired by this reference, my own layout.'));
        $this->assertNull($m('A promo for my shoes using the attached video.'), 'neither: ask');
        $this->assertNull($m('Show exactly four formats.'), 'an unrelated "exactly" is not a copy request');
        $this->assertNull($m('Copy it frame by frame but loosely.'), 'both: ask');
        $this->assertNull($m('Put "copy it frame by frame" on screen.'), 'quoted copy is content');
        $this->assertSame('exact', \App\Services\Create\ReferenceMatch::answer('Exactly.'));
        $this->assertSame('inspired', \App\Services\Create\ReferenceMatch::answer('inspired'));
        // Once set, a follow-up only changes it when it clearly says so ("like the reference" once flipped an exact copy).
        $f = fn ($t) => \App\Services\Create\ReferenceMatch::change($t);
        $this->assertNull($f('Put a 3D object in each problem card like the reference: a camera, scissors and a clock.'));
        $this->assertNull($m('Put a 3D object in each card like the reference.'), '"like the reference" is not a request for inspiration');
        $this->assertSame('inspired', $f('Actually, make it loosely inspired by the reference.'));
        $this->assertSame('exact', $f('Copy it exactly.'));
        $this->assertSame('exact', $f('exactly'));

        $ref = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'ref.mp4', 'storage_url' => 'create-upload://r', 'status' => 'active']);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->attach($this->owner, $c->id, $ref->id, 'reference', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A promo for WyvStudio from this.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        $plans = app(\App\Services\Create\PlanService::class);
        $v = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $asked = $plans->propose($this->owner, $c->id, $v, 'p1');
        $this->assertSame('reference_match', $asked['needs_answer']);
        $this->assertSame(0, DB::table('create_plans')->where('conversation_id', $c->id)->count(), 'nothing is planned until it is settled');
        $this->assertSame(\App\Services\Create\ReferenceMatch::QUESTION, DB::table('create_messages')->where('conversation_id', $c->id)->orderByDesc('sequence')->value('content'));
        $plans->propose($this->owner, $c->id, $v + 1, 'p2');
        $this->assertSame(1, DB::table('create_messages')->where('conversation_id', $c->id)->where('content', \App\Services\Create\ReferenceMatch::QUESTION)->count(), 'asked once');
        // The answer sets it, and says so.
        $this->conversations->message($this->owner, $c->id, ['content' => 'exactly', 'expected_version' => $v + 1, 'idempotency_key' => 'b2']);
        $this->assertSame('exact', json_decode($this->conversations->conversation($this->owner, $c->id)->settings_json, true)['reference_match']);
        $this->assertStringContainsString('matched exactly', DB::table('create_messages')->where('conversation_id', $c->id)->orderByDesc('sequence')->value('content'));
    }

    public function test_offline_plan_is_a_free_assistant_turn_with_editable_selections(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Launch video with callouts "Sit-stand in 8 seconds" and "Holds two monitors".', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $v = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, $v, 'plan-1');
        $this->assertSame('offline-planner-v1', $p['provider']);
        // The turn's activity is saved with the plan, and the live record ends when planning does.
        $this->assertIsInt($p['plan']['activity']['worked_ms']);
        $this->assertMatchesRegularExpression('/^Planned \d+ shots?$/', end($p['plan']['activity']['steps'])['label']);
        $this->assertFalse(\App\Services\Create\PlanActivity::live($c->id)['running']);
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id/plan-activity")->assertOk()->assertJsonPath('data.running', false);
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

    public function test_planning_has_no_daily_cap_and_failures_cost_nothing(): void
    {
        $c = $this->brief(); $plans = app(\App\Services\Create\PlanService::class);
        $plans->propose($this->owner, $c->id, 1, 'one');
        $plans->propose($this->owner, $c->id, 2, 'two');
        config(['create.mode' => 'agent', 'create.planner' => 'replicate', 'services.replicate.api_token' => 't']);
        Http::fake(['api.replicate.com/*' => Http::response(['error' => 'down'], 500)]);
        $this->rejected(502, fn () => $plans->propose($this->owner, $c->id, 3, 'three'));
        $this->assertSame(2, DB::table('create_plans')->count(), 'two plans in a day: no cap; the failed third costs nothing');
        $viewer = User::create(['email' => 'viewer@example.test', 'name' => 'V', 'role' => 'viewer', 'status' => 'active']);
        $viewer->forceFill(['workspace_id' => $this->workspace->id])->save();
        $this->rejected(403, fn () => $plans->propose($viewer, $c->id, 3, 'four'));
    }

    public function test_free_edit_rerenders_variables_without_a_model_call_or_credits(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $html = '<!doctype html><html lang="en" data-composition-variables=\'[{"id":"headline","type":"string","label":"Headline","default":"Your product. Your story."},{"id":"cta","type":"string","label":"Button text","default":"Explore the collection"},{"id":"color_background","type":"color","label":"Background","default":"#17151d"},{"id":"color_accent","type":"color","label":"Accent","default":"#ff6b32"}]\'><head></head><body><div id="root" data-composition-id="main"><div id="cta">Explore the collection</div></div></body></html>';
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => $html]], 'create/previews/test/v1.mp4', 'hash');
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
        $this->runs->finish($run->id, $lease, ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<img src="'.$record['name'].'">']], 'create/previews/test/v1.mp4', 'h');
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
        $this->assertSame([(int) ceil(($imageCredits + 0 + 3) * 1.5), 3 + 20], [$media['total_credits'], $media['max_calls']]);
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
        // Reconciled as never made (the operator's evidence), the item is no longer "unknown": a new run may buy it.
        $attempt = DB::table('composition_attempts')->where('run_id', $run2->id)->where('attempt_key', 'plan-media-2')->value('id');
        app(\App\Services\Create\ReconciliationService::class)->reconcile(new \App\Services\Create\VerifiedAttemptReceipt($attempt, 'failed', 'not-sent-voice', 0, 'No provider request was made.'), true);
        $this->assertSame('failed', DB::table('create_plan_media')->where('plan_id', $plan['id'])->where('kind', 'voiceover')->value('status'));
        $this->assertSame('cold brew pour ice', \App\Services\Create\PlanMediaExecutor::searchTerms('Vertical slow-motion cold brew pour over ice, dark background'));
    }

    public function test_each_job_of_a_take_is_recorded_restarted_once_and_collected_without_an_unknown_outcome(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.pilot_budget_microusd' => 50_000_000]);
        $c = $this->brief();
        $plan = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-take');
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $lines = ['Every brand starts somewhere, usually at a kitchen table late at night with an idea and no camera.', 'Paste one line into WyvStudio and it turns it into a voiced, captioned video.', 'Schedule it to YouTube, TikTok or Instagram, and start with the nine dollar Test Pass today.'];
        $json['media'] = [['kind' => 'ugc_take', 'description' => 'A founder talks to camera in a bright kitchen', 'presenter' => 'none', 'engine' => 'omni', 'credits' => 1]];
        $json['narration'] = $json['selections']['narration'] = $lines;
        $json['shot_context'] = ['has_avatar' => false, 'aspect_ratio' => '9:16', 'language' => 'en'];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $q = $this->conversations->quote($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $take = collect($q->payload_json['plan_media'])->firstWhere('kind', 'ugc_take');
        $this->assertGreaterThanOrEqual(3, count($take['segments']), 'three long lines are three parts');
        $index = array_search($take, $q->payload_json['plan_media'], true);

        $log = [];
        app()->instance(\App\Services\Create\PlanMediaExecutor::class, new class($log) extends \App\Services\Create\PlanMediaExecutor {
            private array $polls = [];
            public function __construct(private array &$log) {}
            public function startJob(string $kind, string $description, array $ctx, int $k): string {
                $this->log[] = 'start '.$k;
                // The second part is refused once at submission: nothing was made, so it is simply started again.
                if ($k === 1 && ! in_array('refused 1', $this->log, true)) { $this->log[] = 'refused 1'; throw new \RuntimeException('Veo submit failed: busy'); }
                return 'p'.$k.'-'.count($this->log);
            }
            public function pollJob(string $id): array {
                $this->log[] = 'poll '.$id;
                $n = $this->polls[$id] = ($this->polls[$id] ?? 0) + 1;
                // The third part fails once at the provider and is restarted; everything else lands on its second check.
                if (str_starts_with($id, 'p2-') && ! in_array('failed '.$id, $this->log, true) && ! collect($this->log)->contains(fn ($l) => str_starts_with($l, 'failed p2'))) { $this->log[] = 'failed '.$id; return ['status' => 'failed', 'declined' => false, 'error' => 'interrupted']; }
                return $n >= 2 ? ['status' => 'succeeded', 'url' => 'https://example.test/'.$id.'.mp4'] : ['status' => 'running'];
            }
            public function cancelJob(string $id): void { $this->log[] = 'cancel '.$id; }
            public function finishGenerated(string $kind, string $description, array $ctx, string $dir, array $urls, array $ids, string $engine): array {
                $this->log[] = 'finish '.implode(',', $ids);
                \Illuminate\Support\Facades\Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=16x16:d=0.2', $dir.'/take.mp4']);
                return ['path' => $dir.'/take.mp4', 'mime' => 'video/mp4', 'title' => 'UGC take', 'provider_id' => 'take-1', 'engine' => $engine, 'line' => 'x', 'speech_mode' => 'native', 'speech_check' => ['status' => 'ok', 'missing' => []]];
            }
        });
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-take', true);
        $claim = $this->runs->claim();
        $service = app(\App\Services\Create\PlanMediaService::class);
        $row = fn () => json_decode((string) DB::table('create_plan_media')->where('plan_id', $plan['id'])->where('kind', 'ugc_take')->value('record_json'), true);

        $first = $service->produce($run->id, $claim['lease_token'], $index);
        $this->assertSame('pending', $first['status']);
        $jobs = $row()['jobs'];
        $this->assertNotNull($jobs[0]); $this->assertNull($jobs[1], 'the refused part is recorded as not started'); $this->assertNotNull($jobs[2]);

        for ($i = 0; $i < 6 && ($r = $service->produce($run->id, $claim['lease_token'], $index))['status'] === 'pending'; $i++);
        $this->assertSame('succeeded', $r['status']);
        $this->assertSame((int) $take['credits'], $r['charged_credits'], 'charged once, at the quoted price, only when delivered');
        $this->assertCount(1, $r['failed_jobs'], 'the failed part is kept on record');
        $this->assertNotContains('unknown', DB::table('create_plan_media')->where('plan_id', $plan['id'])->pluck('status')->all());
        $this->assertSame(2, collect($log)->filter(fn ($l) => $l === 'start 1')->count(), 'the refused part was started again once');
        $this->assertSame(2, collect($log)->filter(fn ($l) => $l === 'start 2')->count(), 'the failed part was restarted once');
    }

    public function test_the_user_corrects_the_agreement_and_the_build_receives_it(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief();
        $service = app(\App\Services\Create\PlanService::class);
        $plan = $service->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-agree');
        $this->assertSame(['preserve', 'replace', 'flexible', 'required'], array_keys($plan['plan']['agreement']));
        $edited = ['preserve' => ['The drawn night world'], 'replace' => ['DistroKid with WyvStudio'], 'flexible' => ['Shot count'], 'required' => ['The creator stays on the laptop until the reaction', 'Start with the $9 Test Pass']];
        $saved = $service->select($this->owner, $c->id, $plan['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['agreement' => $edited]);
        $this->assertSame($edited, $saved['plan']['selections']['agreement']);
        $this->rejected(422, fn () => $service->select($this->owner, $c->id, $plan['id'], (int) $this->conversations->conversation($this->owner, $c->id)->version, ['agreement' => ['required' => []]]));
        $this->assertSame($edited, \App\Services\Create\PlanService::forQuote($this->conversations->conversation($this->owner, $c->id))['agreement'], 'the build follows what the user approved');
    }

    public function test_the_character_and_the_storyboard_are_separate_approved_steps_and_a_note_redraws_one_panel(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.pilot_budget_microusd' => 50_000_000, 'services.anthropic.key' => '']);
        $c = $this->brief();
        $service = app(\App\Services\Create\PlanService::class);
        $plan = $service->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-board');
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $json['media'] = [
            ['kind' => 'reference_sheet', 'description' => 'anime night', 'subjects' => [['name' => 'Maya', 'kind' => 'character', 'looks' => 'red coat'], ['name' => 'Shop', 'kind' => 'place', 'looks' => 'candle shop']], 'credits' => 70],
            ['kind' => 'generated_shot', 'description' => 'Maya lights a candle', 'engine' => 'seedance25', 'refs' => ['Maya', 'Shop'], 'action' => 'she lights the wick', 'gaze' => 'on the flame', 'seconds' => 5, 'credits' => 1],
            ['kind' => 'generated_shot', 'description' => 'Maya reads her phone', 'engine' => 'omni', 'refs' => ['Maya'], 'action' => 'she reads the post', 'gaze' => 'on her phone', 'seconds' => 5, 'credits' => 1]];
        $json['shot_context'] = ['has_avatar' => false, 'aspect_ratio' => '9:16', 'language' => 'en'];
        $json['look_first'] = $json['selections']['look_first'] = true;
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;

        // Step 1, the character: only the sheet is drawn; no video is built.
        $look = $this->conversations->quote($this->owner, $c->id, $version());
        $this->assertSame(['character', ['reference_sheet'], true], [$look->payload_json['step'], array_column($look->payload_json['plan_media'], 'kind'), $look->payload_json['media_only']]);
        $this->assertArrayNotHasKey('agent', $look->payload_json['execution_policy'], 'a step buys images only');
        $drawn = [];
        app()->instance(\App\Services\Create\PlanMediaExecutor::class, new class($drawn) extends \App\Services\Create\PlanMediaExecutor {
            public function __construct(private array &$drawn) {}
            public function produce(string $kind, string $description, array $ctx, string $dir): array {
                $png = fn ($tag) => (function () use ($dir, $tag) { $p = $dir.'/'.$tag.'.png'; \Illuminate\Support\Facades\Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=0x'.substr(md5($tag), 0, 6).':s=8x8', '-frames:v', '1', $p]); return $p; })();
                if ($kind === 'reference_sheet') return ['path' => $png('maya'), 'mime' => 'image/png', 'title' => 'Sheet', 'provider_id' => 'sheet-1', 'extra' => [['path' => $png('shop'), 'title' => 'Shop', 'pose' => 'Shop']], 'poses' => ['Maya', 'Shop'], 'character_contract' => \App\Services\Create\CharacterApproval::CONTRACT];
                $prior = $ctx['prior_panels'] ?? []; $files = []; $hashes = []; $new = 0;
                foreach ($ctx['shot']['panels'] as $k => $panel) {
                    $h = \App\Services\Create\Storyboard::panelHash($panel, $ctx['cast'], (string) $ctx['character_style'], $panel['aspect'] ?? '9:16');
                    if (! isset($prior[$h])) { $new++; $this->drawn[] = $panel['label']; }
                    $files[] = ['path' => $png('panel-'.$k.'-'.substr($h, 0, 8)), 'title' => $panel['label'], 'pose' => $panel['label']]; $hashes[] = $h;
                }
                $first = array_shift($files);
                return ['path' => $first['path'], 'mime' => 'image/png', 'title' => 'Panel 1', 'provider_id' => 'board-'.count($this->drawn), 'extra' => $files, 'poses' => array_column($ctx['shot']['panels'], 'label'),
                    'panel_hashes' => $hashes, 'panel_checks' => ['status' => 'unverified', 'panels' => []], 'credits' => $new * \App\Services\Create\ShotRoute::PANEL_CREDITS, 'character_contract' => \App\Services\Create\CharacterApproval::CONTRACT];
            }
        });
        $run = $this->conversations->approve($this->owner, $c->id, $look->id, 'approve-look', true);
        // Approving the plan freezes it: its content no longer changes; only the steps move on.
        $this->assertTrue(\App\Services\Create\PlanService::approved($plan['id']));
        $this->rejected(409, fn () => $service->select($this->owner, $c->id, $plan['id'], $version(), ['callouts' => ['A new line']]));
        $claim = $this->runs->claim();
        $media = app(\App\Services\Create\PlanMediaService::class);
        $media->produce($run->id, $claim['lease_token'], 0);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'step_ready', 'summary' => 'The character is ready for you to check.'], null, null);
        $this->assertSame('step_ready', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $present = fn () => $service->present(DB::table('create_plans')->where('id', $plan['id'])->first(), DB::table('create_conversations')->where('id', $c->id)->first());
        $shown = $present()['character_preview'];
        $this->assertSame(['Maya', 'Shop'], array_column($shown['images'], 'label'), 'the character is reviewed on its own');
        $this->assertSame('reference_sheet', $shown['kind']);
        $this->assertNull($present()['storyboard_preview']);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, $version(), 'full_video'));

        // Step 2, the storyboard: drawn only from the approved character.
        // The next step's price is shown before approving (a preview quote that cannot itself be approved) ...
        $preview = $this->conversations->quote($this->owner, $c->id, $version(), null, ['character_approval' => $shown['token']]);
        $this->assertSame('storyboard', $preview->payload_json['step']);
        $this->rejected(422, fn () => $this->conversations->approve($this->owner, $c->id, $preview->id, 'approve-preview', true));
        // ... and one action approves the character and starts the storyboard; a higher price is shown, not charged.
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $url = "/api/v1/create/conversations/$c->id/plans/{$plan['id']}/approve-step";
        $this->actingAs($this->owner)->postJson($url, ['step' => 'character', 'token' => $shown['token'], 'expected_version' => $version(), 'max_credits' => $preview->credits_max - 1, 'idempotency_key' => 'step-low'])
            ->assertOk()->assertJsonPath('data.needs_confirm', true);
        $started = $this->actingAs($this->owner)->postJson($url, ['step' => 'character', 'token' => $shown['token'], 'expected_version' => $version(), 'max_credits' => $preview->credits_max, 'idempotency_key' => 'step-board'])
            ->assertStatus(202)->assertJsonPath('data.build_stage', 'storyboard');
        $run = DB::table('composition_runs')->where('id', $started->json('data.id'))->first();
        $boardInput = json_decode($run->input_json, true);
        $this->assertSame(['reference_sheet' => 0, 'storyboard' => 2 * \App\Services\Create\ShotRoute::PANEL_CREDITS], array_column($boardInput['plan_media'], 'credits', 'kind'), 'the approved sheet is not bought again');
        $claim = $this->runs->claim();
        $media->produce($run->id, $claim['lease_token'], 0);
        $board = $media->produce($run->id, $claim['lease_token'], 1);
        $this->assertSame([2 * \App\Services\Create\ShotRoute::PANEL_CREDITS, ['Panel 1', 'Panel 2']], [$board['charged_credits'], $this->collectDrawn($drawn)]);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'step_ready', 'summary' => 'The storyboard is ready for you to check.'], null, null);
        $frames = $present()['storyboard_preview'];
        $this->assertSame(['Panel 1', 'Panel 2'], array_column($frames['images'], 'label'));
        $this->assertFalse($frames['approved']);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, $version(), 'full_video'));

        // Step 3, the video: made from the approved character and frames.
        $service->select($this->owner, $c->id, $plan['id'], $version(), ['storyboard_approval' => $frames['token']]);
        $full = $this->conversations->quote($this->owner, $c->id, $version());
        $this->assertSame('full_video', $full->payload_json['build_stage']);
        $ids = array_column($full->payload_json['input_files'], 'asset_id');
        $this->assertSame(array_values(array_unique($ids)), $ids, 'each input once: the worker refuses a manifest with a repeated asset');
        $items = collect($full->payload_json['plan_media'])->keyBy(fn ($m) => $m['kind'].($m['first_frame'] ?? ''));
        $this->assertSame(0, $items['storyboard']['credits'], 'the approved panels are not bought again');
        $this->assertTrue($items->has('generated_shotPanel 1') && $items->has('generated_shotPanel 2'), 'each shot starts from its approved panel: '.json_encode($items->keys()));

        // A new look for the character asks for the character again, and the frames after it.
        $service->select($this->owner, $c->id, $plan['id'], $version(), ['character_looks' => ['Maya' => 'green coat, short hair']]);
        $this->assertFalse($present()['character_preview']['approved'] ?? false);
        $this->assertSame('character', $this->conversations->quote($this->owner, $c->id, $version())->payload_json['step']);
        $service->select($this->owner, $c->id, $plan['id'], $version(), ['character_looks' => []]);
        $service->select($this->owner, $c->id, $plan['id'], $version(), ['panel_notes' => ['2' => 'phone in her left hand']]);
        $redo = $this->conversations->quote($this->owner, $c->id, $version(), 'storyboard');
        $this->assertSame(\App\Services\Create\ShotRoute::PANEL_CREDITS, collect($redo->payload_json['plan_media'])->firstWhere('kind', 'storyboard')['credits'], 'a note on one panel redraws only that panel');
    }

    private function collectDrawn(array $drawn): array { return $drawn; }

    public function test_a_run_that_stops_while_a_clip_renders_hands_it_to_the_next_run_without_a_hold(): void
    {
        $this->pilot(); config(['create.planner' => 'offline', 'create.pilot_budget_microusd' => 50_000_000]);
        $c = $this->brief();
        $plan = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-hand');
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $json['media'] = [['kind' => 'generated_shot', 'description' => 'A candle flame catches', 'engine' => 'seedance25', 'action' => 'the flame catches', 'seconds' => 5, 'credits' => 1]];
        $json['shot_context'] = ['has_avatar' => false, 'aspect_ratio' => '9:16', 'language' => 'en'];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $starts = 0;
        app()->instance(\App\Services\Create\PlanMediaExecutor::class, new class($starts) extends \App\Services\Create\PlanMediaExecutor {
            public function __construct(private int &$starts) {}
            public function startJob(string $kind, string $description, array $ctx, int $k): string { $this->starts++; return 'pred-slow'; }
            public function pollJob(string $id): array { return ['status' => 'running']; }
        });
        $q = $this->conversations->quote($this->owner, $c->id, $version());
        $index = array_search('generated_shot', array_column($q->payload_json['plan_media'], 'kind'), true);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-hand', true);
        $claim = $this->runs->claim();
        $media = app(\App\Services\Create\PlanMediaService::class);
        $this->assertSame('pending', $media->produce($run->id, $claim['lease_token'], $index)['status']);
        $this->assertSame('pending', $media->produce($run->id, $claim['lease_token'], $index)['status']);

        // The worker gives up waiting and stops: no hold, no charge, and the user is told the clip is still being made.
        $stopped = $this->runs->workerStopped($run->id, $claim['lease_token']);
        $this->assertSame(['failed', 1], [$stopped['status'], $stopped['handed_over']]);
        $row = DB::table('composition_runs')->where('id', $run->id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('still being made', $row->error);
        $this->assertSame(0, (int) DB::table('composition_attempts')->where('run_id', $run->id)->sum('charged_credits'));

        // The next run collects the same prediction instead of starting another.
        $q2 = $this->conversations->quote($this->owner, $c->id, $version());
        $run2 = $this->conversations->approve($this->owner, $c->id, $q2->id, 'approve-hand-2', true);
        $claim2 = $this->runs->claim();
        $this->assertSame('pending', $media->produce($run2->id, $claim2['lease_token'], $index)['status']);
        $this->assertSame(1, $starts, 'adopted, not bought again');
        $this->assertSame($run2->id, json_decode((string) DB::table('create_plan_media')->where('plan_id', $plan['id'])->where('kind', 'generated_shot')->value('record_json'), true)['run_id']);
    }

    public function test_saved_styles_come_from_a_version_or_a_reference_and_steer_later_quotes(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $html = '<html data-composition-variables=\'[{"id":"color_background","type":"color","label":"Background","default":"#F2EDE4"},{"id":"color_accent","type":"color","label":"Accent","default":"#E07A52"},{"id":"headline","type":"string","label":"Headline","default":"Hi"}]\'><style>h1{font-family:"Inter", sans-serif}</style></html>';
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => $html]], 'create/previews/test/v1.mp4', 'h');
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
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'V1', 'bundle' => ['index.html' => '<html></html>'], 'delivery_checks' => $checks, 'creative_review' => ['status' => 'passed', 'findings' => ['Fix the headline'], 'unknown' => 'discard']], 'create/previews/test/v1.mp4', 'h');
        $meta = json_decode(DB::table('composition_revisions')->where('run_id', $run->id)->value('metadata_json'), true);
        $this->assertEquals(['ok' => false, 'safe_area' => [['selector' => '#cta', 'time' => 13.46, 'message' => 'Collides with the caption band']], 'edges' => [], 'contrast' => [],
            'pacing' => [], 'loudness' => ['status' => 'levelled', 'lufs' => -14.0, 'from' => -23.4, 'peak' => -1.6]], $meta['delivery_checks']);
        $this->assertNull(\App\Services\Create\RunService::deliveryChecks('nope'));
        $heard = \App\Services\Create\RunService::deliveryChecks(['ok' => true, 'audio' => ['ok' => false, 'problems' => ['The music may be too loud under the voice.', 42], 'script_coverage' => 0.96123, 'mix' => ['secret' => 1]]]);
        $this->assertSame(['ok' => false, 'problems' => ['The music may be too loud under the voice.'], 'script_coverage' => 0.961], $heard['audio'], 'what was heard is kept in plain words only');
        $this->assertSame(['status' => 'issues', 'findings' => ['Fix the headline']], $meta['creative_review']);
        $this->assertSame('incomplete', RunService::creativeReview(null)['status']);
        $this->assertSame('ready', RunService::creativeReview(['status' => 'ready', 'findings' => []])['status'], 'checked and nothing open: ready for the user');
        $this->assertSame('issues', RunService::creativeReview(['status' => 'ready', 'findings' => ['At 3.2 s: the card is missing from its slot']])['status']);
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
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => $summary, 'bundle' => ['index.html' => '<html></html>']], 'create/previews/test/v1.mp4', 'h');
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
        // A new creation first checks whether it must ask anything (here: no question), then plans.
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['id' => 'ask', 'content' => [['type' => 'text', 'text' => '{"question": null}']], 'usage' => []])
            ->push(['id' => 'first', 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'usage' => []])
            // The follow-up is clear, so the change check asks nothing.
            ->push(['id' => 'clear', 'content' => [['type' => 'text', 'text' => '{"question": null}']], 'usage' => []])
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
        $this->assertSame(100, $plain->payload_json['execution_policy']['agent']['max_calls'], 'Standard effort: room for 100 calls within a credit budget');
        $json = json_decode(DB::table('create_plans')->where('id', $plan['id'])->value('plan_json'), true);
        $json['media'] = [['kind' => 'character_poses', 'description' => 'Mascot: talking', 'credits' => 210]];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $version = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, $version, 'full_video'));
        $candidate = $this->seedCharacter($plan['id'], $json, $c);
        $json['selections']['character_approval'] = $candidate['token'];
        DB::table('create_plans')->where('id', $plan['id'])->update(['plan_json' => json_encode($json)]);
        $withCharacter = $this->conversations->quote($this->owner, $c->id, $version, 'full_video');
        $this->assertGreaterThanOrEqual(20, $withCharacter->payload_json['execution_policy']['agent']['max_calls']);
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
        \Illuminate\Support\Facades\Storage::disk('local')->put('create/inputs/test/frozen-reference.mp4', $bytes);
        $frozen = ['purpose' => 'reference', 'asset_type' => 'video', 'storage_path' => 'create/inputs/test/frozen-reference.mp4',
            'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $frames = $sheets->characterStyleImages([
            ['purpose' => 'reference', 'asset_type' => 'image', 'reference' => ['from' => 'page']],
            $frozen,
            ['purpose' => 'source', 'asset_type' => 'image'],
        ], $tmp);
        $this->assertCount(2, $frames, 'Only the uploaded style reference supplies character treatment, never the brand page or source image');
        foreach ($frames as $frame) $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($frame));
        \Illuminate\Support\Facades\Storage::disk('local')->put('create/inputs/test/frozen-reference.mp4', 'changed');
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
        $this->assertSame([true, false, 100], [$q->payload_json['look_first'], $q->payload_json['from_look'], $q->payload_json['execution_policy']['agent']['max_calls']], 'the look run has the same room; its budget and the no-progress guard stop it');
        $this->assertArrayNotHasKey('critic', $q->payload_json['execution_policy'], 'a look run is never reviewed (reviewedBuild), so nothing is held for a reviewer (BG1)');

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
        $this->assertSame(100, $q2->payload_json['execution_policy']['agent']['max_calls']);
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
            'review' => [['time' => 1, 'score' => 9, 'problems' => []], ['time' => 7, 'score' => 6, 'problems' => ['Word too small', 'x', 'y', 'dropped fourth']], 'junk']], 'create/previews/test/v1.mp4', 'h');
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
        // The history goes as the worker wrote it, with a cache marker on its last block so the next turn reads it cheaply.
        // The cache marker sits on the message before the newest assistant turn: the part that will not change.
        $cached = $messages; $lastAssistant = max(array_keys(array_filter(array_column($cached, 'role'), fn ($r) => $r === 'assistant')));
        $cached[$lastAssistant - 1]['content'][count($cached[$lastAssistant - 1]['content']) - 1]['cache_control'] = ['type' => 'ephemeral'];
        $this->assertSame($cached, $sentBody['messages'], 'the history goes to the provider as the worker wrote it, cached to its settled part');
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
        $this->assertSame(22, $q->payload_json['execution_policy']['plan_media']['max_calls'], 'room for ad-hoc purchases');
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
        // A cutout starts from a file this run has, named first: here the file bought a moment ago.
        $name = $bought['file']['name'];
        $cutter = \Mockery::mock(\App\Services\Create\PlanMediaExecutor::class);
        $cutter->shouldReceive('produce')->once()->with('cutout', \Mockery::any(), \Mockery::any(), \Mockery::any())->andReturnUsing(function ($k, $d, $ctx, $dir) use ($name) {
            $this->assertContains($name, array_column($ctx['cutout_files'], 'name'));
            $this->assertStringStartsWith($name, $d);
            \Illuminate\Support\Facades\Process::run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=8x8', '-frames:v', '1', $dir.'/cut.png']);
            return ['path' => $dir.'/cut.png', 'mime' => 'image/png', 'title' => 'Cut out', 'provider_id' => 'cutout-1']; });
        $this->app->instance(\App\Services\Create\PlanMediaExecutor::class, $cutter);
        $cut = $service->produceAdHoc($run->id, $claim['lease_token'], 'cutout', $name.' the product on its own');
        $this->assertSame(['succeeded', \App\Services\Create\CapabilityCatalogue::CUTOUT_CREDITS], [$cut['status'], $cut['charged_credits']]);
        // A cutout that names nothing the run has, with no image bought before it, is refused before any provider is
        // called: a plain failure the build can correct, never a hold for reconciliation.
        $this->assertThrows(fn () => (new \App\Services\Create\PlanMediaExecutor)->produce('cutout', 'shop-owner-stock: remove the background', ['cutout_files' => [], 'cutout_latest' => null], sys_get_temp_dir()), \InvalidArgumentException::class);
        $refuser = \Mockery::mock(\App\Services\Create\PlanMediaExecutor::class);
        $refuser->shouldReceive('produce')->once()->andThrow(new \InvalidArgumentException('Name the image to cut out by its file name'));
        $this->app->instance(\App\Services\Create\PlanMediaExecutor::class, $refuser);
        $failed = $service->produceAdHoc($run->id, $claim['lease_token'], 'cutout', 'shop-owner-stock: remove the background');
        $this->assertSame(['failed', 0], [$failed['status'], $failed['charged_credits']]);
        $this->assertFalse(\App\Services\Create\AttemptService::unresolved($run->id), 'nothing left to reconcile');
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

    public function test_changed_source_bytes_are_requoted_and_redrawn_instead_of_reusing_the_old_identity(): void
    {
        $this->pilot(); config(['create.planner' => 'offline']);
        $c = $this->brief();
        $this->imageAttachment($c, 'source');
        $plans = app(\App\Services\Create\PlanService::class);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $p = $plans->propose($this->owner, $c->id, $version(), 'avatar-cache');
        $json = $p['plan'];
        $json['media'] = [['kind' => 'reference_sheet', 'description' => 'The user', 'subjects' => [['name' => 'User', 'kind' => 'character', 'looks' => 'the uploaded person']], 'credits' => 35]];
        $json['look_first'] = $json['selections']['look_first'] = true;
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($json)]);
        $calls = [];
        app()->instance(\App\Services\Create\PlanMediaExecutor::class, new class($calls) extends \App\Services\Create\PlanMediaExecutor {
            public function __construct(private array &$calls) {}
            public function produce(string $kind, string $description, array $ctx, string $dir): array {
                $bytes = file_get_contents($ctx['source_images'][0]);
                $this->calls[] = hash('sha256', $bytes);
                file_put_contents($dir.'/x.png', $bytes);
                return ['path' => $dir.'/x.png', 'mime' => 'image/png', 'title' => 'User', 'poses' => ['User'], 'provider_id' => 'offline-'.count($this->calls), 'character_contract' => \App\Services\Create\CharacterApproval::CONTRACT];
            }
        });
        $quote = $this->conversations->quote($this->owner, $c->id, $version(), 'storyboard');
        $run = $this->conversations->approve($this->owner, $c->id, $quote->id, 'avatar-first', true);
        $claim = $this->runs->claim();
        $media = app(\App\Services\Create\PlanMediaService::class);
        $first = $media->produce($run->id, $claim['lease_token'], 0);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'offline look done'], null, null);
        $this->assertNotNull($plans->present(DB::table('create_plans')->where('id', $p['id'])->first(), $this->conversations->conversation($this->owner, $c->id))['character_preview'], 'the approval candidate uses the same source fingerprint');
        $again = $this->conversations->quote($this->owner, $c->id, $version(), 'storyboard');
        $this->assertSame(0, $again->payload_json['plan_media'][0]['credits'], 'unchanged source reuses the paid sheet');
        $disk = \Illuminate\Support\Facades\Storage::disk('minio');
        $disk->put('sample.png', $disk->get('sample.png').'new source bytes');
        $changed = $this->conversations->quote($this->owner, $c->id, $version(), 'storyboard');
        $this->assertGreaterThan(0, $changed->payload_json['plan_media'][0]['credits'], 'changed source is quoted before buying');
        $run2 = $this->conversations->approve($this->owner, $c->id, $changed->id, 'avatar-second', true);
        $claim2 = $this->runs->claim();
        $second = $media->produce($run2->id, $claim2['lease_token'], 0);
        $this->assertFalse($second['reused']);
        $this->assertCount(2, $calls);
        $this->assertNotSame($first['file']['sha256'], $second['file']['sha256']);
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

    private function privateCreateStorage(): \App\Services\Create\CreateStorage
    {
        \Illuminate\Support\Facades\Storage::fake('create_private');
        config(['create.storage_disk' => 'create_private']);
        $privacy = \Mockery::mock(\App\Services\Create\PrivateBucketGuard::class);
        $privacy->shouldReceive('assertPrivate')->with('create_private');
        $this->app->instance(\App\Services\Create\PrivateBucketGuard::class, $privacy);
        $this->app->forgetInstance(\App\Services\Create\CreateStorage::class);
        return app(\App\Services\Create\CreateStorage::class);
    }

    public function test_private_bucket_upload_and_worker_input_survive_a_fresh_local_disk(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $storage = $this->privateCreateStorage();
        $c = $this->conversations->create($this->owner, []);
        $asset = app(\App\Services\Create\AttachmentUploadService::class)->upload($this->owner, $c->id, $this->uploadPng(), 'source', 'remote-upload', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Make a short product video', 'expected_version' => 1, 'idempotency_key' => 'remote-brief']);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $f = $q->payload_json['input_files'][0];
        $this->assertSame('create_private', DB::table('create_stored_files')->where('path', $f['storage_path'])->value('disk'));
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'remote-approve');
        $claim = $this->runs->claim();
        \Illuminate\Support\Facades\Storage::fake('local'); // Simulate a separate API host with no originals/cache.
        $this->assertSame($f['sha256'], hash('sha256', $storage->get($f['storage_path'])));
        config(['create.worker_token' => str_repeat('w', 64)]);
        $url = "/api/internal/create/runs/{$run->id}/inputs/{$asset->id}";
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => $claim['lease_token']])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => str_repeat('z', 64)])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_migrated_video_plays_in_production_with_ranges_and_expiring_signed_access(): void
    {
        [$c, $revision, $output] = $this->registeredOutput();
        $storage = $this->privateCreateStorage();
        $path = DB::table('composition_revisions')->where('id', $revision)->value('artifact_path');
        $storage->migrate($path);
        \Illuminate\Support\Facades\Storage::fake('local');
        $asset = Asset::findOrFail($output['asset_id']);
        $url = app(\App\Services\Media\StorageService::class)->url($asset->storage_url);
        $this->app->instance('env', 'production');
        $response = $this->get($url, ['Range' => 'bytes=0-6'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-6/29');
        $this->assertSame('offline', $response->streamedContent());
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
        $this->get(str_replace('/assets/'.$asset->id, '/assets/'.($asset->id + 1), $url))->assertForbidden();
        $preview = \Illuminate\Support\Facades\URL::temporarySignedRoute('media.create.version', now()->addMinutes(5), ['revisionId' => $revision]);
        $this->get($preview, ['Range' => 'bytes=0-6'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-6/29');
        $this->travel(11)->minutes();
        try { $this->get($url)->assertForbidden(); $this->get($preview)->assertForbidden(); }
        finally { $this->travelBack(); }
        $this->workspace->update(['status' => 'suspended']);
        $this->get($url)->assertNotFound();
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

    public function test_upload_http_needs_no_role_or_consent_and_image_briefs_cannot_render_video_fixture(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);$this->actingAs($this->owner);
        \Illuminate\Support\Facades\Storage::fake('local');
        $c=$this->postJson('/api/v1/create/conversations',['output_kind'=>'image'])->assertCreated()->json('data.id');
        // Attaching is one step: the role is read from the brief when it is sent.
        $this->post("/api/v1/create/conversations/$c/uploads",['asset_file'=>$this->uploadPng(),'idempotency_key'=>'a','expected_version'=>0],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.attachments.0.purpose','auto');
        $this->post("/api/v1/create/conversations/$c/uploads",['asset_file'=>$this->uploadPng('b.png'),'purpose'=>'source','idempotency_key'=>'b','expected_version'=>1],['Accept'=>'application/json'])->assertOk();
        $this->postJson("/api/v1/create/conversations/$c/messages",['content'=>'Make a product image','expected_version'=>2,'idempotency_key'=>'brief'])->assertCreated();
        $this->postJson("/api/v1/create/conversations/$c/quotes",['expected_version'=>3])->assertStatus(422);
        $this->assertSame(0,\App\Models\ApiQuote::count());Bus::assertNothingDispatched();
    }

    public function test_a_new_creation_asks_its_questions_one_at_a_time_before_planning(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'An ad for my shop.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"question": "Which product should it feature?"}']]])]);
        $plans = app(\App\Services\Create\PlanService::class);
        $out = $plans->propose($this->owner, $c->id, 1, 'p1');
        $this->assertSame(['needs_answer' => 'clarify', 'question' => 'Which product should it feature?'], $out);
        $this->assertSame(0, DB::table('create_plans')->count());
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id")->assertOk()->assertJsonPath('data.messages.1.kind', 'question');
        // The user can skip the questions; the plan is then made with the planner's best guess.
        config(['create.mode' => 'fixture']);
        $p = $plans->propose($this->owner, $c->id, 2, 'p2', true);
        $this->assertSame('proposed', $p['status']);
        // A plan never asks again on its own: the cap stops a fourth question.
        $this->assertNull(app(\App\Services\Create\Planning\Clarifier::class)->question([], \App\Services\Create\Planning\Clarifier::MAX_QUESTIONS));
    }

    public function test_a_similar_video_runs_as_long_as_its_reference_unless_the_user_chose_a_length(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        foreach ([[[], 30], [['duration_chosen' => true], 15]] as [$extra, $want]) {
            $ref = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'ref.mp4', 'storage_url' => 'create-upload://r', 'status' => 'active',
                'metadata_json' => ['reference_study' => ['duration_seconds' => 36.2]]]);
            $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'reference_match' => 'similar', ...$extra]);
            $this->conversations->attach($this->owner, $c->id, $ref->id, 'reference', 0);
            $this->conversations->message($this->owner, $c->id, ['content' => 'Something similar for WyvStudio.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
            $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'p1', true);
            $this->assertSame($want, json_decode($this->conversations->conversation($this->owner, $c->id)->settings_json, true)['duration_seconds'], '36 s reference, Create makes up to 30 s');
        }
    }

    public function test_material_only_the_user_has_is_asked_for_before_the_plan(): void
    {
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"question": "Do you have screens of the UGC feature and real videos made with WyvStudio? Attach them here, or reply go without."}']]])]);
        $ctx = ['messages' => [['role' => 'user', 'content' => 'Something similar to this for wyvstudio.com']], 'settings' => ['reference_match' => 'similar'],
            'brand_library' => [['role' => 'logo', 'title' => 'WyvStudio logo']],
            'files' => [['title' => 'ref.mp4', 'asset_type' => 'video', 'purpose' => 'reference', 'reference' => ['summary' => 'A presenter with cutaways', 'study' => ['moments' => [
                ['id' => '1735:m6', 'kind' => 'stat', 'visual' => 'GitHub star counter rolls to 62.3k'], ['id' => '1735:m21', 'kind' => 'ui', 'visual' => 'Gallery of finished videos']]]]]]];
        $q = app(\App\Services\Create\Planning\Clarifier::class)->question($ctx, 0);
        $this->assertStringContainsString('go without', $q);
        Http::assertSent(function ($r) {
            $prompt = $r['messages'][0]['content'];
            return str_contains($prompt, 'What the reference shows:') && str_contains($prompt, 'stat: GitHub star counter rolls to 62.3k')
                && str_contains($prompt, 'Brand library: logo: WyvStudio logo') && str_contains($prompt, 'real results or videos made with it');
        });
    }

    public function test_people_and_places_already_drawn_are_found_by_their_own_drawing_key(): void
    {
        $c = $this->brief();
        $row = ['conversation_id' => $c->id, 'plan_id' => (string) \Illuminate\Support\Str::uuid(), 'item_index' => 0, 'kind' => 'reference_sheet', 'description_hash' => str_repeat('a', 64), 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()];
        $columns = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('create_plan_media'));
        DB::table('create_plan_media')->insert(array_intersect_key($row + ['id' => (string) \Illuminate\Support\Str::uuid(), 'record_json' => json_encode(['file' => ['asset_id' => 1, 'sha256' => 'm'], 'more_files' => [['asset_id' => 2, 'sha256' => 's']], 'poses' => ['Maya', 'Shop'], 'subject_keys' => ['key-maya', 'key-shop']])], $columns));
        $prior = \App\Services\Create\Storyboard::priorSubjects($c->id);
        $this->assertSame(['key-maya' => ['asset_id' => 1, 'sha256' => 'm'], 'key-shop' => ['asset_id' => 2, 'sha256' => 's']], $prior, 'each drawing is kept under its own key, so changing Maya leaves the shop as it was');
    }

    public function test_the_reference_question_never_repeats_an_unclear_answer_or_a_skip(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $video = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'status' => 'ready', 'storage_url' => 'minio://ref.mp4', 'title' => 'their-ugc.mp4']);
        $this->conversations->attach($this->owner, $c->id, $video->id, 'reference', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Make one for my shop.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        $plans = app(\App\Services\Create\PlanService::class);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->assertSame('reference_match', $plans->propose($this->owner, $c->id, $version(), 'p1')['needs_answer']);
        // An answer that names none of the three choices is still an answer: the plan is made, not the question again.
        $this->conversations->message($this->owner, $c->id, ['content' => 'kinda close but punchier', 'expected_version' => $version(), 'idempotency_key' => 'b2']);
        $p = $plans->propose($this->owner, $c->id, $version(), 'p2');
        $this->assertSame('proposed', $p['status']);
        $this->assertSame('similar', json_decode((string) $this->conversations->conversation($this->owner, $c->id)->settings_json, true)['reference_match']);
        $this->assertSame(1, DB::table('create_messages')->where('conversation_id', $c->id)->where('content', \App\Services\Create\ReferenceMatch::QUESTION)->count());
    }

    public function test_a_file_whose_role_is_unclear_is_asked_about_and_the_reply_decides_it(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $video = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'status' => 'ready', 'storage_url' => 'minio://clip.mp4', 'title' => 'WhatsApp Video.mp4']);
        $this->conversations->attach($this->owner, $c->id, $video->id, 'auto', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'I want this 30 secs video for my site.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => '{"roles": {"'.$video->id.'": "unsure"}}']]])
            ->push(['content' => [['type' => 'text', 'text' => '{"question": null}']]])]);
        $plans = app(\App\Services\Create\PlanService::class);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $asked = $plans->propose($this->owner, $c->id, $version(), 'p1');
        $this->assertSame(['role', 'Should I put "WhatsApp Video.mp4" in your video, or make your video like it?'], [$asked['needs_answer'], $asked['question']]);
        $this->assertSame('auto', DB::table('create_attachments')->where('asset_id', $video->id)->value('purpose'), 'nothing is decided on a guess');
        // The reply decides that file (in the cards' words or the user's own).
        $this->conversations->message($this->owner, $c->id, ['content' => 'Make mine like it', 'expected_version' => $version(), 'idempotency_key' => 'b2']);
        config(['create.mode' => 'fixture']);
        $plans->propose($this->owner, $c->id, $version(), 'p2');
        $this->assertSame('reference', DB::table('create_attachments')->where('asset_id', $video->id)->value('purpose'));
        $this->assertSame(['source', 'reference', null], [\App\Services\Create\AttachmentRoles::fromAnswer('Use it in my video'), \App\Services\Create\AttachmentRoles::fromAnswer('make mine like it'), \App\Services\Create\AttachmentRoles::fromAnswer('hmm')]);
    }

    public function test_a_plan_that_made_its_video_is_frozen(): void
    {
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $p = $plans->propose($this->owner, $c->id, $version(), 'plan-freeze');
        $q = $this->conversations->quote($this->owner, $c->id, $version());
        $this->assertSame($p['id'], $q->payload_json['plan']['plan_id'] ?? null);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-freeze');
        DB::table('composition_runs')->where('id', $run->id)->update(['status' => 'preview_ready']);
        $this->assertTrue(\App\Services\Create\PlanService::built($p['id']));
        $this->assertTrue($plans->present(DB::table('create_plans')->where('id', $p['id'])->first(), $this->conversations->conversation($this->owner, $c->id))['built']);
        // No edits and no second build from it: a new version starts from a new plan.
        $this->rejected(409, fn () => $plans->select($this->owner, $c->id, $p['id'], $version(), ['callouts' => ['New line']]));
        $this->rejected(409, fn () => $this->conversations->quote($this->owner, $c->id, $version()));
    }

    public function test_the_brand_library_keeps_brand_visuals_and_a_plan_that_uses_one_attaches_it(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $mascot = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'minio://bear.png', 'title' => 'Wyv bear']);
        $this->actingAs($this->owner)->postJson('/api/v1/create/brand-library', ['asset_id' => $mascot->id, 'role' => 'mascot'])->assertCreated()->assertJsonPath('data.role', 'mascot');
        $this->actingAs($this->owner)->postJson('/api/v1/create/brand-library', ['asset_id' => $mascot->id, 'role' => 'spaceship'])->assertStatus(422);
        $this->actingAs($this->owner)->getJson('/api/v1/create/brand-library')->assertOk()->assertJsonPath('data.0.asset_id', $mascot->id);
        // The planner sees it and may use it without the user attaching it; the plan attaches what it uses.
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $ctx = (new \ReflectionMethod($plans, 'context'))->invoke($plans, $this->owner, $this->conversations->conversation($this->owner, $c->id));
        $this->assertSame([['asset_id' => $mascot->id, 'role' => 'mascot', 'title' => 'Wyv bear', 'asset_type' => 'image']], $ctx['brand_library']);
        $plan = $plans->normalize(['summary' => 'x', 'left_out' => '', 'reused' => [['asset_id' => $mascot->id, 'use' => 'Waves in the end card'], ['asset_id' => 999999, 'use' => 'not ours']],
            'asks' => [['what' => 'Your mascot in another pose', 'kind' => 'mascot', 'why' => 'For the hook', 'fallback' => 'The saved bear']]], $ctx, (int) $this->workspace->id);
        $this->assertSame([['asset_id' => $mascot->id, 'title' => 'Wyv bear', 'use' => 'Waves in the end card', 'from_brand' => 'mascot']], $plan['reused']);
        $this->assertSame('mascot', $plan['asks'][0]['kind']);
        \App\Services\Create\BrandLibrary::attachUsed($c->id, [$mascot->id], (string) now());
        $this->assertSame('source', DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $mascot->id)->value('purpose'));
        $this->actingAs($this->owner)->deleteJson("/api/v1/create/brand-library/{$mascot->id}")->assertOk();
        $this->assertSame([], \App\Services\Create\BrandLibrary::items((int) $this->workspace->id));
    }

    public function test_costs_are_estimated_per_stage_and_planning_is_half_price_capped(): void
    {
        $e = \App\Services\Create\CostEstimate::class;
        // Until enough real runs exist, the curve fitted to the measured runs: about 290 for 15 s, 800 for 30 s at Standard.
        $this->assertSame([289, 795, 116, 1511], [$e::agent('standard', 15), $e::agent('standard', 30), $e::agent('quick', 15), $e::agent('thorough', 30)]);
        $this->assertSame([1445, 3975, 1000], [$e::agentCeiling('standard', 15), $e::agentCeiling('standard', 30), $e::agentCeiling('quick', 15)], 'five times the estimate, never under 1,000');
        $this->assertSame([231, 520], $e::agentRange('standard', 15), 'without real runs, a spread around the curve');
        $this->assertSame([30, 75, 100, 145], [$e::planningCharge(60), $e::planningCharge(150), $e::planningCharge(200), $e::planningCharge(290)], 'half the real cost, with no cap');
        // Effort sets the build: Quick has no reviewer and 24 calls; every effort has a credit budget, not a call count.
        $quick = \App\Services\Create\PilotPolicy::class;
        config(['create.agent_provider' => 'anthropic', 'services.anthropic.key' => 'k', 'create.mode' => 'agent', 'create.paid_execution_enabled' => true, 'create.pilot_budget_id' => 'e3-test', 'create.pilot_budget_microusd' => 5000000]);
        $q = $quick::execution(['effort' => 'quick', 'duration_seconds' => 15, 'output_kind' => 'video']);
        $t = $quick::execution(['effort' => 'thorough', 'duration_seconds' => 30, 'output_kind' => 'video']);
        $this->assertSame([24, 'low', false, $e::agentCeiling('quick', 15)], [$q['agent']['max_calls'], $q['agent']['effort'], isset($q['critic']), $q['agent']['total_credits']]);
        $this->assertSame([150, 'high', 4], [$t['agent']['max_calls'], $t['agent']['effort'], $t['critic']['max_calls']]);
        $this->assertThrows(fn () => \App\Services\Create\OutputSettings::normalize(['effort' => 'extreme']), \Illuminate\Validation\ValidationException::class);

        // Planning: billed at half its real tokens' cost when the plan arrives; subsidized, never free.
        config(['create.planner' => 'anthropic', 'create.mode' => 'agent']);
        $reply = ['summary' => 'A kinetic launch video', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 15, 'idea' => 'Title lands']]];
        $plan = fn ($id) => ['id' => $id, 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'usage' => ['input_tokens' => 100000, 'output_tokens' => 2000]];
        // The question check before it (Sonnet: 2,000 in, 100 out) is part of planning too.
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => '{"question": null}']], 'usage' => ['input_tokens' => 2000, 'output_tokens' => 100]])->push($plan('one'))->push(['content' => [['type' => 'text', 'text' => '{"question": null}']]])->push($plan('two'))]);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A launch video for my desk.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $service = app(\App\Services\Create\PlanService::class);
        $first = $service->propose($this->owner, $c->id, 1, 'pc-1');
        // 100,000 input and 2,000 output tokens at the gateway's tariff: 110 credits; the question check 2 more; billed half.
        $this->assertSame(['cost_credits' => 112, 'parts' => ['planner' => 110, 'questions' => 2], 'charge' => 56, 'charged' => 56, 'waived' => false], $first['plan']['planning_charge']);
        $this->assertSame(44, $this->conversations->creditAvailability($this->owner)['available']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Bigger title.', 'expected_version' => (int) $this->conversations->conversation($this->owner, $c->id)->version, 'idempotency_key' => 'b2']);
        // 44 left is below a typical planning charge (60): asked to top up before anything is spent.
        $this->rejected(402, fn () => $service->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'pc-2'));
        $this->workspace->update(['credits_monthly' => (int) $this->workspace->credits_monthly + 20]);
        $second = $service->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'pc-2');
        $this->assertFalse($second['plan']['planning_charge']['waived']);
        $this->assertSame(min($second['plan']['planning_charge']['charge'], 64), $second['plan']['planning_charge']['charged'], 'the whole charge, or what is left; never free');
        $this->assertGreaterThanOrEqual(0, $this->conversations->creditAvailability($this->owner)['available']);
    }

    public function test_testing_without_limits_still_shows_a_real_never_more_than(): void
    {
        $this->pilot();
        config(['create.agent_provider' => 'anthropic', 'services.anthropic.key' => 'k', 'create.unlimited' => true]);
        $c = $this->brief();
        $q = $this->conversations->quote($this->owner, $c->id, 1);
        $this->assertGreaterThan(100000, $q->credits_max, 'unlimited testing holds far more');
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $shown = $this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/quotes", ['expected_version' => 1])->assertOk()->json('data');
        $this->assertSame(\App\Services\Create\CostEstimate::agentCeiling('standard', 15), $shown['ceiling'], 'what a real hold would be');
        $this->assertLessThan($shown['credits_max'], $shown['ceiling']);
    }

    public function test_a_reference_study_is_billed_with_the_first_plan_that_uses_it_and_never_again(): void
    {
        $costs = \App\Services\Create\PlanningCosts::class;
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'ref.mp4', 'storage_url' => 'create-upload://r', 'status' => 'active',
            'metadata_json' => ['reference_study' => ['cost_microusd' => 440000, 'sound' => ['cost_microusd' => 1100], 'layout' => null]]]);
        $costs::begin('conv-a');
        foreach (['reference_study', 'reference_study.sound', 'reference_study.layout'] as $part) $costs::once($asset, $part, 'cost_microusd', 'reference');
        $this->assertSame(['reference' => 441100], $costs::take('conv-a'));
        $costs::begin('conv-b');
        $costs::once($asset->refresh(), 'reference_study', 'cost_microusd', 'reference');
        $this->assertSame([], $costs::take('conv-b'), 'a study already billed is not billed again');
        $this->assertSame([], $costs::take('conv-a'), 'taking clears it');
    }

    public function test_the_build_estimate_learns_from_runs_of_the_same_type_and_kind_of_request(): void
    {
        $e = \App\Services\Create\CostEstimate::class;
        $c = $this->brief();
        $seed = function (string $type, string $task, int $credits) use ($c) {
            $planId = (string) \Illuminate\Support\Str::uuid();
            DB::table('create_plans')->insert(['id' => $planId, 'conversation_id' => $c->id, 'message_id' => (string) \Illuminate\Support\Str::uuid(), 'brief_sequence' => 1, 'idempotency_key' => 'k'.$planId,
                'request_hash' => str_repeat('a', 64), 'provider' => 'test', 'plan_json' => json_encode(['video_type' => $type, 'planner_task' => $task]), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
            $runId = (string) \Illuminate\Support\Str::uuid();
            DB::table('composition_runs')->insert(['id' => $runId, 'conversation_id' => $c->id, 'workspace_id' => $this->workspace->id, 'quote_id' => substr(md5($runId), 0, 32), 'idempotency_key' => 'r'.$runId, 'request_hash' => str_repeat('b', 64),
                'input_json' => json_encode(['build_stage' => 'full_video', 'settings' => ['duration_seconds' => 15], 'plan' => ['plan_id' => $planId]]), 'status' => 'preview_ready', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('composition_attempts')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'run_id' => $runId, 'operation_id' => substr(md5($runId), 0, 32), 'attempt_key' => 'a1', 'request_hash' => str_repeat('c', 64),
                'kind' => 'agent', 'provider' => 'anthropic', 'model' => 'm', 'status' => 'succeeded', 'credit_limit' => 1000, 'cost_limit_microusd' => 1, 'charged_credits' => $credits, 'created_at' => now(), 'updated_at' => now()]);
        };
        foreach ([100, 120, 140] as $cr) $seed('footage_motion', 'edit', $cr);
        foreach ([400, 500, 600] as $cr) $seed('motion_graphics', 'creative', $cr);
        $seed('motion_graphics', 'edit', 200);
        $this->assertSame(120, $e::agent('standard', 15, ['type' => 'footage_motion', 'task' => 'edit']));
        $this->assertSame(500, $e::agent('standard', 15, ['type' => 'motion_graphics', 'task' => 'creative']));
        $this->assertSame([400, 600], $e::agentRange('standard', 15, ['type' => 'motion_graphics', 'task' => 'creative']), 'the middle half of real builds');
        $this->assertSame(500, $e::agent('standard', 15, ['type' => 'motion_graphics', 'task' => 'edit']), 'two of a kind is too few: the same type decides');
        $this->assertSame(200, $e::agent('standard', 15, ['type' => 'ugc_motion']), 'an unseen type: every similar run');
        $this->assertSame(795, $e::agent('standard', 30, ['type' => 'motion_graphics']), 'no runs of a similar length: the curve');
    }

    public function test_a_step_starts_on_what_is_available_when_it_covers_the_likely_cost_and_pauses_at_it(): void
    {
        $this->pilot();
        config(['create.agent_provider' => 'anthropic', 'services.anthropic.key' => 'k']);
        $c = $this->brief();
        $q = $this->conversations->quote($this->owner, $c->id, 1);
        $budget = $q->payload_json['execution_policy']['agent']['total_credits'];
        $this->assertSame(\App\Services\Create\CostEstimate::agentCeiling('standard', 15), $budget);
        $this->assertGreaterThan(0, $q->payload_json['estimate']);
        // Less than the likely cost: refused, saying what it usually takes.
        $this->workspace->update(['credits_monthly' => (int) $q->payload_json['estimate'] - 10]);
        $this->rejected(402, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'limited-low', true));
        // The likely cost but not one call's ceiling on top: the build would stop part-way, so it is refused too.
        $min = \App\Services\Create\ConversationService::MIN_CALL_CREDITS;
        $this->workspace->update(['credits_monthly' => (int) $q->payload_json['estimate'] + $min - 10]);
        $this->rejected(402, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'limited-short', true));
        // More than the likely cost but less than the ceiling: it starts, with what is available as its ceiling, and
        // a per-call ceiling small enough that the likely cost fits under it.
        $available = (int) $q->payload_json['estimate'] + $min + 40;
        $this->workspace->update(['credits_monthly' => $available]);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'limited-ok', true);
        $input = json_decode($run->input_json, true);
        $this->assertTrue($input['credit_limited']);
        $this->assertSame($available, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('authorized_credits'));
        $agent = $input['execution_policy']['agent'];
        $this->assertGreaterThanOrEqual($min, $agent['credits']);
        $this->assertLessThan(300, $agent['credits']);
        $this->assertSame($agent['credits'] * 4000, $agent['cost_limit_microusd']);
        $this->assertGreaterThanOrEqual((int) $q->payload_json['estimate'] - (int) ($q->payload_json['media_estimate'] ?? 0), $agent['total_credits'] - $agent['credits'], 'the likely cost fits under room less one ceiling');
        // Reaching it pauses the step: the run says so, and Retry continues it.
        $claim = $this->runs->claim();
        DB::table('composition_trace_events')->insert(['run_id' => $run->id, 'sequence' => 1, 'event_json' => json_encode(['phase' => 'run', 'status' => 'failed', 'detail' => 'Model budget exhausted']), 'created_at' => now()]);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'This build stopped before it finished.'], null, null);
        $this->assertSame(\App\Services\Create\RunService::OUT_OF_CREDITS, DB::table('composition_runs')->where('id', $run->id)->value('error'));
    }

    public function test_a_retry_of_a_build_started_on_a_short_balance_starts_from_the_full_limits(): void
    {
        $this->pilot();
        config(['create.agent_provider' => 'anthropic', 'services.anthropic.key' => 'k']);
        $c = $this->brief();
        $q = $this->conversations->quote($this->owner, $c->id, 1);
        $full = $q->payload_json['execution_policy']['agent'];
        $this->workspace->update(['credits_monthly' => (int) $q->payload_json['estimate'] + \App\Services\Create\ConversationService::MIN_CALL_CREDITS + 40]);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'short-1', true);
        $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'This build stopped before it finished.'], null, null);
        // The retry asks for the full limits again, not the shrunk ones the failed run kept...
        $retry = app(\App\Services\Create\VariantService::class)->retryQuote($this->owner, $c->id, $run->id, (int) $this->conversations->conversation($this->owner, $c->id)->version);
        $agent = $retry->payload_json['execution_policy']['agent'];
        $this->assertSame($full['credits'], $agent['credits']);
        $this->assertSame($full['total_credits'], $agent['total_credits']);
        $this->assertArrayNotHasKey('credit_limited', $retry->payload_json);
        // ...and approving fits them to what is available now.
        $again = json_decode($this->conversations->approve($this->owner, $c->id, $retry->id, 'short-2', true)->input_json, true);
        $this->assertTrue($again['credit_limited']);
        $this->assertLessThan($full['credits'], $again['execution_policy']['agent']['credits']);
    }

    public function test_builds_run_at_once_up_to_the_limit_and_one_workspace_never_takes_every_slot(): void
    {
        $c = $this->brief();
        $other = Workspace::create(['name' => 'Other', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active']);
        config(['create.workspaces' => []]);
        $queue = function (int $workspace, int $age) use ($c) {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('composition_runs')->insert(['id' => $id, 'conversation_id' => $c->id, 'workspace_id' => $workspace, 'quote_id' => substr(md5($id), 0, 32), 'idempotency_key' => 'q'.$id,
                'request_hash' => str_repeat('a', 64), 'input_json' => json_encode(['mode' => 'fixture']), 'status' => 'queued', 'created_at' => now()->subMinutes($age), 'updated_at' => now()]);
            return $id;
        };
        $a1 = $queue($this->workspace->id, 30); $a2 = $queue($this->workspace->id, 20); $b1 = $queue($other->id, 10);
        // One at a time (the default): the oldest runs, nothing else starts beside it.
        $this->assertSame($a1, $this->runs->claim()['id']);
        $this->assertNull($this->runs->claim());
        // Two at once, one per workspace: the other workspace's build goes next, not the first workspace's second.
        config(['create.max_running' => 2, 'create.max_running_per_workspace' => 1]);
        $this->assertSame($b1, $this->runs->claim()['id']);
        $this->assertNull($this->runs->claim(), 'both slots are taken');
        // A build whose worker has not confirmed it stopped still holds its slot.
        DB::table('composition_runs')->where('id', $b1)->update(['status' => 'needs_attention', 'lease_hash' => null, 'worker_stopped_at' => null]);
        $this->assertNull($this->runs->claim());
        DB::table('composition_runs')->where('id', $b1)->update(['worker_stopped_at' => now(), 'status' => 'failed']);
        $this->assertNull($this->runs->claim(), 'a slot is free, but the first workspace is at its own limit');
        config(['create.max_running_per_workspace' => 2]);
        $this->assertSame($a2, $this->runs->claim()['id']);
    }

    public function test_create_health_alerts_the_team_once_an_hour_per_problem_and_notes_recovery(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        config(['create.enabled' => true, 'create.mode' => 'agent', 'create.admin_alert_emails' => ['ops@example.test']]);
        $health = app(\App\Services\Create\CreateHealth::class);
        $c = $this->brief();
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('composition_runs')->insert(['id' => $id, 'conversation_id' => $c->id, 'workspace_id' => $this->workspace->id, 'quote_id' => substr(md5($id), 0, 32), 'idempotency_key' => 'h'.$id,
            'request_hash' => str_repeat('a', 64), 'input_json' => json_encode(['mode' => 'fixture']), 'status' => 'queued', 'created_at' => now()->subMinutes(30), 'updated_at' => now()]);
        // No worker has checked in, and a build has waited 30 minutes: both reported, with the run's ID.
        $now = $health->check();
        $this->assertSame(['queue', 'worker'], array_values(array_intersect(['queue', 'worker'], array_keys($now))));
        $this->assertStringContainsString(substr($id, 0, 8), $now['queue']['text']);
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class, fn ($m) => str_contains($m->title, 'waiting too long'));
        $sent = count(\Illuminate\Support\Facades\Mail::queued(\App\Mail\VendorAlertMail::class));
        // Still wrong five minutes later: no second email within the hour.
        $health->check();
        $this->assertSame($sent, count(\Illuminate\Support\Facades\Mail::queued(\App\Mail\VendorAlertMail::class)));
        // A worker checks in and the build is claimed: both clear, each with a recovery note.
        \App\Services\Create\CreateHealth::workerSeen();
        DB::table('composition_runs')->where('id', $id)->update(['status' => 'failed']);
        $this->assertSame([], array_values(array_intersect(['queue', 'worker'], array_keys($health->check()))));
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class, fn ($m) => $m->title === 'Recovered: Create queue is healthy again');
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class, fn ($m) => $m->title === 'Recovered: Create worker is healthy again');
        // A worker busy building (no claims, only heartbeats) is not reported missing (production 2026-10-07: three
        // slots building at once set off a false alarm).
        \Illuminate\Support\Facades\Cache::forget('create:worker-seen');
        config(['create.mode' => 'fixture']); [, , $busyRun] = $this->admitted(); $busyClaim = $this->runs->claim(); config(['create.mode' => 'agent']);
        \Illuminate\Support\Facades\Cache::forget('create:worker-seen');
        $this->runs->heartbeat($busyRun->id, $busyClaim['lease_token'], 1, 'Building');
        $this->assertArrayNotHasKey('worker', $health->problems());
        DB::table('composition_runs')->where('id', $busyRun->id)->update(['status' => 'failed']);
    }

    public function test_another_workspace_cannot_reach_create_conversations_videos_files_or_worker_inputs(): void
    {
        [$c, , $run] = $this->admitted();
        $revision = (string) \Illuminate\Support\Str::uuid();
        DB::table('composition_revisions')->insert(['id' => $revision, 'conversation_id' => $c->id, 'run_id' => $run->id, 'number' => 1, 'parent_revision_id' => null,
            'bundle_json' => '{}', 'bundle_hash' => str_repeat('c', 64), 'artifact_path' => 'create/previews/'.$run->id.'/x.mp4', 'artifact_hash' => str_repeat('d', 64), 'summary' => 'v1', 'created_at' => now()]);
        $mine = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready']);
        $other = Workspace::create(['name' => 'Other', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        // The same person, now working in another workspace: everything of the first workspace is out of reach.
        $intruder = $this->owner; $intruder->forceFill(['workspace_id' => $other->id])->save();
        config(['create.workspaces' => []]);
        $theirs = Asset::create(['workspace_id' => $other->id, 'asset_type' => 'image', 'status' => 'ready']);
        // Through the app: the other workspace's conversation, plan activity, planning and video are not found.
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $base = '/api/v1/create/conversations/';
        $this->actingAs($intruder)->getJson($base.$c->id)->assertNotFound();
        $this->actingAs($intruder)->getJson($base.$c->id.'/plan-activity')->assertNotFound();
        $this->actingAs($intruder)->postJson($base.$c->id.'/plans', ['expected_version' => 1, 'idempotency_key' => 'x'])->assertNotFound();
        $this->actingAs($intruder)->getJson($base.$c->id.'/revisions/'.$revision.'/artifact')->assertNotFound();
        // Not through their own conversation either, nor by attaching our file to it.
        $own = $this->conversations->create($intruder, ['duration_seconds' => 15]);
        $this->actingAs($intruder)->getJson($base.$own->id.'/revisions/'.$revision.'/artifact')->assertNotFound();
        $this->actingAs($intruder)->postJson($base.$own->id.'/attachments', ['asset_id' => $mine->id, 'purpose' => 'source', 'expected_version' => 0])->assertNotFound();
        // The worker of our run can fetch only files listed on that run and owned by its workspace.
        config(['create.worker_token' => str_repeat('a', 64)]);
        $claim = $this->runs->claim();
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/runs/'.$run->id.'/inputs/'.$theirs->id, ['lease_token' => $claim['lease_token']])->assertNotFound();
        $this->withToken(str_repeat('b', 64))->postJson('/api/internal/create/runs/'.$run->id.'/inputs/'.$mine->id, ['lease_token' => $claim['lease_token']])->assertForbidden();
    }

    public function test_a_from_scratch_plan_carries_a_concept_a_playbook_and_a_motion_voice_to_the_build(): void
    {
        config(['create.planner' => 'anthropic', 'create.mode' => 'agent', 'services.anthropic.key' => 'k']);
        $sent = [];
        $reply = ['summary' => 'A kinetic launch teaser', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 15, 'idea' => 'The promise lands']],
            'concept' => ['name' => 'One word becomes it', 'idea' => 'The word "shoot" crosses out and becomes the product', 'hook' => 'A giant SHOOT, struck through', 'look' => 'Black, orange, bold type',
                'structure' => 'One object transforms through every feature', 'opening' => 'A giant word', 'ending' => 'Logo lockup', 'why' => 'Most specific to the brand',
                'alternatives' => [['name' => 'Countdown', 'idea' => 'A countdown to the reveal'], ['name' => 'Problem flash', 'idea' => 'Three bad shoots, then the answer'], ['name' => 'Extra', 'idea' => 'A third other way']]],
            'playbook' => 'launch_promo', 'motion_voice' => ['id' => 'bold_playful', 'why' => 'A startup launch']];
        Http::fake(['api.anthropic.com/*' => function ($request) use (&$sent, $reply) {
            $sent[] = $request->data();
            $planning = str_contains(json_encode($request->data()['system'] ?? ''), 'creative planner');
            return Http::response(['id' => 'm'.count($sent), 'content' => [['type' => 'text', 'text' => $planning ? json_encode($reply) : '{"question": null}']], 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
        }]);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A launch teaser for WyvStudio Create.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $p = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, 1, 'scratch-1')['plan'];
        // The planner was given the playbooks and motion voices.
        $planner = collect($sent)->first(fn ($d) => str_contains(json_encode($d['system'] ?? ''), 'creative planner'));
        $this->assertStringContainsString('format_playbooks', json_encode($planner['messages']));
        $this->assertStringContainsString('launch_promo', json_encode($planner['messages']));
        // The plan keeps the concept with two alternatives, the full playbook and the voice's timings.
        $this->assertSame('The word "shoot" crosses out and becomes the product', $p['concept']['idea']);
        $this->assertCount(3, $p['concept']['alternatives'], 'up to four other directions are kept (the drawer shows five)');
        $this->assertSame('launch_promo', $p['playbook']['id']);
        $this->assertNotEmpty($p['playbook']['beats']);
        $this->assertSame('back.out(1.8)', $p['motion_voice']['ease']);
        // Unknown ids are dropped; a plan with a reference keeps no concept.
        $this->assertSame([], \App\Services\Create\FormatPlaybooks::normalize(['playbook' => 'made_up', 'motion_voice' => ['id' => 'loud']], true));
        $this->assertArrayNotHasKey('concept', \App\Services\Create\FormatPlaybooks::normalize($reply, false));
        // The build gets the guide: beats with energy and holds, the concept and the motion voice.
        $guide = \App\Services\Create\FormatPlaybooks::guide($p);
        foreach (['# Format playbook: Launch or motion promo', 'energy high', '# Concept (approved)', 'back.out(1.8)'] as $part) $this->assertStringContainsString($part, $guide);
    }

    public function test_a_file_whose_use_is_open_is_asked_about_with_suggested_answers_and_every_file_is_placed_or_noted(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $logo = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'minio://logo.png', 'title' => 'logo.png', 'mime_type' => 'image/png']);
        $this->conversations->attach($this->owner, $c->id, $logo->id, 'auto', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'An ad for my bakery, Crumb & Co. Here is my logo.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['files' => [(string) $logo->id => ['role' => 'source', 'kind' => 'logo', 'use' => '', 'time' => null, 'ask' => 'Where should your logo go?', 'options' => ['Opening', 'End card', 'Both']]]])]]])
            ->push(['content' => [['type' => 'text', 'text' => '{"question": null}']]])]);
        $plans = app(\App\Services\Create\PlanService::class);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $asked = $plans->propose($this->owner, $c->id, $version(), 'p1');
        $this->assertSame(['file', 'Where should your logo go?'], [$asked['needs_answer'], $asked['question']]);
        $this->assertSame('source', DB::table('create_attachments')->where('asset_id', $logo->id)->value('purpose'));
        $this->assertSame('logo', json_decode(DB::table('create_attachments')->where('asset_id', $logo->id)->value('notes_json'), true)['kind']);
        // The page gets the suggested answers with the question.
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $last = collect($this->actingAs($this->owner)->getJson('/api/v1/create/conversations/'.$c->id)->assertOk()->json('data.messages'))->last();
        $this->assertSame(['question', ['Opening', 'End card', 'Both']], [$last['kind'], $last['options']]);
        // The answer is a message the planner reads; the question is not asked again.
        $this->conversations->message($this->owner, $c->id, ['content' => 'End card', 'expected_version' => $version(), 'idempotency_key' => 'b2']);
        config(['create.mode' => 'fixture']);
        $p = $plans->propose($this->owner, $c->id, $version(), 'p2');
        $this->assertArrayHasKey('plan', $p);
        // A file the plan did not place is listed, so nothing the user gave disappears silently.
        $this->assertContains('logo.png', array_merge($p['plan']['unplaced'] ?? [], array_column($p['plan']['reused'] ?? [], 'title')));
    }

    public function test_a_screenshot_of_the_current_video_is_read_as_that_moment(): void
    {
        $c = $this->brief();
        // A current version with a real (tiny) video, so its frames can be shown next to the screenshot.
        $tmp = tempnam(sys_get_temp_dir(), 'cur').'.mp4';
        $made = \Illuminate\Support\Facades\Process::run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=orange:s=90x160:d=3', '-pix_fmt', 'yuv420p', $tmp]);
        if (! $made->successful()) $this->markTestSkipped('ffmpeg is not available here');
        $path = 'create/previews/test/current.mp4';
        app(\App\Services\Create\CreateStorage::class)->put($path, file_get_contents($tmp));
        $rev = (string) \Illuminate\Support\Str::uuid();
        DB::table('composition_revisions')->insert(['id' => $rev, 'conversation_id' => $c->id, 'run_id' => (string) \Illuminate\Support\Str::uuid(), 'number' => 1, 'parent_revision_id' => null,
            'bundle_json' => '{}', 'bundle_hash' => str_repeat('c', 64), 'artifact_path' => $path, 'artifact_hash' => str_repeat('d', 64), 'summary' => 'v1', 'created_at' => now()]);
        DB::table('create_conversations')->where('id', $c->id)->update(['head_revision_id' => $rev]);
        $shot = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'minio://shot.png', 'title' => 'IMG_2231.png', 'mime_type' => 'image/png']);
        $v = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $this->conversations->attach($this->owner, $c->id, $shot->id, 'auto', $v);
        $this->conversations->message($this->owner, $c->id, ['content' => 'make this text bigger', 'expected_version' => $v + 1, 'idempotency_key' => 'shot-1']);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'test-key']);
        $sent = [];
        Http::fake(['api.anthropic.com/*' => function ($r) use (&$sent, $shot) { $sent[] = $r->data();
            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['files' => [(string) $shot->id => ['role' => 'current', 'kind' => 'screenshot', 'use' => 'make the text bigger', 'time' => 1.5]]])]]]); }]);
        $c = $this->conversations->conversation($this->owner, $c->id);
        $roles = app(\App\Services\Create\AttachmentRoles::class)->resolve($this->owner, $c);
        $this->assertSame('current', $roles['roles'][$shot->id]);
        $this->assertSame(1.5, json_decode(DB::table('create_attachments')->where('asset_id', $shot->id)->value('notes_json'), true)['time']);
        $this->assertStringContainsString("CURRENT video", json_encode($sent[0]['messages']), 'the current video was shown beside the screenshot');
        // Without a current video, "current" is not an option and such a reply is not taken.
        $c2 = $this->brief(); $other = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'minio://x.png', 'title' => 'x.png', 'mime_type' => 'image/png']);
        $this->conversations->attach($this->owner, $c2->id, $other->id, 'auto', (int) $this->conversations->conversation($this->owner, $c2->id)->version);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['files' => [(string) $other->id => ['role' => 'current', 'time' => 2]]])]]])]);
        $r2 = app(\App\Services\Create\AttachmentRoles::class)->resolve($this->owner, $this->conversations->conversation($this->owner, $c2->id));
        $this->assertNotSame('current', $r2['roles'][$other->id] ?? null);
    }

    public function test_a_from_scratch_plan_keeps_five_directions_each_with_the_users_files_and_more_ways_adds_three(): void
    {
        $d = fn ($n, $f = 'offer_ad') => ['name' => $n, 'idea' => $n.' idea', 'hook' => 'opens', 'look' => 'warm', 'swatches' => ['#f2e8d8', '#3b2a20', 'red', '#d9682b'], 'format' => $f, 'uses' => 'Your logo on the end card', 'structure' => 's'];
        $concept = \App\Services\Create\FormatPlaybooks::concept($d('Fourteen Days Fresh') + ['why' => 'shows it', 'alternatives' => [$d('Doorstep Drop', 'brand_story'), $d('Offer First'), $d('Diary', 'testimonial'), $d('Flavour Map', 'explainer'), $d('Sixth')]], true)['concept'];
        $this->assertCount(4, $concept['alternatives'], 'the planned direction and four others: five in the drawer');
        $this->assertSame(['#f2e8d8', '#3b2a20', '#d9682b'], $concept['swatches'], 'only real colours');
        $this->assertSame('Your logo on the end card', $concept['alternatives'][0]['uses']);
        $this->assertSame('brand_story', $concept['alternatives'][0]['format']);
        $this->assertArrayNotHasKey('format', \App\Services\Create\FormatPlaybooks::direction(['name' => 'x', 'idea' => 'y', 'format' => 'made_up']));
        $this->assertTrue(\App\Services\Create\FormatPlaybooks::concept($d('Given') + ['given' => true], true)['concept']['given']);
        // More ways: three new directions join the plan, a repeat of one shown is dropped, and it is billed like planning.
        $c = $this->brief();
        $planId = (string) \Illuminate\Support\Str::uuid();
        DB::table('create_plans')->insert(['id' => $planId, 'conversation_id' => $c->id, 'message_id' => (string) \Illuminate\Support\Str::uuid(), 'brief_sequence' => 1, 'idempotency_key' => 'k'.$planId,
            'request_hash' => str_repeat('a', 64), 'provider' => 'test', 'plan_json' => json_encode(['summary' => 's', 'concept' => $concept]), 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.planner_model' => 'claude-sonnet-5']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['directions' => [$d('Doorstep Drop'), $d('Night Shift', 'brand_story'), $d('Map Room', 'explainer'), $d('Pour Over', 'product_demo')]])]], 'usage' => ['input_tokens' => 20000, 'output_tokens' => 1500]])
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['directions' => [$d('Brew Clock'), $d('Map Room')]])]], 'usage' => ['input_tokens' => 20000, 'output_tokens' => 900]])]);
        $before = $this->conversations->creditAvailability($this->owner)['available'];
        $out = app(\App\Services\Create\DirectionService::class)->more($this->owner, $c->id, $planId);
        $this->assertSame(['Night Shift', 'Map Room'], array_column($out['directions'], 'name'), 'a name already shown is dropped; at most three are taken');
        $this->assertGreaterThan(0, $out['charged']);
        $this->assertSame($before - $out['charged'], $this->conversations->creditAvailability($this->owner)['available']);
        $this->assertCount(2, json_decode(DB::table('create_plans')->where('id', $planId)->value('plan_json'), true)['concept']['more']);
        // The page asks through its route.
        // The page asks through its route; only new names are taken.
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner)->postJson('/api/v1/create/conversations/'.$c->id.'/plans/'.$planId.'/directions')->assertOk()->assertJsonPath('data.directions.0.name', 'Brew Clock');
    }

    public function test_the_users_own_voice_is_the_narration_and_their_track_the_music_so_neither_is_bought(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $said = ['sha256' => 'x', 'text' => "Hi, I'm Ada. I bake sourdough every morning.", 'words' => [], 'segments' => []];
        $voice = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'audio', 'status' => 'ready', 'storage_url' => 'minio://me.mp3', 'title' => 'me.mp3', 'mime_type' => 'audio/mpeg', 'duration_seconds' => 9, 'transcript_text' => $said['text'], 'metadata_json' => ['create_transcript' => $said]]);
        $song = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'audio', 'status' => 'ready', 'storage_url' => 'minio://song.mp3', 'title' => 'song.mp3', 'mime_type' => 'audio/mpeg', 'duration_seconds' => 120, 'metadata_json' => ['create_transcript' => ['sha256' => 'y', 'text' => '', 'words' => [], 'segments' => []]]]);
        $this->conversations->attach($this->owner, $c->id, $voice->id, 'auto', 0);
        $this->conversations->attach($this->owner, $c->id, $song->id, 'auto', 1);
        $this->conversations->message($this->owner, $c->id, ['content' => 'An ad for my bakery with my voice and my song.', 'expected_version' => 2, 'idempotency_key' => 'b1']);
        config(['create.mode' => 'agent', 'create.planner' => 'anthropic', 'services.anthropic.key' => 'k']);
        $sent = [];
        $plan = ['summary' => 'A warm bakery ad', 'narration' => ["Hi, I'm Ada.", 'I bake sourdough every morning.'], 'voice' => 'Kore',
            'scenes' => [['label' => 'Ada', 'start' => 0, 'end' => 15, 'idea' => 'Bread']], 'media' => [['kind' => 'voiceover', 'description' => 'x'], ['kind' => 'music', 'description' => 'warm']]];
        Http::fake(['api.anthropic.com/*' => function ($r) use (&$sent, $plan, $voice, $song) {
            $sent[] = $r->data(); $body = json_encode($r->data());
            if (str_contains($body, 'creative planner')) $text = json_encode($plan);
            elseif (str_contains($body, 'decide its role')) $text = json_encode(['files' => [(string) $voice->id => ['role' => 'source', 'kind' => 'voice', 'use' => 'narration'], (string) $song->id => ['role' => 'source', 'kind' => 'music', 'use' => 'background music']]]);
            else $text = '{"question": null}';
            return Http::response(['id' => 'm'.count($sent), 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]); }]);
        $p = app(\App\Services\Create\PlanService::class)->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'p1', true)['plan'];
        // The reading heard the recording's words, and none in the track.
        $reading = collect($sent)->first(fn ($d) => str_contains(json_encode($d), 'decide its role'));
        $this->assertStringContainsString("heard: \\\"Hi, I'm Ada", json_encode($reading));
        $this->assertStringContainsString('no words heard', json_encode($reading));
        // A transcriber inventing a line over a 24 s instrumental (production 2026-10-07) is not someone speaking.
        $this->assertStringStartsWith(', almost no words heard', \App\Services\Create\AttachmentRoles::heardNote('Guruji, I bow to you.', 24));
        $this->assertStringStartsWith(', heard:', \App\Services\Create\AttachmentRoles::heardNote("Hi, I'm Ada. I bake sourdough every morning.", 9));
        $this->assertSame(['voice', 'music'], [json_decode(DB::table('create_attachments')->where('asset_id', $voice->id)->value('notes_json'), true)['kind'], json_decode(DB::table('create_attachments')->where('asset_id', $song->id)->value('notes_json'), true)['kind']]);
        // Their recording says the script and their track is the bed: nothing is bought for either.
        $kinds = array_column($p['media'], 'kind');
        $this->assertNotContains('voiceover', $kinds);
        $this->assertNotContains('music', $kinds);
        $this->assertSame(["Hi, I'm Ada.", 'I bake sourdough every morning.'], $p['narration']);
    }

    public function test_settings_read_from_the_brief_are_marked_so_details_can_say_so(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Make a square 20 second video in French.', 'expected_version' => 0, 'idempotency_key' => 'fb-1']);
        $s = json_decode($this->conversations->conversation($this->owner, $c->id)->settings_json, true);
        $this->assertEqualsCanonicalizing(['aspect_ratio', 'duration_seconds', 'language'], $s['from_brief']);
        // Details saves the list back without the values the user changed there; an unknown key is refused.
        $s2 = \App\Services\Create\OutputSettings::normalize(array_merge(array_diff_key($s, ['from_brief' => 1]), ['aspect_ratio' => '9:16', 'from_brief' => ['duration_seconds', 'language']]));
        $this->assertSame(['duration_seconds', 'language'], $s2['from_brief']);
        $this->assertThrows(fn () => \App\Services\Create\OutputSettings::normalize(['from_brief' => ['style_pack']]), \Illuminate\Validation\ValidationException::class);
    }

    public function test_details_changes_make_the_plan_out_of_date_and_the_format_locks_once_its_pictures_exist(): void
    {
        $c = $this->brief();
        $plans = app(\App\Services\Create\PlanService::class);
        $version = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $p = $plans->propose($this->owner, $c->id, $version(), 'lock-1');
        $row = fn () => DB::table('create_plans')->where('id', $p['id'])->first();
        $this->assertFalse($plans->stale($row(), $this->conversations->conversation($this->owner, $c->id)));
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $patch = fn ($settings) => $this->actingAs($this->owner)->patchJson('/api/v1/create/conversations/'.$c->id, ['expected_version' => $version(), 'settings' => $settings]);
        // Frame rate only changes the render: the plan stands.
        $patch(['frame_rate' => 30])->assertOk();
        $this->assertFalse($plans->stale($row(), $this->conversations->conversation($this->owner, $c->id)));
        // Length is what the plan was timed for: it is out of date.
        $patch(['duration_seconds' => 20])->assertOk();
        $this->assertTrue($plans->stale($row(), $this->conversations->conversation($this->owner, $c->id)));
        // Pictures made for the current plan fix its format.
        $fresh = $plans->propose($this->owner, $c->id, $version(), 'lock-2');
        DB::table('create_plan_media')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => $c->id, 'plan_id' => $fresh['id'], 'item_index' => 0, 'kind' => 'reference_sheet', 'description_hash' => str_repeat('a', 64), 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner)->getJson('/api/v1/create/conversations/'.$c->id)->assertOk()->assertJsonPath('data.settings_locks.format', true);
        $patch(['aspect_ratio' => '16:9'])->assertStatus(409);
        $patch(['duration_seconds' => 15])->assertOk();
    }

    public function test_only_thorough_builds_hold_credits_for_the_reviewer(): void
    {
        config(['create.agent_provider' => 'anthropic', 'services.anthropic.key' => 'k', 'create.mode' => 'agent', 'create.paid_execution_enabled' => true, 'create.pilot_budget_id' => 'e3-test', 'create.pilot_budget_microusd' => 5000000]);
        $policy = fn ($effort) => \App\Services\Create\PilotPolicy::execution(['effort' => $effort, 'duration_seconds' => 15, 'output_kind' => 'video']);
        $this->assertArrayNotHasKey('critic', $policy('quick'));
        $this->assertArrayNotHasKey('critic', $policy('standard'), 'Standard is never reviewed, so it holds nothing for a reviewer');
        $this->assertSame(4, $policy('thorough')['critic']['max_calls']);
    }

    public function test_a_busy_image_model_is_tried_again_but_a_refused_request_is_not(): void
    {
        $busy = fn ($e) => \App\Services\Create\PlanMediaExecutor::busy($e);
        $this->assertTrue($busy('nano-banana failed: Prediction failed: Async prediction failed: ModelRateLimitError: Service is currently unavailable due to high demand.'));
        $this->assertTrue($busy('HTTP 429 Too Many Requests'));
        $this->assertFalse($busy('The input was flagged as sensitive (E005)'));
        $this->assertFalse($busy(''));
    }

    public function test_plan_colours_can_be_changed_and_are_kept_fixed(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Launch video for my desk.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $plans = app(\App\Services\Create\PlanService::class);
        $p = $plans->propose($this->owner, $c->id, (int) $this->conversations->conversation($this->owner, $c->id)->version, 'plan-1');
        $plan = $p['plan']; $plan['colour_treatment'] = ['source' => 'agent', 'source_note' => '', 'roles' => ['accent' => ['hex' => '#FF6B35', 'locked' => false]], 'usage' => ''];
        DB::table('create_plans')->where('id', $p['id'])->update(['plan_json' => json_encode($plan)]);
        $v = (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $out = $plans->select($this->owner, $c->id, $p['id'], $v, ['colours' => ['accent' => '#3355ff']]);
        $this->assertSame(['hex' => '#3355FF', 'locked' => true], $out['plan']['colour_treatment']['roles']['accent']);
        $this->assertSame('user', $out['plan']['colour_treatment']['source']);
        $this->rejected(422, fn () => $plans->select($this->owner, $c->id, $p['id'], $v + 1, ['colours' => ['background' => '#000000']]));
    }

    public function test_attachment_roles_are_settled_from_the_brief_and_kept_on_reattach(): void
    {
        $c = $this->brief();
        $image = $this->imageAttachment($c, 'auto');
        $video = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'status' => 'ready', 'storage_url' => 'minio://clip.mp4', 'title' => 'their-ugc.mp4']);
        $this->conversations->attach($this->owner, $c->id, $video->id, 'auto', 2);
        // No model in fixture mode: a video follows as a reference, an image is used in the video.
        $roles = app(\App\Services\Create\AttachmentRoles::class)->resolve($this->owner, $this->conversations->conversation($this->owner, $c->id));
        $this->assertSame(['roles' => [$image->id => 'source', $video->id => 'reference'], 'unsure' => []], $roles);
        $this->assertSame('source', DB::table('create_attachments')->where('asset_id', $image->id)->value('purpose'));
        // Settled roles are not asked again, and attaching the same file without a role keeps its role.
        $this->assertSame(['roles' => [], 'unsure' => []], app(\App\Services\Create\AttachmentRoles::class)->resolve($this->owner, $this->conversations->conversation($this->owner, $c->id)));
        $this->conversations->attach($this->owner, $c->id, $image->id, 'auto', 3);
        $this->assertSame('source', DB::table('create_attachments')->where('asset_id', $image->id)->value('purpose'));
        // An image brief uses a video too: nothing is studied, it is edited from.
        $this->assertSame('source', \App\Services\Create\AttachmentRoles::fallback('video', 'image'));
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
        // A workspace list narrows it; an empty list allows any workspace (the people gate still decides).
        config(['create.enabled' => true, 'create.workspaces' => [999]]); $this->rejected(404, fn () => $this->brief());
        $this->assertSame(0, DB::table('create_conversations')->count());
        config(['create.workspaces' => []]); $this->brief();
        $this->assertSame(1, DB::table('create_conversations')->count());
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
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Stopped at your request. This is the last version that passed every check.', 'bundle' => ['index.html' => '<html></html>']], 'create/previews/test/v1.mp4', 'h');
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
        \Illuminate\Support\Sleep::fake();
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
        // A drop is waited out for about a minute before giving up as not sent.
        \Illuminate\Support\Sleep::assertSequence(array_map(fn ($w) => \Illuminate\Support\Sleep::for($w)->seconds(), [2, ...\App\Services\Create\NetRetry::WAITS]));
        $row=DB::table('composition_attempts')->where('id',$b['id'])->first();
        $this->assertSame(['failed',0],[$row->status,(int)$row->charged_credits],'never sent, never charged, nothing held');
        $this->assertFalse(\App\Services\Create\AttemptService::unresolved($run->id));
    }

    public function test_our_model_account_running_dry_alerts_the_team_once_holds_new_work_and_says_so(): void
    {
        [, , $run]=$this->admitted(); $claim=$this->runs->claim(); $attempts=app(\App\Services\Create\AttemptService::class);
        $input=json_decode($run->input_json,true);$input['mode']='agent';
        $input['execution_policy']['agent']=['provider'=>'anthropic','model'=>'claude-opus-5-5','credits'=>75,'cost_limit_microusd'=>300000,'max_calls'=>3];
        DB::table('composition_runs')->where('id',$run->id)->update(['input_json'=>json_encode($input)]);
        DB::table('api_operations')->where('id',$run->operation_id)->update(['authorized_credits'=>225,'reserved_credits'=>225]);
        $this->workspace->update(['credits_monthly'=>1000]);
        $this->owner->update(['role'=>'super_admin']);
        config(['create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>'test-pilot','create.pilot_budget_microusd'=>5000000,'services.anthropic.key'=>'test-key','create.admin_alert_emails'=>['ops@example.com']]);
        \Illuminate\Support\Facades\Mail::fake();
        $call=['prompt'=>'p','system'=>'s','max_tokens'=>1024,'image'=>null];
        $hash=hash('sha256',json_encode(['prompt'=>'p','system'=>'s','maxTokens'=>1024,'image'=>null],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $gateway=app(\App\Services\Create\AnthropicGateway::class);
        // Anthropic's real reply when the account has no credit (a 400 that used to be retried as "busy").
        $dry=['type'=>'error','error'=>['type'=>'invalid_request_error','message'=>'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.']];
        Http::fake(['https://api.anthropic.com/*'=>Http::sequence()->push($dry,400,['request-id'=>'req_dry1'])->push($dry,400,['request-id'=>'req_dry2'])
            ->push(['id'=>'msg_back','content'=>[['type'=>'text','text'=>'ok']],'usage'=>['input_tokens'=>100,'output_tokens'=>10]])]);
        foreach (['agent-1','agent-2'] as $key) {
            $a=$attempts->begin($run->id,$claim['lease_token'],$key,'agent',$hash);
            try { $gateway->complete($run->id,$claim['lease_token'],$a['id'],$call); $this->fail('refused'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(503,$e->getStatusCode());
                $this->assertStringStartsWith('[vendor:vendor_credit] Our AI model is temporarily unavailable on our side. The team has been notified',$e->getMessage());
            }
            $this->assertSame(['failed',0],[DB::table('composition_attempts')->where('id',$a['id'])->value('status'),(int)DB::table('composition_attempts')->where('id',$a['id'])->value('charged_credits')]);
        }
        // One alert for both failures, to the super admins and the alert list, saying where to fix it.
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class,2);
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class,fn($m)=>$m->hasTo('ops@example.com')&&$m->vendor==='anthropic'&&$m->kind==='vendor_credit'&&$m->extra['fix']==='https://console.anthropic.com/settings/billing'&&in_array($run->id,$m->extra['runs'],true));
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class,fn($m)=>$m->hasTo(mb_strtolower($this->owner->email)));
        $this->assertSame(2,DB::table('vendor_incidents')->where('vendor','anthropic')->where('kind','vendor_credit')->count());
        // New work that needs the model waits while it is held.
        $this->assertNotNull(\App\Services\Vendors\VendorAlerts::down('anthropic'));
        $this->assertThrows(fn()=>\App\Services\Vendors\VendorAlerts::assertUp(['anthropic']),\Symfony\Component\HttpKernel\Exception\HttpException::class);
        // The first call that works ends the hold and tells the team.
        $c=$attempts->begin($run->id,$claim['lease_token'],'agent-3','agent',$hash);
        $this->assertSame('ok',$gateway->complete($run->id,$claim['lease_token'],$c['id'],$call)['text']);
        $this->assertNull(\App\Services\Vendors\VendorAlerts::down('anthropic'));
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class,fn($m)=>$m->kind==='recovered');
        // The day's summary lists it.
        $this->artisan('create:vendor-digest',['--print'=>true])->expectsOutputToContain('anthropic · vendor_credit')->assertSuccessful();
    }

    public function test_a_busy_model_does_not_leave_the_reference_half_studied_and_a_failed_study_is_never_followed_blind(): void
    {
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k']);
        \Illuminate\Support\Sleep::fake();
        $study = app(\App\Services\Create\References\ReferenceStudy::class);
        $ask = new \ReflectionMethod($study, 'ask');
        // Busy twice, then it reads: waited out, not given up.
        // One fake for the whole test: each request takes the next queued reply.
        $queue = [[['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529], [['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529],
            [['content' => [['type' => 'text', 'text' => '{"moments": []}']], 'usage' => ['input_tokens' => 10]], 200],
            [['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']], 400]];
        Http::fake(function () use (&$queue) { [$body, $status] = array_shift($queue) ?? [['content' => [['type' => 'text', 'text' => '{}']]], 200]; return Http::response($body, $status); });
        $this->assertSame(['moments' => []], $ask->invoke($study, 'claude-opus-5-5', [], false)[0]);
        $this->assertNull($study->lastFailure);
        \Illuminate\Support\Sleep::assertSequence([\Illuminate\Support\Sleep::for(10)->seconds(), \Illuminate\Support\Sleep::for(30)->seconds()]);
        // Our account out of credit: not retried, and said.
        $this->assertNull($ask->invoke($study, 'claude-opus-5-5', [], false));
        $this->assertSame('vendor_credit', $study->lastFailure);
        \Illuminate\Support\Facades\Cache::flush();

        // Planning a similar video from a reference whose reading failed: the user is asked first.
        $ref = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'ref.mp4', 'storage_url' => 'create-upload://r', 'status' => 'active',
            'metadata_json' => ['reference_study' => ['duration_seconds' => 12, 'shots' => [[0, 6], [6, 12]], 'moments' => [], 'moments_status' => 'failed', 'moments_failure' => 'busy']]]);
        $fake = \Mockery::mock(\App\Services\Create\References\ReferenceStudy::class);
        $fake->shouldReceive('forAsset')->andReturn($ref->metadata_json['reference_study']);
        $this->app->instance(\App\Services\Create\References\ReferenceStudy::class, $fake);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'reference_match' => 'similar', 'duration_chosen' => true]);
        $this->conversations->attach($this->owner, $c->id, $ref->id, 'reference', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Something like this for my shop.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        $plans = app(\App\Services\Create\PlanService::class);
        $v = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $asked = $plans->propose($this->owner, $c->id, $v(), 'p1');
        $this->assertSame('study', $asked['needs_answer']);
        $this->assertStringStartsWith('I couldn\'t study "ref.mp4" properly just now: the model was busy.', $asked['question']);
        $this->assertSame(0, DB::table('create_plans')->where('conversation_id', $c->id)->count(), 'nothing is planned blind');
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->assertSame('question', collect($this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id")->assertOk()->json('data.messages'))->last()['kind']);
        // "Plan from a quick look": the plan is made, and says so.
        $this->conversations->message($this->owner, $c->id, ['content' => 'Go ahead with a quick look', 'expected_version' => $v(), 'idempotency_key' => 'b2']);
        config(['create.planner' => 'anthropic']);
        $reply = ['summary' => 'A similar shop video', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 15, 'idea' => 'Title lands']]];
        $queue = [[['content' => [['type' => 'text', 'text' => '{"question": null}']]], 200], [['id' => 'p', 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]], 200]];
        $plan = $plans->propose($this->owner, $c->id, $v(), 'p2');
        $this->assertSame('proposed', $plan['status']);
        $this->assertContains('Planned from a quick look at "ref.mp4": its full study did not finish (the model was busy).', array_column($plan['plan']['direction_notes'] ?? [], 'text'));
    }

    public function test_only_the_team_and_named_accounts_can_see_or_use_create(): void
    {
        config(['create.allowed_domains' => ['wyvstudio.com'], 'create.allowed_emails' => ['kolakachi@gmail.com']]);
        $who = fn ($email) => \App\Services\Create\ConversationService::personAllowed(new User(['email' => $email]));
        $this->assertSame([true, true, true, false, false, false], [$who('kola@wyvstudio.com'), $who('Kolakachi@Gmail.com'), $who('ops@WyvStudio.com'),
            $who('someone@gmail.com'), $who('kola@wyvstudio.com.evil.io'), $who('')]);
        $this->owner->update(['email' => 'customer@gmail.com']);
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner->fresh())->getJson('/api/v1/create/capabilities')->assertNotFound();
        $this->owner->update(['email' => 'team@wyvstudio.com']);
        $this->actingAs($this->owner->fresh())->getJson('/api/v1/create/capabilities')->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_material_the_reference_shows_is_asked_for_by_rule_once_and_go_without_plans(): void
    {
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.planner' => 'anthropic']);
        $study = ['duration_seconds' => 20, 'moments_status' => 'ok', 'moments' => [['id' => 'm1', 'kind' => 'ui'], ['id' => 'm2', 'kind' => 'stat'], ['id' => 'm3', 'kind' => 'text']]];
        $ref = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'video', 'title' => 'ref.mp4', 'storage_url' => 'create-upload://r', 'status' => 'active', 'metadata_json' => ['reference_study' => $study]]);
        $fake = \Mockery::mock(\App\Services\Create\References\ReferenceStudy::class);
        $fake->shouldReceive('forAsset')->andReturn($study);
        $this->app->instance(\App\Services\Create\References\ReferenceStudy::class, $fake);
        $c = $this->conversations->create($this->owner, ['reference_match' => 'similar', 'duration_chosen' => true]);
        $this->conversations->attach($this->owner, $c->id, $ref->id, 'reference', 0);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Something like this for my app.', 'expected_version' => 1, 'idempotency_key' => 'b1']);
        $v = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $plans = app(\App\Services\Create\PlanService::class);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"question": null}']]])]);
        $asked = $plans->propose($this->owner, $c->id, $v(), 'm1');
        $this->assertSame('Before I plan: your reference shows screens or a screen recording of the product and real numbers (users, ratings or results). Do you have yours? Attach them here, or reply "go without" and I\'ll use illustrative versions.', $asked['question']);
        Http::assertNothingSent();
        $this->conversations->message($this->owner, $c->id, ['content' => 'Go without', 'expected_version' => $v(), 'idempotency_key' => 'b2']);
        $reply = ['summary' => 'A similar app video', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 15, 'idea' => 'Title lands']]];
        Http::fake(fn ($r) => Http::response(! isset($r['system']) ? ['content' => [['type' => 'text', 'text' => '{"question": null}']]]
            : ['id' => 'p', 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]));
        try { $plans->propose($this->owner, $c->id, $v(), 'm2'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException) { /* the stand-in planner's reply is not the point */ }
        $this->assertSame(1, DB::table('create_messages')->where('conversation_id', $c->id)->where('idempotency_key', 'like', 'materials:%')->count(), 'asked once');
        Http::assertSent(fn ($r) => ($r['model'] ?? '') === \App\Services\Create\Planning\Clarifier::MODEL);
        // The user's own screens attached: nothing to ask.
        $mine = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'title' => 'screen.png', 'storage_url' => 'create-upload://s', 'status' => 'active']);
        $c2 = $this->conversations->create($this->owner, ['reference_match' => 'similar']);
        $this->conversations->attach($this->owner, $c2->id, $ref->id, 'reference', 0);
        $this->conversations->attach($this->owner, $c2->id, $mine->id, 'source', 1);
        $this->assertNull(\App\Services\Create\PlanService::materialsWanted($this->conversations->conversation($this->owner, $c2->id)));
    }

    public function test_a_vague_change_gets_one_question_and_the_plan_says_what_it_assumed(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A launch video for my desk.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $plans = app(\App\Services\Create\PlanService::class);
        $v = fn () => (int) $this->conversations->conversation($this->owner, $c->id)->version;
        $plans->propose($this->owner, $c->id, $v(), 'p1', true);
        $this->conversations->message($this->owner, $c->id, ['content' => 'make it better', 'expected_version' => $v(), 'idempotency_key' => 'b2']);
        config(['create.mode' => 'agent', 'services.anthropic.key' => 'k', 'create.planner' => 'anthropic']);
        $reply = ['summary' => 'A sharper launch video', 'assumptions' => ['Free trial, no price shown', ''], 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 15, 'idea' => 'Title lands']]];
        Http::fake(fn ($r) => Http::response(! isset($r['system']) ? ['content' => [['type' => 'text', 'text' => '{"question": "Which part should change: the hook, the colours, the voice, or the pacing?"}']]]
            : ['id' => 'p', 'content' => [['type' => 'text', 'text' => json_encode($reply)]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]));
        $asked = $plans->propose($this->owner, $c->id, $v(), 'p2');
        $this->assertSame('Which part should change: the hook, the colours, the voice, or the pacing?', $asked['question']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'the hook', 'expected_version' => $v(), 'idempotency_key' => 'b3']);
        $plan = $plans->propose($this->owner, $c->id, $v(), 'p3');
        $this->assertSame('proposed', $plan['status'], 'the answer plans; it is not asked again');
        $this->assertSame(['Free trial, no price shown'], $plan['plan']['assumptions']);
    }

    private function workerIdentity(): array
    {
        return ['worker_id' => 'create-test-host', 'instance_id' => (string) \Illuminate\Support\Str::uuid(), 'slot' => 'render-1'];
    }

    public function test_worker_ownership_is_required_after_rollout_and_claim_is_bound_to_an_instance(): void
    {
        [, , $run] = $this->admitted();
        config(['create.worker_ownership_required' => true, 'create.worker_token' => str_repeat('a', 64)]);
        $identity = $this->workerIdentity();
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/claim', [])->assertStatus(422);
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/claim', ['worker_id' => 'missing-instance'])->assertStatus(422);
        $this->assertSame('queued', DB::table('composition_runs')->value('status'));
        $claim = $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/claim', $identity)->assertOk()->json('data');
        $this->assertSame($run->id, $claim['id']);
        $this->assertSame($identity['instance_id'], $claim['assignment']['instance_id']);
        $this->assertArrayNotHasKey('lease_fingerprint', $claim['assignment']);
        $this->assertNull($this->runs->claim($this->workerIdentity()), 'New process identity cannot take another active execution');
        $this->travel(10)->seconds();
        $this->runs->heartbeat($run->id, $claim['lease_token'], 1, 'Working');
        $owner = app(\App\Services\Create\WorkerOwnership::class)->find($run->id);
        $this->assertEquals(now()->toDateTimeString(), $owner->last_seen_at);
        $this->assertSame($claim['assignment']['id'], $owner->id);
    }

    public function test_operator_stop_is_assignment_bound_revokes_callbacks_and_preserves_uncertain_billing(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $ownership = app(\App\Services\Create\WorkerOwnership::class);
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $claim['lease_token'], 'render', 'render', str_repeat('a', 64));
        // Nonzero accounting fixture makes an unintended reservation release observable.
        DB::table('api_operations')->where('id', $run->operation_id)->update(['reserved_credits' => 42, 'spent_credits' => 7]);
        $before = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $assignment = $claim['assignment']['id'];
        $this->rejected(409, fn () => $ownership->confirmStopped($run->id, $assignment, 'incident-123: host checked'));
        $this->travel(100)->seconds(); $this->runs->expireLeases();
        $this->rejected(409, fn () => $ownership->confirmStopped($run->id, (string) \Illuminate\Support\Str::uuid(), 'incident-123: host checked'));
        $this->rejected(422, fn () => $ownership->confirmStopped($run->id, $assignment, ''));
        $this->rejected(409, fn () => app(\App\Services\Create\ReconciliationService::class)->closeSettled($run->id, true));
        $this->assertNull(DB::table('composition_runs')->where('id', $run->id)->value('worker_stopped_at'));
        $this->assertNull($this->runs->claim($this->workerIdentity()));
        $proof = $ownership->confirmStopped($run->id, $assignment, 'incident-123: original process and both containers stopped');
        $this->assertFalse($proof['replayed']);
        $this->assertTrue($ownership->confirmStopped($run->id, $assignment, 'incident-123: repeated acknowledgement')['replayed']);
        $owner = $ownership->find($run->id);
        $this->assertSame('operator', $owner->stop_source);
        $this->assertSame('incident-123: original process and both containers stopped', $owner->stop_evidence);
        $after = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $this->assertEquals($before->reserved_credits, $after->reserved_credits);
        $this->assertEquals($before->spent_credits, $after->spent_credits);
        $this->assertSame(0, (int) $after->capacity_slots);
        $this->assertSame('started', DB::table('composition_attempts')->where('id', $a['id'])->value('status'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, $claim['lease_token'], 2, 'Late'));
        $this->rejected(403, fn () => $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Late'], null, null));
        // Recorded stop frees execution capacity, never the uncertain credit hold.
        [, , $next] = $this->admitted();
        $this->assertSame($next->id, $this->runs->claim($this->workerIdentity())['id']);
    }

    public function test_worker_stop_acknowledgement_is_durable_and_safe_to_repeat_after_lost_response(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $claim['lease_token'], 'render', 'render', str_repeat('a', 64));
        $attempts->settle($run->id, $claim['lease_token'], $a['id'], ['status' => 'unknown']);
        $first = $this->runs->workerStopped($run->id, $claim['lease_token']);
        $again = $this->runs->workerStopped($run->id, $claim['lease_token']);
        $this->assertTrue($first['hold_retained']); $this->assertTrue($again['hold_retained']); $this->assertTrue($again['replayed']);
        $owner = app(\App\Services\Create\WorkerOwnership::class)->find($run->id);
        $this->assertSame('worker', $owner->stop_source); $this->assertNotNull($owner->stopped_at);
        $this->rejected(403, fn () => $this->runs->workerStopped($run->id, str_repeat('x', 64)));
        $this->assertSame('unknown', DB::table('composition_attempts')->where('id', $a['id'])->value('status'));
    }

    public function test_assigned_reconciliation_cannot_bypass_stop_record_with_a_boolean(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $a = app(\App\Services\Create\AttemptService::class)->begin($run->id, $claim['lease_token'], 'render', 'render', str_repeat('a', 64));
        $this->travel(100)->seconds(); $this->runs->expireLeases();
        $service = app(\App\Services\Create\ReconciliationService::class);
        $receipt = new \App\Services\Create\VerifiedAttemptReceipt($a['id'], 'failed', null, 0, 'Offline test: no render ran');
        $this->rejected(409, fn () => $service->reconcile($receipt, true));
        $this->rejected(409, fn () => $service->releaseUnstarted($run->id, true));
        $this->rejected(409, fn () => $service->closeSettled($run->id, true));
        app(\App\Services\Create\WorkerOwnership::class)->confirmStopped($run->id, $claim['assignment']['id'], 'incident-456: host and containers verified stopped');
        $service->reconcile($receipt, true);
        $this->assertSame('failed', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->assertTrue($service->reconcile($receipt, true)['replayed']);
        $this->assertSame(1, DB::table('composition_reconciliations')->count());
        Http::assertNothingSent();
    }

    public function test_worker_recovery_inspection_is_read_only_and_trajectory_has_no_lease_secret(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $ownership = app(\App\Services\Create\WorkerOwnership::class);
        $before = DB::table('composition_runs')->where('id', $run->id)->first();
        $this->artisan('create:worker-recovery', ['run' => $run->id])->assertSuccessful();
        $this->artisan('create:worker-recovery', ['run' => $run->id, '--worker-stopped' => true])->assertFailed();
        $this->artisan('create:worker-recovery', ['run' => $run->id, '--confirm-stopped' => $claim['assignment']['id']])->assertFailed();
        $this->assertEquals($before, DB::table('composition_runs')->where('id', $run->id)->first());
        $text = json_encode($ownership->inspect($run->id));
        $this->assertStringNotContainsString($claim['lease_token'], $text);
        $this->assertStringNotContainsString(hash('sha256', $claim['lease_token']), $text);
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $url = '/api/v1/admin/create-trajectories/'.$c->id;
        $this->actingAs($this->owner)->getJson($url)->assertForbidden();
        $this->owner->forceFill(['role' => 'super_admin'])->save();
        $response = $this->actingAs($this->owner)->getJson($url)->assertOk();
        $response->assertJsonPath('data.runs.0.worker_assignment.id', $claim['assignment']['id']);
        $this->assertStringNotContainsString(hash('sha256', $claim['lease_token']), $response->getContent());
        $this->travel(100)->seconds(); $this->runs->expireLeases();
        $this->artisan('create:worker-recovery', ['run' => $run->id, '--confirm-stopped' => $claim['assignment']['id'], '--worker-stopped' => true, '--evidence' => 'incident-789: original process and containers stopped'])->assertSuccessful();
        $this->assertSame('operator', $ownership->find($run->id)->stop_source);
    }

    public function test_stop_confirmation_rejects_changed_lease_and_missing_ownership_schema_fails_closed(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $this->travel(100)->seconds(); $this->runs->expireLeases();
        DB::table('composition_runs')->where('id', $run->id)->update(['lease_hash' => hash('sha256', 'changed')]);
        $ownership = app(\App\Services\Create\WorkerOwnership::class);
        $this->rejected(409, fn () => $ownership->confirmStopped($run->id, $claim['assignment']['id'], 'incident-123: incorrect old lease'));
        $this->assertNull($ownership->find($run->id)->stopped_at);
        \Illuminate\Support\Facades\Schema::drop('create_worker_assignments');
        $this->rejected(503, fn () => $this->runs->claim($this->workerIdentity()));
    }

    public function test_recorded_stop_can_close_only_fully_settled_work_without_buying_anything(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $this->travel(100)->seconds(); $this->runs->expireLeases();
        $ownership = app(\App\Services\Create\WorkerOwnership::class);
        $ownership->confirmStopped($run->id, $claim['assignment']['id'], 'incident-closed: coordinator and containers stopped');
        $this->artisan('create:worker-recovery', ['run' => $run->id, '--close-settled' => true])->assertSuccessful();
        $this->assertSame('failed', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->assertSame(0, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        $again = $this->runs->workerStopped($run->id, $claim['lease_token']);
        $this->assertTrue($again['replayed']); $this->assertFalse($again['hold_retained']);
        [, , $next] = $this->admitted(); $lease = $this->runs->claim($this->workerIdentity());
        $a = app(\App\Services\Create\AttemptService::class)->begin($next->id, $lease['lease_token'], 'render', 'render', str_repeat('a', 64));
        $this->runs->workerStopped($next->id, $lease['lease_token']);
        $this->rejected(409, fn () => \Illuminate\Support\Facades\Artisan::call('create:worker-recovery', ['run' => $next->id, '--close-settled' => true]));
        $this->assertSame('started', DB::table('composition_attempts')->where('id', $a['id'])->value('status'));
        Http::assertNothingSent();
    }

    public function test_failed_finished_recovery_rolls_back_temporary_lease_and_requires_assignment_stop(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim($this->workerIdentity());
        $attempts = app(\App\Services\Create\AttemptService::class);
        $attempt = $attempts->begin($run->id, $claim['lease_token'], 'render', 'render', str_repeat('a', 64));
        $attempts->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'failed', 'cost_microusd' => 0]);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Saving failed'], null, null);
        $args = ['run' => $run->id, 'completion' => '/not-read-before-stop-check', 'artifact' => '/not-read-before-stop-check'];
        $this->rejected(409, fn () => \Illuminate\Support\Facades\Artisan::call('create:recover-finished', $args));
        app(\App\Services\Create\WorkerOwnership::class)->confirmStopped($run->id, $claim['assignment']['id'], 'incident-save: original execution has stopped');
        $receiptHash = DB::table('composition_attempts')->where('id', $attempt['id'])->value('result_hash');
        DB::table('composition_attempts')->where('id', $attempt['id'])->update(['result_hash' => null]);
        $this->rejected(409, fn () => \Illuminate\Support\Facades\Artisan::call('create:recover-finished', $args));
        DB::table('composition_attempts')->where('id', $attempt['id'])->update(['result_hash' => $receiptHash]);
        $dir = sys_get_temp_dir().'/recover-atomic-'.\Illuminate\Support\Str::uuid(); mkdir($dir);
        try {
            (new \Symfony\Component\Process\Process(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=108x192:d=15', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $dir.'/video.mp4']))->mustRun();
            file_put_contents($dir.'/completion.json', json_encode(['result' => ['status' => 'preview_ready', 'summary' => 'Saved local result', 'bundle' => ['index.html' => '<html>Saved</html>']]]));
            $args = ['run' => $run->id, 'completion' => $dir.'/completion.json', 'artifact' => $dir.'/video.mp4'];
            $beforeRun = DB::table('composition_runs')->where('id', $run->id)->first();
            $beforeOp = DB::table('api_operations')->where('id', $run->operation_id)->first();
            $mock = \Mockery::mock(RunService::class);
            $mock->shouldReceive('finish')->once()->andThrow(new \RuntimeException('Injected delivery failure'));
            $this->app->instance(RunService::class, $mock);
            try {
                \Illuminate\Support\Facades\Artisan::call('create:recover-finished', $args);
                $this->fail('Expected the injected recovery failure');
            } catch (\RuntimeException $e) { $this->assertSame('Injected delivery failure', $e->getMessage()); }
            finally { $this->app->instance(RunService::class, $this->runs); }
            $this->assertEquals($beforeRun, DB::table('composition_runs')->where('id', $run->id)->first());
            $this->assertEquals($beforeOp, DB::table('api_operations')->where('id', $run->operation_id)->first());
            $this->assertSame(0, DB::table('composition_revisions')->where('run_id', $run->id)->count());
            $this->artisan('create:recover-finished', $args)->assertSuccessful();
            $this->assertSame('preview_ready', DB::table('composition_runs')->where('id', $run->id)->value('status'));
            $this->assertNull(DB::table('composition_runs')->where('id', $run->id)->value('lease_hash'));
            Http::assertNothingSent();
        } finally { foreach (glob($dir.'/*') ?: [] as $file) unlink($file); rmdir($dir); }
    }

    private function drainControl(): \App\Services\Create\AdmissionControl
    {
        config(['create.runtime_controls_enabled' => true]);
        return app(\App\Services\Create\AdmissionControl::class);
    }

    public function test_drain_preserves_existing_approvals_and_blocks_new_holds_and_claims(): void
    {
        [$c, $q, $run] = $this->admitted();
        $other = $this->brief(); $quote = $this->conversations->quote($this->owner, $other->id, 1);
        $control = $this->drainControl(); $control->setPaused(true, 'Test deployment');
        $before = DB::table('api_operations')->count();
        $this->assertSame($run->id, $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-'.$c->id)->id);
        $this->rejected(503, fn () => $this->conversations->approve($this->owner, $other->id, $quote->id, 'new-approval'));
        $this->assertSame($before, DB::table('api_operations')->count());
        $this->assertNull($this->runs->claim());
        $this->assertSame('queued', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        // Reading and saving the brief still work during a drain.
        $this->conversations->message($this->owner, $other->id, ['content' => 'Use a blue background.', 'expected_version' => 1, 'idempotency_key' => 'saved-during-drain']);
        $this->assertSame(2, (int) $this->conversations->conversation($this->owner, $other->id)->version);
        $control->setPaused(false, null);
        $this->assertSame($run->id, $this->runs->claim()['id']);
    }

    public function test_drain_keeps_worker_auth_heartbeat_attempt_settlement_and_delivery_available(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $this->drainControl()->setPaused(true, 'Test');
        config(['create.worker_token' => str_repeat('a', 64)]);
        $url = '/api/internal/create/runs/'.$run->id;
        $body = ['lease_token' => $claim['lease_token']];
        $this->withToken(str_repeat('x', 64))->postJson($url.'/heartbeat', $body + ['sequence' => 1, 'stage' => 'Working'])->assertForbidden();
        $this->withToken(str_repeat('a', 64))->postJson($url.'/heartbeat', $body + ['sequence' => 1, 'stage' => 'Finishing during drain'])->assertOk();
        $attempt = app(\App\Services\Create\AttemptService::class)->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('b', 64));
        $this->withToken(str_repeat('a', 64))->postJson($url.'/attempts/'.$attempt['id'].'/settle', $body + ['status' => 'succeeded', 'cost_microusd' => 0])->assertOk();
        $result = json_encode(['status' => 'failed', 'summary' => 'Offline fixture finished its work.']);
        $this->withToken(str_repeat('a', 64))->postJson($url.'/finish', $body + ['result' => $result])->assertOk();
        $this->withToken(str_repeat('a', 64))->postJson($url.'/finish', $body + ['result' => $result])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame('failed', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->assertSame(0, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        // The separate emergency off switch keeps its original semantics.
        config(['create.enabled' => false]);
        $this->withToken(str_repeat('a', 64))->postJson($url.'/heartbeat', $body + ['sequence' => 2, 'stage' => 'Late'])->assertNotFound();
    }

    public function test_drain_keeps_queued_plans_and_allows_admitted_planning_to_finish(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'queued', true);
        $control = $this->drainControl(); $control->setPaused(true, null);
        $this->assertSame($job['id'], $plans->submit($this->owner, $c->id, 1, 'queued', true)['id']);
        $this->rejected(503, fn () => $plans->submit($this->owner, $c->id, 1, 'new', true));
        $plans->execute($job['id']);
        $this->travel(6)->minutes(); $plans->recover();
        $this->assertSame('queued', $plans->latest($c->id)['state']);
        $this->assertNull(DB::table('create_planning_jobs')->value('execution_token'));
        $this->assertSame(0, DB::table('create_plans')->count());
        Bus::assertDispatchedTimes(\App\Jobs\PlanCreateVideo::class, 1);
        $control->setPaused(false, null); $plans->recover();
        Bus::assertDispatchedTimes(\App\Jobs\PlanCreateVideo::class, 2);
        $real = new \App\Services\Create\PlanService($this->conversations);
        $mock = \Mockery::mock(\App\Services\Create\PlanService::class);
        $mock->shouldReceive('propose')->once()->andReturnUsing(function ($user, $id, $version, $key, $skip, $token) use ($control, $real) {
            $control->setPaused(true, 'Drain after planning execution admitted');
            $this->rejected(409, fn () => $real->propose($user, $id, $version, $key, $skip, 'forged-token'));
            return $real->propose($user, $id, $version, $key, $skip, $token);
        });
        $this->app->instance(\App\Services\Create\PlanService::class, $mock);
        $plans->execute($job['id']);
        $this->assertSame('done', $plans->latest($c->id)['state']);
        $this->assertSame(1, DB::table('create_plans')->count());
        $plans->execute($job['id']); // No new execution even after queue redelivery.
        $this->assertSame(1, DB::table('create_plans')->count());
    }

    public function test_drain_blocks_legacy_planning_and_new_expensive_intake_before_io(): void
    {
        $c = $this->durablePlanningBrief(); config(['create.durable_planning' => false]);
        $this->drainControl()->setPaused(true, null);
        foreach ([false, true] as $async) {
            $this->actingAs($this->owner)->postJson('/api/v1/create/conversations/'.$c->id.'/plans', [
                'expected_version' => 1, 'idempotency_key' => 'legacy', 'async' => $async,
            ])->assertStatus(503);
        }
        $this->rejected(503, fn () => app(\App\Services\Create\AttachmentUploadService::class)->upload($this->owner, $c->id, $this->uploadPng(), 'reference', 'upload', 1));
        foreach (['ReferenceLinkService', 'MediaLinkService', 'PageReferenceService'] as $service) {
            $this->rejected(503, fn () => app('App\\Services\\Create\\References\\'.$service)->add($this->owner, $c->id, 'https://example.test/file', 1, 'link'));
        }
        $this->rejected(503, fn () => $this->conversations->quote($this->owner, $c->id, 1));
        $this->assertSame(0, DB::table('create_plans')->count());
        Http::assertNothingSent();
    }

    public function test_lease_sweep_during_drain_retains_unknown_spend_and_requires_stop_confirmation(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $attempts = app(\App\Services\Create\AttemptService::class);
        $a = $attempts->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('c', 64));
        $this->drainControl()->setPaused(true, null);
        $held = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $this->travel(100)->seconds();
        $this->artisan('create:check-leases')->assertSuccessful();
        $this->assertSame(0, $this->runs->expireLeases(), 'No repeated transition or automatic replay');
        $state = DB::table('composition_runs')->where('id', $run->id)->first();
        $this->assertSame('needs_attention', $state->status);
        $this->assertNull($state->worker_stopped_at);
        $after = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $this->assertSame($held->reserved_credits, $after->reserved_credits);
        $this->assertSame($held->capacity_slots, $after->capacity_slots);
        $this->rejected(409, fn () => $this->runs->heartbeat($run->id, $claim['lease_token'], 2, 'Late'));
        $this->rejected(409, fn () => $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Late'], null, null));
        $this->assertTrue($this->runs->workerStopped($run->id, $claim['lease_token'])['hold_retained']);
        $this->assertSame('started', DB::table('composition_attempts')->where('id', $a['id'])->value('status'));
        $this->assertEquals($held->reserved_credits, DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
    }

    public function test_drain_command_is_persistent_and_missing_controls_fail_closed(): void
    {
        $control = $this->drainControl();
        $this->artisan('create:drain', ['action' => 'pause', '--reason' => 'Test'])->assertSuccessful();
        $this->assertTrue((new \App\Services\Create\AdmissionControl)->paused());
        $this->artisan('create:drain', ['action' => 'invalid'])->assertFailed();
        $this->assertTrue($control->paused());
        $status = $control->status();
        $this->assertSame(0, $status['unconfirmed_workers']);
        $this->assertArrayNotHasKey('safe_to_shutdown', $status);
        $this->artisan('create:drain', ['action' => 'resume'])->assertSuccessful();
        $this->assertFalse($control->paused());
        DB::table('create_runtime_controls')->delete();
        $this->rejected(503, fn () => $this->runs->claim());
    }

    public function test_bounded_drain_wait_requires_enabled_paused_durable_planning(): void
    {
        $this->artisan('create:drain', ['action' => 'wait', '--timeout' => 0])->assertFailed();
        config(['create.durable_planning' => true]);
        $control = $this->drainControl();
        $this->artisan('create:drain', ['action' => 'wait', '--timeout' => 0])->assertFailed();
        $control->setPaused(true, 'Test');
        $this->artisan('create:drain', ['action' => 'wait', '--timeout' => 0])->assertSuccessful();
        $this->assertTrue($control->paused(), 'Wait never resumes admission');
        $this->artisan('create:drain', ['action' => 'wait', '--timeout' => -1])->assertFailed();
    }

    public function test_drain_wait_preserves_active_work_and_its_hold(): void
    {
        [, , $run] = $this->admitted(); $this->runs->claim();
        config(['create.durable_planning' => true]);
        $control = $this->drainControl(); $control->setPaused(true, 'Test');
        $before = DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits');
        $this->artisan('create:drain', ['action' => 'wait', '--timeout' => 0])->assertFailed();
        $this->assertSame('running', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->assertSame($before, DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        $status = $control->status();
        $this->assertContains('builds.running', \App\Services\Create\AdmissionControl::drainBlockers($status));
        $status['builds'] = []; $status['pending_media'] = 1;
        $this->assertContains('pending_media', \App\Services\Create\AdmissionControl::drainBlockers($status));
        $status['pending_media'] = 0; $status['planning'] = ['needs_attention' => 1];
        $this->assertContains('planning.needs_attention', \App\Services\Create\AdmissionControl::drainBlockers($status));
        $status['planning'] = []; unset($status['unresolved_attempts']);
        $this->assertContains('unresolved_attempts', \App\Services\Create\AdmissionControl::drainBlockers($status));
    }

    private function durablePlanningBrief(): object
    {
        config(['create.durable_planning' => true]);
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A launch video for my desk.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        return $c;
    }

    public function test_low_disk_preserves_brief_and_leaves_accepted_planning_queued_without_model_work(): void
    {
        $c = $this->durablePlanningBrief();
        $low = \Mockery::mock(\App\Services\Create\DiskSpace::class);
        $low->shouldReceive('admission')->andThrow(new \App\Services\Create\DiskCapacityException());
        $this->app->instance(\App\Services\Create\DiskSpace::class, $low);
        $this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/plans", [
            'expected_version' => 1, 'idempotency_key' => 'low-disk', 'async' => true,
        ])->assertStatus(503);
        $this->assertSame(1, DB::table('create_messages')->where('role', 'user')->count());
        $this->assertSame(0, DB::table('create_planning_jobs')->count());
        Bus::assertNothingDispatched(); Http::assertNothingSent();
        $this->app->forgetInstance(\App\Services\Create\DiskSpace::class);
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'low-disk', false);
        $this->app->instance(\App\Services\Create\DiskSpace::class, $low);
        $plans->execute($job['id']);
        $this->assertSame('queued', DB::table('create_planning_jobs')->value('state'));
        $this->assertNull(DB::table('create_planning_jobs')->value('execution_token'));
        $this->assertSame(0, DB::table('create_plans')->count());
        $this->assertSame($job['id'], $plans->submit($this->owner, $c->id, 1, 'low-disk', false)['id']);
        $this->app->forgetInstance(\App\Services\Create\DiskSpace::class);
        $plans->execute($job['id']);
        $this->assertSame('done', DB::table('create_planning_jobs')->value('state'));
        $this->assertSame(1, DB::table('create_plans')->count());
    }

    public function test_low_api_disk_does_not_claim_or_fail_a_queued_build(): void
    {
        [, , $run] = $this->admitted();
        $low = \Mockery::mock(\App\Services\Create\DiskSpace::class);
        $low->shouldReceive('admission')->andThrow(new \App\Services\Create\DiskCapacityException());
        $this->app->instance(\App\Services\Create\DiskSpace::class, $low);
        $this->rejected(503, fn () => $this->runs->claim());
        $this->assertSame('queued', DB::table('composition_runs')->where('id', $run->id)->value('status'));
        $this->assertNull(DB::table('composition_runs')->where('id', $run->id)->value('lease_expires_at'));
        Http::assertNothingSent();
    }

    public function test_durable_planning_persists_admission_and_deduplicates_before_any_work(): void
    {
        $c = $this->durablePlanningBrief();
        $url = "/api/v1/create/conversations/$c->id/plans";
        $body = ['expected_version' => 1, 'idempotency_key' => 'durable-1', 'async' => true];
        $job = $this->actingAs($this->owner)->postJson($url, $body)->assertStatus(202)->json('data');
        $this->assertSame('queued', $job['state']);
        $this->assertSame(0, DB::table('create_plans')->count());
        $this->actingAs($this->owner)->postJson($url, $body)->assertStatus(202)->assertJsonPath('data.id', $job['id']);
        Bus::assertDispatchedTimes(\App\Jobs\PlanCreateVideo::class, 1);
        $this->actingAs($this->owner)->postJson($url, [...$body, 'skip_questions' => true])->assertStatus(409);
        $this->actingAs($this->owner)->postJson($url, [...$body, 'idempotency_key' => 'other'])->assertStatus(409);
        $this->assertSame(1, DB::table('create_planning_jobs')->count());
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $plans->execute($job['id']);
        $plans->execute($job['id']);
        $this->assertSame(1, DB::table('create_plans')->count(), 'a repeated delivery cannot buy another plan');
        \Illuminate\Support\Facades\Cache::flush();
        $saved = $this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id/plan-activity?key=durable-1")
            ->assertOk()->assertJsonPath('job.state', 'done')->json('job');
        $this->assertSame(DB::table('create_plans')->value('id'), $saved['plan_id']);
        $this->actingAs($this->owner)->postJson($url, $body)->assertOk()->assertJsonPath('data.state', 'done');
    }

    public function test_durable_planning_scheduler_redelivers_only_unstarted_work(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'queued', false);
        DB::table('create_planning_jobs')->where('id', $job['id'])->update(['dispatched_at' => null]);
        $plans->recover();
        Bus::assertDispatchedTimes(\App\Jobs\PlanCreateVideo::class, 2);
        DB::table('create_planning_jobs')->where('id', $job['id'])->update([
            'state' => 'running', 'execution_token' => (string) \Illuminate\Support\Str::uuid(), 'deadline_at' => now()->subMinute(),
        ]);
        $plans->recover();
        $this->assertSame('needs_attention', $plans->latest($c->id)['state']);
        $plans->execute($job['id']);
        $this->assertSame(0, DB::table('create_plans')->count(), 'uncertain execution is never replayed');
        $this->rejected(409, fn () => $plans->submit($this->owner, $c->id, 1, 'fresh-key', false));
        Bus::assertDispatchedTimes(\App\Jobs\PlanCreateVideo::class, 2);
    }

    public function test_durable_planning_recovers_a_plan_committed_before_the_worker_died(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'commit-gap', false);
        $plans->execute($job['id']);
        $planId = $plans->latest($c->id)['plan_id'];
        $credits = $this->workspace->fresh()->credits_monthly;
        DB::table('create_planning_jobs')->where('id', $job['id'])->update(['state' => 'running', 'result_json' => null, 'deadline_at' => now()->subMinute()]);
        $plans->recover();
        $this->assertSame('done', $plans->latest($c->id)['state']);
        $this->assertSame($planId, $plans->latest($c->id)['plan_id']);
        $this->assertSame(1, DB::table('create_plans')->count());
        $this->assertSame($credits, $this->workspace->fresh()->credits_monthly);
    }

    public function test_durable_planning_rechecks_version_and_access_before_work(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'stale', false);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Change the headline.', 'expected_version' => 1, 'idempotency_key' => 'changed']);
        $plans->execute($job['id']);
        $this->assertSame('failed', $plans->latest($c->id)['state']);
        $this->assertSame(409, $plans->latest($c->id)['status']);
        $job2 = $plans->submit($this->owner, $c->id, 2, 'access', false);
        $this->owner->forceFill(['workspace_id' => 99999])->save();
        $plans->execute($job2['id']);
        $this->assertSame(403, $plans->latest($c->id, 'access')['status']);
        $this->assertSame(0, DB::table('create_plans')->count());
    }

    public function test_durable_planning_failure_is_visible_and_not_automatically_replayed(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'interrupted', false);
        $fake = \Mockery::mock(\App\Services\Create\PlanService::class);
        $fake->shouldReceive('propose')->once()->andThrow(new \RuntimeException('secret provider detail'));
        $this->app->instance(\App\Services\Create\PlanService::class, $fake);
        $plans->execute($job['id']);
        $plans->execute($job['id']);
        $result = $plans->latest($c->id);
        $this->assertSame('needs_attention', $result['state']);
        $this->assertStringNotContainsString('secret', $result['error']);
        $this->assertSame(0, DB::table('create_plans')->count());
    }

    public function test_durable_planning_keeps_accepted_work_when_queue_publish_fails(): void
    {
        $c = $this->durablePlanningBrief();
        Bus::swap(\Mockery::mock(\Illuminate\Contracts\Bus\QueueingDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Redis unavailable'));
        }));
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'redis-down', false);
        $this->assertSame('queued', $job['state']);
        $this->assertNull(DB::table('create_planning_jobs')->where('id', $job['id'])->value('dispatched_at'));
        Bus::fake();
        $plans->recover();
        Bus::assertDispatchedTimes(\App\Jobs\PlanCreateVideo::class, 1);
    }

    public function test_durable_planning_latest_request_is_ordered_even_with_identical_timestamps(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $this->freezeTime();
        $first = $plans->submit($this->owner, $c->id, 1, 'first', false);
        DB::table('create_planning_jobs')->where('id', $first['id'])->update(['state' => 'failed']);
        $second = $plans->submit($this->owner, $c->id, 1, 'second', false);
        $this->assertSame($second['id'], $plans->latest($c->id)['id']);
        $this->assertSame($first['id'], $plans->latest($c->id, 'first')['id']);
    }

    public function test_durable_planning_status_is_private_to_the_conversation_workspace(): void
    {
        $c = $this->durablePlanningBrief();
        app(\App\Services\Create\PlanningJobService::class)->submit($this->owner, $c->id, 1, 'private', false);
        $other = Workspace::create(['name' => 'Other', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $other->id])->save();
        config(['create.workspaces' => []]);
        $this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id/plan-activity?key=private")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/plans", ['expected_version' => 1, 'idempotency_key' => 'private', 'async' => true])->assertNotFound();
    }

    public function test_durable_planning_disabled_workers_leave_queued_work_untouched(): void
    {
        $c = $this->durablePlanningBrief();
        $plans = app(\App\Services\Create\PlanningJobService::class);
        $job = $plans->submit($this->owner, $c->id, 1, 'disabled', false);
        config(['create.enabled' => false]);
        $plans->execute($job['id']);
        $this->assertSame('queued', $plans->latest($c->id)['state']);
        $this->assertSame(0, DB::table('create_plans')->count());
    }

    public function test_planning_can_answer_at_once_and_finish_after_the_response(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15]);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A launch video for my desk.', 'expected_version' => 0, 'idempotency_key' => 'b1']);
        $started = $this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/plans", ['expected_version' => 1, 'idempotency_key' => 'bg-1', 'async' => true])->assertStatus(202)->json('data');
        $this->assertSame(['bg-1', 'running'], [$started['key'], $started['state']]);
        $this->app->terminate(); // what PHP-FPM does once the response is sent
        // The work ran after the response: the activity endpoint says it is done, and the plan exists.
        $job = $this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id/plan-activity")->assertOk()->json('job');
        $this->assertSame(['bg-1', 'done'], [$job['key'], $job['state']]);
        $this->assertSame(1, DB::table('create_plans')->where('conversation_id', $c->id)->count());
        // Asking again with the same key does not plan twice.
        $this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/plans", ['expected_version' => 1, 'idempotency_key' => 'bg-1', 'async' => true])->assertOk();
        $this->assertSame(1, DB::table('create_plans')->where('conversation_id', $c->id)->count());
        // A refusal is reported, not lost: a stale version.
        $this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/plans", ['expected_version' => 99, 'idempotency_key' => 'bg-2', 'async' => true])->assertStatus(202);
        $this->app->terminate();
        $failed = $this->actingAs($this->owner)->getJson("/api/v1/create/conversations/$c->id/plan-activity")->json('job');
        $this->assertSame(['failed', 409], [$failed['state'], $failed['status']]);
    }

    public function test_every_vendors_errors_are_read_the_same_way(): void
    {
        $k = fn ($text, $status = null) => \App\Services\Vendors\VendorError::classify($text, $status);
        // Real error bodies, as each vendor sends them.
        $this->assertSame('vendor_credit', $k('{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}', 400));
        $this->assertSame('vendor_credit', $k('{"title":"Insufficient credit","detail":"You have insufficient credit to run this model. Go to https://replicate.com/account/billing#billing to purchase credit.","status":402}', 402));
        $this->assertSame('vendor_credit', $k('{"error":{"message":"You exceeded your current quota, please check your plan and billing details.","type":"insufficient_quota"}}', 429));
        $this->assertSame('vendor_config', $k('{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}', 401));
        $this->assertSame('vendor_config', $k('{"detail":"Invalid token.","status":401}', 401));
        $this->assertSame('content_refused', $k('The input or output was flagged as sensitive. Please try again with different inputs. (E005)'));
        $this->assertSame('content_refused', $k('Prediction failed: E006 the content was blocked'));
        $this->assertSame('busy', $k('nano-banana failed: ModelRateLimitError: Service is currently unavailable due to high demand.'));
        $this->assertSame('busy', $k('{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', 529));
        $this->assertSame('busy', $k('{"error":{"code":429,"status":"RESOURCE_EXHAUSTED"}}'));
        $this->assertSame('other', $k('{"type":"error","error":{"type":"invalid_request_error","message":"messages: text content blocks must be non-empty"}}', 400));
    }

    public function test_pilot_policy_switches_the_build_agent_to_the_claude_gateway(): void
    {
        $this->pilot(); config(['create.agent_provider'=>'replicate']);
        $this->assertSame('replicate',\App\Services\Create\PilotPolicy::execution([])['agent']['provider']);
        config(['create.agent_provider'=>'anthropic','create.agent_model'=>'claude-opus-5-5','services.anthropic.key'=>'']);
        $this->rejected(503,fn()=>\App\Services\Create\PilotPolicy::execution([]));
        config(['services.anthropic.key'=>'k']);
        $agent=\App\Services\Create\PilotPolicy::execution([])['agent'];
        $this->assertSame(['anthropic','claude-opus-5-5',1200000,32000,'medium'],[$agent['provider'],$agent['model'],$agent['cost_limit_microusd'],$agent['max_output_tokens'],$agent['effort']]);
    }

    public function test_confirmed_render_queue_expiry_releases_hold_without_recovery(): void
    {
        $this->pilot();
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'queue-expiry', true);
        $claim = $this->runs->claim(); $attempts = app(\App\Services\Create\AttemptService::class);
        $this->assertGreaterThan(0, (int) DB::table('api_operations')->where('id', $run->operation_id)->value('reserved_credits'));
        $attempt = $attempts->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        // The host confirmed flock expired before execution and the container stopped.
        $receipt = ['status' => 'failed', 'cost_microusd' => 0];
        $attempts->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt);
        $this->assertTrue($attempts->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt)['replayed']);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'The render queue stayed busy too long.'], null, null);
        $op = DB::table('api_operations')->where('id', $run->operation_id)->first();
        $this->assertSame(['failed', 0, 0], [$op->status, (int) $op->reserved_credits, (int) $op->spent_credits]);
        $this->assertFalse($attempts::unresolved($run->id));
        $next = $this->brief(); $quote = $this->conversations->quote($this->owner, $next->id, 1);
        $nextRun = $this->conversations->approve($this->owner, $next->id, $quote->id, 'after-queue-expiry', true);
        $this->assertSame($nextRun->id, $this->runs->claim()['id'], 'queue expiry does not block subsequent work');
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
        config(['create.mode'=>'agent','create.paid_execution_enabled'=>true,'create.pilot_budget_id'=>'e3-test','create.pilot_budget_microusd'=>20000000]);
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
        $this->assertGreaterThanOrEqual(3, \App\Services\Create\References\ReferenceStudy::VERSION, 'studies made before moves were tagged are made again');
        // How each moment and element was made travels with it, for routing; the whole video's type and measured look too.
        $methods = \App\Services\Create\References\ReferenceStudy::normalizeSystems([['id' => 's1', 'name' => 'mascot', 'method' => 'render_3d'], ['id' => 's2', 'name' => 'x', 'method' => 'magic']]);
        $this->assertSame(['render_3d', null], array_map(fn ($x) => $x['method'] ?? null, $methods));
        $brief = \App\Services\Create\PlanService::studyBrief(1, ['summary' => 's', 'video_type' => 'mascot_explainer', 'fps' => 60, 'treatment' => ['dither_share' => 0.52, 'dither_step_px' => 2, 'look' => 'ordered_dither'], 'moments' => [], 'systems' => []]);
        $this->assertSame(['mascot_explainer', 60, 'ordered_dither'], [$brief['video_type'], $brief['fps'], $brief['treatment']['look']]);
        $this->assertStringContainsString('render_3d → mascot3d', \App\Services\Create\Planning\PlanPrompt::system());
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
        $this->assertTrue(\App\Services\Create\PilotPolicy::enabled(), 'paid builds need no overall cap (owner, 2026-10-06)');
        \App\Services\Create\PilotPolicy::admit($p); // no overall cap set: nothing to check
        config(['create.pilot_budget_microusd'=>5000000]);
        $this->assertSame(100, \App\Services\Create\PilotPolicy::execution(['output_kind'=>'video','duration_seconds'=>15])['agent']['max_calls']);
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
        $this->assertSame(10,$retry->credits_max,'a retry holds what the approved run held, no more');
        // One action: the approval already given is used again; no new cost review.
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $started=$this->actingAs($this->owner)->postJson("/api/v1/create/conversations/$c->id/runs/{$claim['id']}/retry",['idempotency_key'=>'retry'])->assertStatus(202);
        $this->assertSame($claim['id'],json_decode(DB::table('composition_runs')->where('id',$started->json('data.id'))->value('input_json'),true)['retry_of']);
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
        $this->assertSame('issues', $review['status'], 'something still to listen to is not a pass');
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

    public function test_a_direct_video_or_image_link_is_downloaded_as_the_users_media(): void
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $tmp = sys_get_temp_dir().'/link-'.uniqid(); @mkdir($tmp);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:d=1', '-pix_fmt', 'yuv420p', $tmp.'/demo.mp4']);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=64x64', '-frames:v', '1', $tmp.'/product.png']);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=s=641x481:r=25:d=1', '-c:v', 'mpeg4', $tmp.'/odd.mov']);
        \App\Services\Create\References\PageReferenceService::$resolve = fn () => ['93.184.216.34'];
        Http::fake(['https://cdn.example.com/demo/app-demo.mp4' => Http::response(file_get_contents($tmp.'/demo.mp4'), 200, ['Content-Type' => 'video/mp4']),
            'https://cdn.example.com/shots/product.png' => Http::response(file_get_contents($tmp.'/product.png'), 200, ['Content-Type' => 'image/png']),
            'https://cdn.example.com/screen/odd.mov' => Http::response(file_get_contents($tmp.'/odd.mov'), 200, ['Content-Type' => 'video/quicktime']),
            'https://cdn.example.com/missing.mp4' => Http::response('', 404)]);
        try {
            $this->assertTrue(\App\Services\Create\References\MediaLinkService::isMediaFile('https://cdn.example.com/demo/app-demo.mp4'));
            $this->assertFalse(\App\Services\Create\References\MediaLinkService::isMediaFile('https://wyvstudio.com/pricing'));
            $asset = app(\App\Services\Create\References\MediaLinkService::class)->add($this->owner, $c->id, 'https://cdn.example.com/demo/app-demo.mp4', (int) $c->version, 'link-1');
            $this->assertSame('video', $asset->asset_type);
            $this->assertSame('source', DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $asset->id)->value('purpose'));
            $this->assertSame('link', data_get($asset->metadata_json, 'reference_source.platform'));
            // A .mov of odd size is converted to an MP4 with even sides.
            $mov = app(\App\Services\Create\References\MediaLinkService::class)->add($this->owner, $c->id, 'https://cdn.example.com/screen/odd.mov', (int) DB::table('create_conversations')->where('id', $c->id)->value('version'), 'link-4');
            $this->assertSame(['video', 'video/mp4'], [$mov->asset_type, $mov->mime_type]);
            // An image link is the user's own picture too.
            $this->assertTrue(\App\Services\Create\References\MediaLinkService::isMediaFile('https://cdn.example.com/shots/product.PNG'));
            $image = app(\App\Services\Create\References\MediaLinkService::class)->add($this->owner, $c->id, 'https://cdn.example.com/shots/product.png', (int) DB::table('create_conversations')->where('id', $c->id)->value('version'), 'link-3');
            $this->assertSame('image', $image->asset_type);
            $this->assertSame('source', DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $image->id)->value('purpose'));
            try {
                app(\App\Services\Create\References\MediaLinkService::class)->add($this->owner, $c->id, 'https://cdn.example.com/missing.mp4', (int) DB::table('create_conversations')->where('id', $c->id)->value('version'), 'link-2');
                $this->fail('A missing video should be refused.');
            } catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        } finally {
            \App\Services\Create\References\PageReferenceService::$resolve = null;
            @unlink($tmp.'/demo.mp4'); @unlink($tmp.'/product.png'); @unlink($tmp.'/odd.mov'); @rmdir($tmp);
        }
    }

    public function test_a_network_blip_while_downloading_output_is_retried_then_reported_as_lost_not_held(): void
    {
        \Illuminate\Support\Sleep::fake();
        $fetch = (new \ReflectionClass(\App\Services\Create\PlanMediaExecutor::class))->getMethod('fetch');
        $tmp = sys_get_temp_dir().'/fetch-'.uniqid().'.mp3';
        $calls = 0;
        Http::fake(['https://replicate.delivery/*' => function () use (&$calls) {
            if (++$calls < 3) throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: replicate.delivery');
            return Http::response('ID3audio', 200);
        }]);
        $this->assertSame($tmp, $fetch->invoke(app(\App\Services\Create\PlanMediaExecutor::class), 'https://replicate.delivery/x/a.mp3', $tmp));
        $this->assertSame(3, $calls);
        @unlink($tmp);
        Http::fake(['https://replicate.delivery/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host')]);
        $this->expectException(\App\Services\Create\OutputUnavailable::class);
        $fetch->invoke(app(\App\Services\Create\PlanMediaExecutor::class), 'https://replicate.delivery/x/b.mp3', $tmp);
    }

    public function test_footage_with_sparse_keyframes_or_60_fps_is_made_renderable_once_and_stays_silent(): void
    {
        $dir = sys_get_temp_dir().'/intake-'.uniqid(); @mkdir($dir);
        \Illuminate\Support\Facades\Process::run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=s=320x242:r=60:d=6', '-c:v', 'libx264', '-g', '300', '-pix_fmt', 'yuv420p', $dir.'/screen.mp4']);
        $before = \App\Services\Create\VideoIntake::inspect($dir.'/screen.mp4');
        $this->assertTrue($before['fix']);
        $out = \App\Services\Create\VideoIntake::prepare($dir.'/screen.mp4');
        $after = \App\Services\Create\VideoIntake::inspect($out);
        $this->assertFalse($after['fix']);
        $this->assertLessThanOrEqual(1.1, $after['gap']);
        $this->assertEqualsWithDelta(30, $after['fps'], 0.5);
        $audio = \Illuminate\Support\Facades\Process::run(['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $out]);
        $this->assertSame('', trim($audio->output()), 'a silent video stays silent');
        $this->assertSame($out, \App\Services\Create\VideoIntake::prepare($out), 'a renderable file is left as it is');
        foreach (glob($dir.'/*') as $f) @unlink($f); @rmdir($dir);
    }

    public function test_a_network_drop_before_a_request_is_waited_out_but_a_sent_request_is_never_repeated(): void
    {
        \Illuminate\Support\Sleep::fake();
        $n = 0;
        $r = \App\Services\Create\NetRetry::run(function () use (&$n) {
            if (++$n < 4) throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host: api.openai.com');
            return 'ok';
        });
        $this->assertSame(['ok', 4], [$r, $n]);
        $n = 0;
        try {
            \App\Services\Create\NetRetry::run(function () use (&$n) { $n++; throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 120000 ms with 0 bytes received'); });
            $this->fail('A request that may have been sent must not be repeated.');
        } catch (\Illuminate\Http\Client\ConnectionException) { $this->assertSame(1, $n); }
        // Reads (checking a job, downloading output) are always safe to repeat.
        $n = 0;
        $this->assertSame('read', \App\Services\Create\NetRetry::run(function () use (&$n) {
            if (++$n < 2) throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            return 'read';
        }, true));
    }

    public function test_a_failed_image_batch_keeps_the_images_that_finished_and_never_draws_them_twice(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $calls = [];
        $fail = ['Shop'];
        app()->instance(\App\Services\Generation\Image\NanoBananaProImageAdapter::class, new class($calls, $fail, $png) {
            public function __construct(private array &$calls, private array &$fail, private string $png) {}
            public function generate($prompt, $style, $aspect, $opts = []) {
                $who = str_contains($prompt, 'of Shop') ? 'Shop' : 'Maya';
                $this->calls[] = $who;
                if (in_array($who, $this->fail, true)) throw new \RuntimeException('provider hiccup');
                return ['image_b64' => $this->png];
            }
        });
        $ctx = ['workspace_id' => 99001, 'aspect_ratio' => '9:16', 'character_style' => '', 'shot' => ['subjects' => [
            ['name' => 'Maya', 'kind' => 'character', 'looks' => 'red coat'], ['name' => 'Shop', 'kind' => 'place', 'looks' => 'candle shop']]]];
        $dir = sys_get_temp_dir().'/keep-'.uniqid(); @mkdir($dir);
        try {
            try { app(\App\Services\Create\PlanMediaExecutor::class)->produce('reference_sheet', 'anime night', $ctx, $dir); $this->fail('A failed image fails the sheet.'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('the others are kept', $e->getMessage()); }
            $this->assertSame(['Maya', 'Shop'], $calls);
            $calls = []; $fail = [];
            $made = app(\App\Services\Create\PlanMediaExecutor::class)->produce('reference_sheet', 'anime night', $ctx, $dir);
            $this->assertSame(['Shop'], $calls, 'only the image that failed is drawn again');
            $this->assertCount(1, $made['extra']);
            $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->files('create/image-jobs/99001'), 'emptied once delivered');
        } finally { \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('create/image-jobs/99001'); }
    }

    public function test_a_final_look_that_cannot_reach_our_model_says_why_and_alerts(): void
    {
        [, , $run]=$this->admitted(); $claim=$this->runs->claim();
        config(['services.anthropic.key'=>'test-key','create.admin_alert_emails'=>['ops@example.com']]);
        \Illuminate\Support\Facades\Mail::fake();
        $dry=['type'=>'error','error'=>['type'=>'invalid_request_error','message'=>'Your credit balance is too low to access the Anthropic API.']];
        Http::fake(['https://api.anthropic.com/*'=>Http::response($dry,400)]);
        $look=app(\App\Services\Create\FinalLook::class)->check($run->id,$claim['lease_token'],[['time'=>1.0,'jpeg'=>'x']]);
        $this->assertSame(['status'=>'unverified','note'=>'the checking model is unavailable on our side'],$look);
        $this->assertSame(1,DB::table('vendor_incidents')->where('vendor','anthropic')->where('kind','vendor_credit')->count());
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VendorAlertMail::class,fn($m)=>$m->kind==='vendor_credit');
    }

    public function test_a_pronunciation_in_the_brief_is_kept_for_every_voice(): void
    {
        $map = \App\Services\Ugc\PronunciationMap::class;
        $this->assertSame(['WyvStudio' => 'wiv studio'], $map::fromBrief('A 20 s promo for WyvStudio. Say WyvStudio as "wiv studio", and say it like a friend would.'));
        $this->assertSame(['WyvStudio' => 'wiv studio'], $map::fromBrief('WyvStudio (pronounced wiv studio) makes ads without a shoot.'));
        $this->assertSame(['Nguyen' => 'win'], $map::fromBrief('Our founder Nguyen is pronounced win.'));
        $this->assertSame([], $map::fromBrief('Say it as a question, and say hello as you walk in. Make it pronounced and bold.'));

        $c = $this->conversations->create($this->owner, []);
        $this->conversations->message($this->owner, $c->id, ['content' => 'A launch video for WyvStudio. Say WyvStudio as "wiv studio".', 'expected_version' => (int) $c->version, 'idempotency_key' => 'brief']);
        $this->assertSame('wiv studio', DB::table('create_pronunciations')->where('workspace_id', $this->workspace->id)->where('written', 'WyvStudio')->value('spoken'));
        $this->assertTrue(DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'assistant')->where('content', 'like', 'Every voice will say WyvStudio as “wiv studio”%')->exists());
        $this->assertSame('Try wiv studio today', \App\Services\Create\PlanMediaExecutor::pronounce('Try WyvStudio today', $this->workspace->id));
        $this->assertSame([['written' => 'WyvStudio', 'spoken' => 'wiv studio']], \App\Services\Create\PlanMediaExecutor::pronunciationsIn("Hello.\nTry WyvStudio today", $this->workspace->id), 'the listening check holds the voice to it');
        $this->assertSame([], \App\Services\Create\PlanMediaExecutor::pronunciationsIn('No names here', $this->workspace->id));
    }

    public function test_one_workspace_runs_at_most_two_plans_at_once_and_its_next_plan_waits_its_turn(): void
    {
        config(['create.durable_planning' => true]);
        Bus::fake();
        $row = fn ($state) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => (string) \Illuminate\Support\Str::uuid(), 'workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id,
            'idempotency_key' => \Illuminate\Support\Str::random(8), 'request_hash' => 'h', 'expected_version' => 1, 'skip_questions' => false, 'state' => $state, 'created_at' => now()->subMinutes(9), 'updated_at' => now()];
        DB::table('create_planning_jobs')->insert([$row('running'), $row('running')]);
        DB::table('create_planning_jobs')->insert($waiting = $row('queued'));
        app(\App\Services\Create\PlanningJobService::class)->execute($waiting['id']);
        $this->assertSame('queued', DB::table('create_planning_jobs')->where('id', $waiting['id'])->value('state'), 'no model work while two of its plans run');
        Bus::assertDispatched(\App\Jobs\PlanCreateVideo::class, fn ($j) => $j->planningJobId === $waiting['id'] && $j->delay !== null);
        // Taking its turn is not reported as stuck planning.
        $this->assertArrayNotHasKey('planning', app(\App\Services\Create\CreateHealth::class)->problems());
    }

    /** A finished version whose run built a serum ad: a generated bottle picture, a shot, voice and music. */
    private function changeableVersion(): array
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $input = json_decode($run->input_json, true);
        $input['plan']['scenes'] = [['label' => 'Hook', 'start' => 0, 'end' => 4, 'idea' => 'A drop lands', 'reads' => ['Brighter skin?']], ['label' => 'Bottle', 'start' => 4, 'end' => 12, 'idea' => 'The bottle rises', 'reads' => ['Dewbloom Glow']]];
        $input['plan']['narration'] = ['Want brighter-looking skin?', 'Meet Dewbloom Glow.'];
        $input['plan']['voice'] = 'Aoede';
        $input['plan_media'] = [
            ['kind' => 'ai_image', 'description' => 'Frosted glass dropper bottle with a peach label', 'beat' => 'Bottle', 'credits' => 43, 'plan_item_index' => 0],
            ['kind' => 'generated_shot', 'description' => 'Macro of a golden drop forming', 'beat' => 'Hook', 'credits' => 165, 'plan_item_index' => 1],
            ['kind' => 'voiceover', 'description' => 'Warm read', 'credits' => 3, 'plan_item_index' => 2],
            ['kind' => 'music', 'description' => 'Airy pop', 'credits' => 34, 'plan_item_index' => 3],
            ['kind' => 'cutout', 'description' => 'bottle cut out', 'credits' => 2, 'plan_item_index' => 4],
        ];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        $bytes = 'offline encoded video fixture'; $hash = hash('sha256', $bytes); $path = 'create/previews/'.$run->id.'/'.$hash.'.mp4';
        \Illuminate\Support\Facades\Storage::fake('local'); \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'Test', 'bundle' => ['index.html' => '<h1>Test</h1>']], $path, $hash);
        return [$c, DB::table('composition_revisions')->where('run_id', $run->id)->value('id')];
    }

    public function test_the_change_drawer_lists_the_parts_with_their_prices(): void
    {
        [$c, $revision] = $this->changeableVersion();
        $parts = app(\App\Services\Create\ChangeService::class)->parts($this->owner, $c->id, $revision);
        $this->assertSame(['Picture · Bottle', 'Shot · Hook'], array_column($parts['parts'], 'name'), 'what is in the picture; voice, music and cut-outs are not parts');
        $this->assertSame([[4.0, 12.0], [0.0, 4.0]], array_column($parts['parts'], 'times'));
        $this->assertSame([43, 165], array_column($parts['parts'], 'remake_credits'));
        $this->assertSame(['Want brighter-looking skin?', 'Meet Dewbloom Glow.'], $parts['words']);
        $this->assertTrue($parts['sound']['music']); $this->assertTrue($parts['sound']['voiceover']); $this->assertSame('Aoede', $parts['sound']['voice']);
        [$low, $high] = $parts['estimate']['rebuild'];
        $this->assertGreaterThan(0, $low); $this->assertGreaterThan($low, $high);
    }

    public function test_the_change_drawer_sends_one_change_with_the_frame_and_plans_it_as_an_edit(): void
    {
        [$c, $revision] = $this->changeableVersion();
        config(['create.durable_planning' => true]); Bus::fake();
        $this->workspace->update(['credits_monthly' => 1000]);
        $version = (int) DB::table('create_conversations')->where('id', $c->id)->value('version');
        $out = app(\App\Services\Create\ChangeService::class)->change($this->owner, $c->id, $revision, ['expected_version' => $version,
            'moments' => [['time' => 9.2, 'text' => 'Make the drop golden and slower']],
            'parts' => [['id' => 'media-1', 'action' => 'remake', 'text' => 'closer'], ['id' => 'media-9', 'action' => 'remake']],
            'words' => [['index' => 1, 'text' => 'Meet Dewbloom Glow Serum.'], ['index' => 0, 'text' => 'Want brighter-looking skin?']],
            'music' => 'none', 'voice' => 'another'], [0 => $this->uploadPng('frame.png')]);
        $text = $out['message']->content;
        $this->assertStringStartsWith('Change version 1 of the video:', $text);
        $this->assertStringContainsString('- At 0:09 (the frame is attached): Make the drop golden and slower', $text);
        $this->assertStringContainsString('- Make a new shot · hook (0:00–0:04): closer.', $text);
        $this->assertStringContainsString('- Change the line "Meet Dewbloom Glow." to: "Meet Dewbloom Glow Serum."', $text);
        $this->assertStringNotContainsString('brighter-looking skin?" to', $text, 'an unchanged line is not a change');
        $this->assertStringContainsString("- Music: none.\n- Voice: a different voice.\nKeep everything else as it is.", $text);
        $frame = DB::table('create_attachments')->where('conversation_id', $c->id)->where('purpose', 'current')->first();
        $this->assertSame(['kind' => 'screenshot', 'use' => 'Make the drop golden and slower', 'time' => 9.2], json_decode($frame->notes_json, true));
        $this->assertSame('queued', $out['planning']['state']);
        $this->assertTrue((bool) DB::table('create_planning_jobs')->where('conversation_id', $c->id)->orderByDesc('created_at')->value('skip_questions'), 'a drawer change is specific: planned without a question');
        $this->assertSame('edit', \App\Services\Create\PlanService::plannerTask(DB::table('create_conversations')->where('id', $c->id)->first()), 'a long drawer request is still a change');
        $this->rejected(422, fn () => app(\App\Services\Create\ChangeService::class)->change($this->owner, $c->id, $revision, ['expected_version' => (int) DB::table('create_conversations')->where('id', $c->id)->value('version'), 'moments' => [['time' => 1, 'text' => ' ']]], []));
        // The same change sent again after its plan is made is a new request, not "a different message" (GTM-1 #5).
        $send = fn () => app(\App\Services\Create\ChangeService::class)->change($this->owner, $c->id, $revision, ['expected_version' => (int) DB::table('create_conversations')->where('id', $c->id)->value('version'), 'note' => 'Keep the same presenter.'], []);
        DB::table('create_planning_jobs')->where('conversation_id', $c->id)->update(['state' => 'done']);
        $first = $send()['message'];
        DB::table('create_planning_jobs')->where('conversation_id', $c->id)->update(['state' => 'done']);
        $again = $send()['message'];
        $this->assertNotSame($first->id, $again->id);
        $this->assertSame($first->content, $again->content);
    }

    public function test_suggest_a_change_reads_the_frame_and_is_billed_like_planning(): void
    {
        [$c, $revision] = $this->changeableVersion();
        config(['services.anthropic.key' => 'k', 'create.mode' => 'agent']);
        $this->workspace->update(['credits_monthly' => 1000]);
        Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"suggestion": "Hold the bottle label on screen a beat longer so it can be read.", "ideas": ["Slower drop", "Bigger label", "Warmer light", "extra"]}']],
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 60]])]);
        $s = app(\App\Services\Create\ChangeService::class)->suggest($this->owner, $c->id, $revision, 6.0, $this->uploadPng('frame.png'));
        $this->assertSame('Hold the bottle label on screen a beat longer so it can be read.', $s['suggestion']);
        $this->assertSame(['Slower drop', 'Bigger label', 'Warmer light'], $s['ideas']);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), '\"label\":\"Bottle\"') && $r['model'] === \App\Services\Create\AttachmentRoles::MODEL);
        $this->assertGreaterThanOrEqual(0, $s['charged']);
    }

    public function test_a_plan_amended_before_any_video_is_estimated_as_a_new_build(): void
    {
        [$c, , $run] = $this->admitted();
        $planId = (string) \Illuminate\Support\Str::uuid();
        DB::table('create_plans')->insert(['id' => $planId, 'conversation_id' => $c->id, 'message_id' => (string) \Illuminate\Support\Str::uuid(), 'brief_sequence' => 1, 'idempotency_key' => 'k'.$planId,
            'request_hash' => 'h', 'provider' => 'test', 'plan_json' => json_encode(['planner_task' => 'edit', 'video_type' => 'promo']), 'usage_json' => '{}', 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['type' => 'promo', 'task' => 'creative'], \App\Services\Create\CostEstimate::kindOf(['plan_id' => $planId]), 'no video yet: a whole build');
    }

    public function test_a_cut_of_the_users_own_video_keeps_their_voice_and_buys_no_voiceover(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $raw = ['summary' => 'A 30 s cut of their video.', 'scenes' => [['label' => 'Cut', 'start' => 0, 'end' => 30, 'idea' => 'Best lines']], 'left_out' => '',
            'narration' => ['Ever had a great video idea?', 'Now I post every day.']];
        $video = ['purpose' => 'source', 'asset_id' => 7, 'asset_type' => 'video', 'title' => 'my-video.mp4', 'speech' => 'Hi there. Ever had a great video idea? It took forever. Now I post every day, and it is easy.'];
        $mine = $plans->normalize($raw, ['files' => [$video]], $this->workspace->id);
        $this->assertFalse(collect($mine['media'])->contains(fn ($m) => in_array($m['kind'], ['voiceover', 'cloned_voiceover'], true)), 'their own recorded words: nothing bought to say them');
        $other = $plans->normalize($raw, ['files' => [['speech' => 'Something else entirely is said here.'] + $video]], $this->workspace->id);
        $this->assertTrue(collect($other['media'])->contains('kind', 'voiceover'), 'a script the video does not say still gets a voice');
        $filed = $plans->normalize($raw, ['files' => [['speech' => 'Something else entirely.', 'notes' => ['kind' => 'clip_speech', 'use' => 'extract voiceover']] + $video]], $this->workspace->id);
        $this->assertFalse(collect($filed['media'])->contains('kind', 'voiceover'), 'a clip filed as speech to use is the voice');
        $spelt = $plans->normalize([...$raw, 'narration' => ['Ever had a great video idea?', 'This is WyvStudio, now I post every day.']], ['files' => [['speech' => 'Ever had a great video idea? This is Weave Studio, now I post every day.'] + $video]], $this->workspace->id);
        $this->assertFalse(collect($spelt['media'])->contains('kind', 'voiceover'), 'a name spelt differently by the transcriber still matches');
    }

    public function test_a_name_respelled_by_the_planner_is_written_as_the_brand_writes_it(): void
    {
        DB::table('create_pronunciations')->insert(['workspace_id' => $this->workspace->id, 'written' => 'WyvStudio', 'spoken' => 'weave studio', 'created_at' => now(), 'updated_at' => now()]);
        $plans = app(\App\Services\Create\PlanService::class);
        $raw = ['summary' => 'A take.', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 4, 'idea' => 'Talk']], 'left_out' => '',
            'narration' => ['I used to spend a whole day on one ad. Weave Studio fixed that.', 'Now I type it into WyvStudio.']];
        $plan = $plans->normalize($raw, ['files' => []], $this->workspace->id);
        $this->assertSame(['I used to spend a whole day on one ad. WyvStudio fixed that.', 'Now I type it into WyvStudio.'], $plan['narration']);
        $this->assertSame($plan['narration'], $plan['selections']['narration']);
        $this->assertSame([['written' => 'WyvStudio', 'spoken' => 'weave studio']], $plan['spoken_names']);
    }

    public function test_a_deploy_waits_only_for_running_builds_and_plans_not_work_already_needing_attention(): void
    {
        [, , $run] = $this->admitted(); $this->runs->claim();
        config(['create.durable_planning' => true]);
        $this->drainControl()->setPaused(true, 'deploy');
        $this->artisan('create:drain', ['action' => 'quiet', '--timeout' => 0])->assertFailed();
        DB::table('composition_runs')->where('id', $run->id)->update(['status' => 'needs_attention']);
        $this->artisan('create:drain', ['action' => 'quiet', '--timeout' => 0])->assertSuccessful();
        $this->assertTrue($this->drainControl()->paused(), 'quiet never resumes');
    }

    public function test_a_low_anthropic_balance_is_warned_before_it_runs_dry(): void
    {
        (require database_path('migrations/2026_10_07_210000_create_vendor_balances.php'))->up();
        $this->assertArrayNotHasKey('balance', app(\App\Services\Create\CreateHealth::class)->problems(), 'nothing recorded: no guess');
        $this->artisan('create:model-balance', ['usd' => '50'])->expectsOutputToContain('About $50.00 left')->assertSuccessful();
        $runId = (string) \Illuminate\Support\Str::uuid();
        DB::table('composition_attempts')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'run_id' => $runId, 'operation_id' => 'op1', 'attempt_key' => 'a1', 'request_hash' => str_repeat('c', 64),
            'kind' => 'agent', 'provider' => 'anthropic', 'model' => 'm', 'status' => 'succeeded', 'credit_limit' => 1000, 'cost_limit_microusd' => 1, 'cost_microusd' => 20_000_000, 'charged_credits' => 0, 'created_at' => now()->addSecond(), 'updated_at' => now()]);
        $e = \App\Services\Vendors\ModelBalance::estimate();
        $this->assertSame([22.0, 28.0], [$e['spent'], $e['left']], '$20 measured plus the margin for unmetered calls');
        $p = app(\App\Services\Create\CreateHealth::class)->problems();
        $this->assertSame('Our Anthropic balance is low', $p['balance']['title']);
        $this->assertStringContainsString('About $28.00 is left of the $50.00', $p['balance']['text']);
        $this->artisan('create:model-balance', ['usd' => '300'])->assertSuccessful();
        $this->assertArrayNotHasKey('balance', app(\App\Services\Create\CreateHealth::class)->problems(), 'a top-up recorded clears it');
    }

    public function test_a_replan_keeps_the_people_already_made_unless_asked_for_someone_else(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $cast = ['kind' => 'reference_sheet', 'description' => 'Warm creator room, the approved woman with curly hair', 'subjects' => [['name' => 'Creator', 'looks' => 'curly hair']], 'credits' => 35];
        $raw = ['summary' => 'Reaction ad.', 'scenes' => [['label' => 'React', 'start' => 0, 'end' => 4, 'idea' => 'She reacts']], 'left_out' => '',
            'media' => [['kind' => 'reference_sheet', 'description' => 'Look of the whole video: near-black field, orange accents', 'subjects' => [['name' => 'Creator', 'looks' => 'new person']]]]];
        $ctx = fn ($ask) => ['files' => [], 'previous_plan' => ['cast' => [$cast]], 'messages' => [['role' => 'user', 'content' => 'A reaction ad.'], ['role' => 'user', 'content' => $ask]]];
        $kept = $plans->normalize($raw, $ctx('Show the plan turning into a product ad in the phone instead of the creator again.'), $this->workspace->id);
        $sheet = collect($kept['media'])->firstWhere('kind', 'reference_sheet');
        $this->assertSame('Warm creator room, the approved woman with curly hair', $sheet['description'], 'the same person: the sheet is reused, nobody new is drawn');
        $new = $plans->normalize($raw, $ctx('Use a different presenter, someone older.'), $this->workspace->id);
        $this->assertSame('Look of the whole video: near-black field, orange accents', collect($new['media'])->firstWhere('kind', 'reference_sheet')['description'], 'asked for someone else: the new sheet');
        $drawer = $plans->normalize($raw, $ctx("Change version 4 of the video:\n- The presenter is a man but the voice is a woman's. Keep the same presenter.\nKeep everything else as it is."), $this->workspace->id);
        $this->assertSame('Warm creator room, the approved woman with curly hair', collect($drawer['media'])->firstWhere('kind', 'reference_sheet')['description'], "the Change drawer's heading is not a request for someone new");
        $drawerNew = $plans->normalize($raw, $ctx("Change version 4 of the video:\n- Use a different presenter.\nKeep everything else as it is."), $this->workspace->id);
        $this->assertSame('Look of the whole video: near-black field, orange accents', collect($drawerNew['media'])->firstWhere('kind', 'reference_sheet')['description'], 'asked in the drawer: the new sheet');
    }

    public function test_a_change_to_part_of_a_talking_take_remakes_the_whole_take_in_one_voice(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $said = ['I used to spend a whole day on one ad.', 'Now I type what I want into WyvStudio,', 'approve the plan, and get a finished video.', 'No shoot, no editor.'];
        $before = ['kind' => 'ugc_take', 'description' => 'The approved creator in her home office, handheld selfie', 'lines' => $said];
        $raw = ['summary' => 'Fix the hook.', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 4, 'idea' => 'Talk']], 'left_out' => '',
            'narration' => ['I used to spend a whole day on one ad. WyvStudio fixed that.', ...array_slice($said, 1)],
            'media' => [['kind' => 'ugc_take', 'description' => 'Regenerate only the opening 4 s with a clean pronunciation', 'lines' => ['I used to spend a whole day on one ad. WyvStudio fixed that.']]]];
        $plan = $plans->normalize($raw, ['files' => [], 'previous_plan' => ['takes' => [$before]]], $this->workspace->id);
        $take = collect($plan['media'])->firstWhere('kind', 'ugc_take');
        $this->assertSame(['I used to spend a whole day on one ad. WyvStudio fixed that.', ...array_slice($said, 1)], $take['lines'], 'every line the take said, as the script now words it');
        $this->assertStringStartsWith('The approved creator in her home office', $take['description']);
        $this->assertSame('The talking take is re-made whole, in one voice: its speech is part of the clip', $plan['assumptions'][0]);
        $whole = $plans->normalize([...$raw, 'media' => [['kind' => 'ugc_take', 'description' => 'Same take', 'lines' => $raw['narration']]]], ['files' => [], 'previous_plan' => ['takes' => [$before]]], $this->workspace->id);
        $this->assertNotContains('The talking take is re-made whole, in one voice: its speech is part of the clip', $whole['assumptions'], 'already whole: nothing to widen');
    }

    public function test_a_take_change_brings_the_made_person_along_and_a_logo_is_never_the_presenters_photo(): void
    {
        $plans = app(\App\Services\Create\PlanService::class);
        $cast = ['kind' => 'reference_sheet', 'description' => 'Home office, the approved young creator', 'subjects' => [['name' => 'Creator', 'looks' => 'young man']], 'credits' => 70];
        $logo = ['purpose' => 'source', 'asset_id' => 9, 'asset_type' => 'image', 'title' => 'wyvstudio-logo.svg', 'notes' => ['kind' => 'logo']];
        $raw = ['summary' => 'Re-make the take.', 'scenes' => [['label' => 'Hook', 'start' => 0, 'end' => 4, 'idea' => 'Talk']], 'left_out' => '', 'narration' => ['Now I type what I want.'],
            'media' => [['kind' => 'ugc_take', 'description' => 'The whole take again', 'lines' => ['Now I type what I want.']]]];
        $plan = $plans->normalize($raw, ['files' => [$logo], 'previous_plan' => ['cast' => [$cast]], 'messages' => [['role' => 'user', 'content' => 'Say it as weave studio.']]], $this->workspace->id);
        $this->assertSame(['reference_sheet', 'ugc_take'], array_slice(array_column($plan['media'], 'kind'), 0, 2), 'the made person comes along, reused');
        $this->assertSame('Home office, the approved young creator', $plan['media'][0]['description']);
        $this->assertFalse($plan['shot_context']['has_avatar'], 'a logo is not a photo of the presenter');
        $photo = $plans->normalize($raw, ['files' => [['purpose' => 'source', 'asset_id' => 10, 'asset_type' => 'image', 'title' => 'me.jpg', 'notes' => ['kind' => 'photo']]]], $this->workspace->id);
        $this->assertTrue($photo['shot_context']['has_avatar']);
    }

    public function test_a_super_admin_can_build_one_conversation_with_another_model_at_its_own_prices(): void
    {
        $policy = ['agent' => ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'medium'], 'render' => ['provider' => 'offline']];
        $test = ['model_test' => ['model' => 'claude-haiku-5-5', 'effort' => 'xhigh']];
        $admin = new User(['role' => 'super_admin']); $owner = new User(['role' => 'owner']);
        $tried = \App\Services\Create\ConversationService::modelTest($policy, $test, $admin);
        $this->assertSame(['claude-haiku-5-5', 'xhigh', true], [$tried['agent']['model'], $tried['agent']['effort'], $tried['agent']['model_test']]);
        $this->assertSame($policy, \App\Services\Create\ConversationService::modelTest($policy, $test, $owner), 'only a super admin');
        $this->assertSame($policy, \App\Services\Create\ConversationService::modelTest($policy, ['model_test' => ['model' => 'gpt-9']], $admin), 'only a listed model');
        $this->assertSame($policy, \App\Services\Create\ConversationService::modelTest($policy, [], $admin));
        $haiku = \App\Services\Create\AnthropicGateway::rates('claude-haiku-5-5');
        $this->assertSame([0.1, 0.5, 100000], [$haiku['input'], $haiku['output'], $haiku['long_above']]);
        $this->assertSame(config('create.anthropic_rates'), \App\Services\Create\AnthropicGateway::rates('claude-opus-5-5'), 'the build tariff otherwise');
    }
    public function test_change_on_a_restored_version_uses_the_build_it_restores(): void
    {
        [$c, $revision] = $this->changeableVersion();
        $changes = app(\App\Services\Create\ChangeService::class);
        $before = $changes->parts($this->owner, $c->id, $revision);
        $restored = app(\App\Services\Create\ConversationService::class)->restore($this->owner, $c->id, $revision, (int) DB::table('create_conversations')->where('id', $c->id)->value('version'));
        $this->assertNull(DB::table('composition_revisions')->where('id', $restored)->value('run_id'), 'a restored version has no build of its own');
        $this->assertSame(DB::table('composition_revisions')->where('id', $revision)->value('run_id'), \App\Services\Create\ConversationService::revisionRunId($restored));
        $this->assertSame(array_column($before['parts'], 'id'), array_column($changes->parts($this->owner, $c->id, $restored)['parts'], 'id'), 'the same parts as the version it restores');
    }
}
