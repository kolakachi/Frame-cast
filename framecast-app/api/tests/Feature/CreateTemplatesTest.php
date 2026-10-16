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
            '2026_10_16_120000_create_create_templates_table'] as $m) (require database_path('migrations/'.$m.'.php'))->up();
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
            $sha = hash('sha256', $what);
            $path = 'create/uploads/'.$this->staff->workspace_id.'/'.Str::uuid().'/'.$sha.'.'.$ext;
            $storage->put($path, $what.'!!', ['visibility' => 'private']);
            $files[] = ['asset_id' => $asset->id, 'purpose' => 'source', 'name' => 'asset-'.$asset->id.'-'.$sha.'.'.$ext, 'sha256' => $sha, 'bytes' => 6,
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
            .'<body><img id="heroshoe" src="'.$files[0]['name'].'"><audio src="'.$files[1]['name'].'"></audio></body></html>';
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
        $this->assertSame([['id' => 'headline', 'label' => 'Hook', 'default' => 'NEW DROP', 'max' => 10]], $draft['parts']['text']);
        $this->assertSame('#FFE14D', $draft['parts']['colours'][0]['default']);
        $this->assertSame(['Archivo Black'], $draft['parts']['fonts']);
        $this->assertSame(['heroshoe', 'Ridge 2 shoe, side profile', 'ai_image'], [$draft['parts']['images'][0]['element'], $draft['parts']['images'][0]['what'], $draft['parts']['images'][0]['kind']]);
        $this->assertSame(['New drop.', 'Shop the drop.'], $draft['parts']['voice']['lines']);
        $this->assertSame('Neo-brutalism', $draft['parts']['style']);
        $this->assertSame(['1 lines of text', '1 brand colours', '1 product picture', 'Voiceover', 'Music'], $draft['summary']);
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
