<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Scene\SceneController;
use App\Http\Controllers\Api\V1\Ugc\UgcController;
use App\Jobs\GenerateAIImageJob;
use App\Jobs\GenerateTTSJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Ugc\UgcPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Deliberately uses a fresh in-memory DB, never the configured application database or paid providers. */
class UgcExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'ugc_test', 'database.connections.ugc_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('ugc_test');
        config(['services.ugc.types_live' => array_keys(\App\Services\Ugc\UgcTypes::DEFINITIONS)]);
        Bus::fake();
        Event::fake();
        Http::preventStrayRequests();
        foreach ([
            'projects' => ['workspace_id', 'created_by_user_id', 'title', 'aspect_ratio', 'duration_target_seconds', 'status', 'source_type', 'primary_language', 'source_content_raw', 'default_character_id', 'visual_brief'],
            'scenes' => ['project_id', 'scene_order', 'scene_type', 'label', 'script_text', 'duration_seconds', 'voice_settings_json', 'caption_settings_json', 'visual_type', 'visual_asset_id', 'character_id', 'visual_prompt', 'status', 'image_generation_settings_json'],
            'characters' => ['workspace_id', 'name', 'status', 'is_stock', 'reference_asset_id', 'gender'],
            'assets' => ['workspace_id', 'asset_type', 'storage_url'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) {
                    $t->text($column)->nullable();
                }
                $t->timestamps();
            });
        }
        Schema::create('workspaces', function (Blueprint $t) { $t->id(); $t->timestamps(); });
        DB::table('workspaces')->insert(['id' => 1]);
        (require database_path('migrations/2026_09_18_120000_create_ugc_run_requests.php'))->up();
        Character::query()->create(['workspace_id' => 1, 'name' => 'Creator', 'status' => 'active', 'is_stock' => false, 'reference_asset_id' => 10, 'gender' => 'female']);
        $reference = new Asset(['workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'test/creator.png']);
        $reference->id = 10;
        $reference->save();
        $credits = $this->createMock(CreditService::class);
        $credits->method('balance')->willReturn(100000);
        $credits->method('limitFor')->willReturnCallback(fn ($id, $key) => in_array($key, ['ugc_ads', 'custom_characters'], true) ? true : null);
        $this->app->instance(CreditService::class, $credits);
    }

    private function request(array $shots, string $format, array $changes = []): Request
    {
        $normal = UgcPlan::normalise($shots, $format);
        $request = Request::create('/ugc/generate', 'POST', array_replace([
            'format' => $format, 'segments' => $shots, 'script' => UgcPlan::script($normal),
            'character_ids' => [1], 'aspect_ratio' => '9:16', 'consent' => true, 'reviewed' => true,
            'credits_per_character' => UgcPlan::quote($normal),
        ], $changes));
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 1, 'workspace_id' => 1]));

        return $request;
    }

    private function shot(array $changes = []): array
    {
        return array_replace(['kind' => 'on_camera', 'script_text' => 'Here is the idea.', 'seconds' => 5,
            'visual_brief' => 'Kitchen selfie, casual sweater, window light.', 'headline' => 'One useful idea',
            'voice_direction' => 'Warm and curious.', 'motion_prompt' => '', 'source' => null,
            'speed' => 1.0], $changes);
    }

    public function test_free_cannot_generate_and_starter_cannot_use_custom_cast(): void
    {
        foreach ([false, true] as $paid) {
            $credits = $this->createMock(CreditService::class);
            $credits->method('limitFor')->willReturnCallback(fn ($id, $key) => $key === 'ugc_ads' ? $paid : false);
            $this->app->instance(CreditService::class, $credits);
            try {
                $response = (new UgcController)->generate($this->request([$this->shot()], 'direct_camera'));
                $this->assertFalse($paid);
                $this->assertSame(402, $response->status());
            } catch (ValidationException $e) {
                $this->assertTrue($paid);
                $this->assertArrayHasKey('character_ids', $e->errors());
            }
        }
        $this->assertSame(0, Project::count());
        Bus::assertNothingDispatched();
    }

    public function test_replayed_submission_returns_the_same_run_without_more_jobs(): void
    {
        $request = $this->request([$this->shot()], 'direct_camera', ['request_id' => '86ebc83e-7eec-4b8f-bc12-cbd854073521']);
        $first = (new UgcController)->generate($request);
        $second = (new UgcController)->generate($request);
        $this->assertSame(201, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Project::count());
        Bus::assertDispatchedTimes(GenerateAIImageJob::class, 1);
    }

    public function test_reused_submission_key_with_changed_plan_is_rejected(): void
    {
        $changes = ['request_id' => '86ebc83e-7eec-4b8f-bc12-cbd854073521'];
        (new UgcController)->generate($this->request([$this->shot()], 'direct_camera', $changes));
        $response = (new UgcController)->generate($this->request([$this->shot()], 'direct_camera', $changes + ['title' => 'Changed']));
        $this->assertSame(409, $response->status());
        $this->assertSame(1, Project::count());
    }

    public function test_missing_variant_asset_prevents_the_entire_run(): void
    {
        try {
            (new UgcController)->generate($this->request([$this->shot()], 'demo', ['variants' => [
                ['label' => 'Alternative', 'segments' => [$this->shot(['kind' => 'b_roll', 'source' => 'upload', 'asset_id' => 999])]],
            ]]));
            $this->fail('Missing variant footage must fail before any take starts.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('variants.0.segments.0.asset_id', $e->errors());
        }
        $this->assertSame(0, Project::count());
        $this->assertSame(0, DB::table('ugc_run_requests')->count());
        Bus::assertNothingDispatched();
    }

    public function test_variant_asset_is_attached_to_its_scene(): void
    {
        $asset = Asset::create(['workspace_id' => 1, 'asset_type' => 'video', 'storage_url' => 'test/variant.mp4']);
        (new UgcController)->generate($this->request([$this->shot()], 'demo', ['variants' => [
            ['label' => 'Alternative', 'segments' => [$this->shot(['kind' => 'b_roll', 'source' => 'upload', 'asset_id' => $asset->id])]],
        ]]));
        $this->assertSame(2, Project::count());
        $this->assertSame($asset->id, (int) Scene::orderByDesc('id')->first()->visual_asset_id);
    }

    public function test_monthly_cap_rejects_a_second_run_but_allows_replaying_the_first(): void
    {
        $credits = $this->createMock(CreditService::class);
        $credits->method('balance')->willReturn(100000);
        $credits->method('limitFor')->willReturn(1);
        $this->app->instance(CreditService::class, $credits);
        $request = $this->request([$this->shot()], 'direct_camera', ['request_id' => '86ebc83e-7eec-4b8f-bc12-cbd854073521']);
        (new UgcController)->generate($request);
        $this->assertSame(200, (new UgcController)->generate($request)->status());
        try {
            (new UgcController)->generate($this->request([$this->shot()], 'direct_camera'));
            $this->fail('The second run should exceed the monthly cap.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('takes', $e->errors());
        }
        $this->assertSame(1, Project::count());
    }

    public function test_failed_take_reports_other_unfinished_scenes_as_working(): void
    {
        (new UgcController)->generate($this->request([$this->shot(), $this->shot()], 'story'));
        $scene = Scene::orderBy('id')->firstOrFail();
        $scene->forceFill(['image_generation_settings_json' => ['last_error' => 'Provider failed']])->save();
        $request = Request::create('/ugc/takes', 'GET', ['detail' => true]);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 1, 'workspace_id' => 1]));
        $take = (new UgcController)->takes($request)->getData(true)['data']['takes'][0];
        $this->assertSame('needs_attention', $take['status']);
        $this->assertTrue($take['working']);
    }

    public function test_reaction_dispatches_directed_animation_chain_without_tts(): void
    {
        $shot = $this->shot(['kind' => 'reaction', 'script_text' => '', 'motion_prompt' => 'Look concerned, then smile.']);
        $response = (new UgcController)->generate($this->request([$shot], 'reaction'));
        $this->assertSame(201, $response->status());
        Bus::assertNotDispatched(GenerateTTSJob::class);
        Bus::assertDispatched(GenerateAIImageJob::class, fn ($job) => $job->chainAnimateAfterSeconds === 5
            && $job->chainAnimateTier === 'quick' && str_contains($job->chainAnimateMotionPrompt, 'then smile') && $job->afterCommit);
        $scene = Scene::query()->firstOrFail();
        $this->assertFalse($scene->caption_settings_json['enabled']);
        $this->assertSame('', $scene->script_text);
        $this->assertNotEmpty($scene->caption_settings_json['ugc_headline']['lines']);
        $this->assertSame('reaction', $scene->image_generation_settings_json['ugc_kind']);
        $this->assertStringContainsString('Kitchen selfie', $scene->visual_prompt);
    }

    public function test_one_shot_rejects_reused_footage_before_creating_or_dispatching(): void
    {
        $credits = $this->createMock(CreditService::class);
        $credits->method('limitFor')->willReturn(true);
        $this->app->instance(CreditService::class, $credits);
        foreach (['upload', 'stock'] as $source) {
            $request = $this->request([
                $this->shot(['kind' => 'b_roll', 'source' => $source, 'asset_id' => 10]),
            ], 'demo', ['credits' => 165]);
            try {
                (new UgcController)->generateOneShot($request, $credits);
                $this->fail('A one-shot request must not discard selected footage.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('segments', $e->errors());
                $this->assertStringContainsString('shot by shot', $e->errors()['segments'][0]);
            }
        }
        $this->assertSame(0, Project::count());
        Bus::assertNothingDispatched();
    }

    public function test_uploaded_cutaway_uses_real_asset_and_never_dispatches_an_image(): void
    {
        $asset = Asset::query()->create(['workspace_id' => 1, 'asset_type' => 'video', 'storage_url' => 'test/screen.mp4']);
        $cutaway = $this->shot(['kind' => 'b_roll', 'source' => 'upload', 'asset_id' => $asset->id]);
        (new UgcController)->generate($this->request([$cutaway, $this->shot()], 'demo'));
        $scene = Scene::query()->orderBy('scene_order')->firstOrFail();
        $this->assertSame($asset->id, (int) $scene->visual_asset_id);
        $this->assertSame('video', $scene->visual_type);
        Bus::assertDispatchedTimes(GenerateAIImageJob::class, 1);
        Bus::assertDispatched(GenerateAIImageJob::class, fn ($job) => $job->sceneId !== $scene->id);
        Bus::assertDispatchedTimes(GenerateTTSJob::class, 1);
    }

    public function test_missing_footage_stops_before_creating_projects_or_jobs(): void
    {
        try {
            (new UgcController)->generate($this->request([$this->shot(['kind' => 'b_roll', 'source' => 'stock'])], 'demo'));
            $this->fail('Missing media must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('segments.0.asset_id', $e->errors());
        }
        $this->assertSame(0, Project::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_another_workspaces_asset_is_rejected(): void
    {
        $asset = Asset::query()->create(['workspace_id' => 2, 'asset_type' => 'video', 'storage_url' => 'private.mp4']);
        $this->expectException(ValidationException::class);
        (new UgcController)->generate($this->request([$this->shot(['kind' => 'b_roll', 'source' => 'upload', 'asset_id' => $asset->id])], 'demo'));
    }

    public function test_stale_script_and_stale_cost_are_rejected(): void
    {
        foreach ([['script' => 'Old words'], ['credits_per_character' => 0], ['reviewed' => false], ['consent' => false]] as $change) {
            try {
                (new UgcController)->generate($this->request([$this->shot()], 'direct_camera', $change));
                $this->fail('Unreviewed or stale requests must fail.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(0, Project::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_pace_reaches_the_voice_settings(): void
    {
        (new UgcController)->generate($this->request([$this->shot(['speed' => 1.35])], 'direct_camera'));

        $this->assertEqualsWithDelta(1.35, Scene::query()->firstOrFail()->voice_settings_json['speed'], 0.001);
    }

    public function test_pace_defaults_to_natural_when_not_set(): void
    {
        (new UgcController)->generate($this->request([$this->shot()], 'direct_camera'));

        $this->assertEqualsWithDelta(1.0, Scene::query()->firstOrFail()->voice_settings_json['speed'], 0.001);
    }

    public function test_an_out_of_range_pace_is_clamped_rather_than_refused(): void
    {
        // A slider that rejects the value it just produced is worse than one
        // that stays inside what the voice can actually do.
        $normal = UgcPlan::normalise([$this->shot(['speed' => 9])], 'direct_camera');
        $this->assertEqualsWithDelta(2.0, $normal[0]['speed'], 0.001);

        $slow = UgcPlan::normalise([$this->shot(['speed' => 0.01])], 'direct_camera');
        $this->assertEqualsWithDelta(0.5, $slow[0]['speed'], 0.001);
    }

    public function test_talking_take_preserves_voice_and_camera_direction(): void
    {
        (new UgcController)->generate($this->request([$this->shot()], 'direct_camera', ['voice_key' => 'Kore']));
        $scene = Scene::query()->firstOrFail();
        $this->assertSame('Kore', $scene->voice_settings_json['voice_id']);
        $this->assertSame('Warm and curious.', $scene->voice_settings_json['voice_prompt']);
        $this->assertTrue($scene->image_generation_settings_json['planned_spokesperson']);
        Bus::assertDispatched(GenerateAIImageJob::class, fn ($job) => $job->chainAnimateAfterSeconds === null);
    }

    public function test_take_list_is_workspace_scoped_and_waits_for_video_not_just_a_still(): void
    {
        $request = $this->request([$this->shot()], 'direct_camera');
        $controller = new UgcController;
        $controller->generate($request);
        Project::query()->create(['workspace_id' => 2, 'title' => 'Private take', 'visual_brief' => ['ugc_format' => 'reaction']]);
        $data = $controller->takes($request)->getData(true)['data']['takes'];
        $this->assertCount(1, $data);
        $this->assertSame('generating', $data[0]['status']);
        $scene = Scene::query()->firstOrFail();
        $scene->update(['visual_asset_id' => 10, 'image_generation_settings_json' => ['ugc_kind' => 'on_camera'],
            'voice_settings_json' => ['audio_asset_id' => 11]]);
        $this->assertSame('generating', $controller->takes($request)->getData(true)['data']['takes'][0]['status']);
        $scene->update(['image_generation_settings_json' => ['ugc_kind' => 'on_camera', 'animation_video_asset_id' => 12]]);
        $this->assertSame('ready_for_review', $controller->takes($request)->getData(true)['data']['takes'][0]['status']);
    }

    public function test_ordinary_caption_edits_preserve_headline_and_explicit_edits_rebuild_layout(): void
    {
        $request = $this->request([$this->shot()], 'direct_camera');
        (new UgcController)->generate($request);
        $scene = Scene::query()->firstOrFail();
        $controller = app(SceneController::class);
        $edit = Request::create('/scenes/'.$scene->id, 'PATCH', ['caption_settings_json' => ['enabled' => false]]);
        $edit->setUserResolver($request->getUserResolver());
        $controller->update($edit, $scene->id);
        $this->assertSame('One useful idea', $scene->fresh()->caption_settings_json['ugc_headline']['text']);
        $edit->replace(['caption_settings_json' => ['enabled' => false, 'ugc_headline' => ['text' => 'New headline', 'lines' => ['UNTRUSTED LAYOUT']]]]);
        $controller->update($edit, $scene->id);
        $this->assertSame(['New headline'], $scene->fresh()->caption_settings_json['ugc_headline']['lines']);
        Bus::assertDispatchedTimes(GenerateAIImageJob::class, 1); // Only the initial generation.
    }

    public function test_a_product_photo_is_composited_onto_the_talking_actor(): void
    {
        $product = Asset::query()->create(['workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'test/jar.png']);

        (new UgcController)->generate($this->request(
            [$this->shot()], 'direct_camera', ['product_asset_id' => $product->id],
        ));

        // Actor first, product second — order carries the roles for the adapter.
        Bus::assertDispatched(GenerateAIImageJob::class, fn ($job) => $job->referenceAssetIds === [10, $product->id]);

        $scene = Scene::query()->firstOrFail();
        $settings = $scene->image_generation_settings_json;
        $settings = is_array($settings) ? $settings : (json_decode((string) $settings, true) ?: []);
        $this->assertSame([10, $product->id], $settings['reference_asset_ids']);
    }

    public function test_a_product_photo_from_another_workspace_is_refused(): void
    {
        $foreign = Asset::query()->create(['workspace_id' => 2, 'asset_type' => 'image', 'storage_url' => 'test/theirs.png']);

        $response = (new UgcController)->generate($this->request(
            [$this->shot()], 'direct_camera', ['product_asset_id' => $foreign->id],
        ));

        $this->assertSame(422, $response->getStatusCode());
        Bus::assertNothingDispatched();
    }

    public function test_without_a_product_the_actor_reference_is_unchanged(): void
    {
        (new UgcController)->generate($this->request([$this->shot()], 'direct_camera'));

        Bus::assertDispatched(GenerateAIImageJob::class, fn ($job) => $job->referenceAssetIds === []);
    }
    public function test_pass_rejects_an_overlength_variant_before_reserving(): void
    {
        $credits = $this->createMock(CreditService::class);
        $credits->method('planTier')->willReturn('ugc_pass');
        $credits->method('limitFor')->willReturnCallback(fn ($id, $key) => in_array($key, ['ugc_ads', 'custom_characters'], true) ? true : 2);
        $this->app->instance(CreditService::class, $credits);
        try {
            (new UgcController)->generate($this->request([$this->shot()], 'direct_camera', [
                'variants' => [['label' => 'Too long', 'segments' => [$this->shot(['seconds' => 20])]]],
            ]));
            $this->fail('The variant must obey the same duration limit as the main take');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('variants.0.segments', $e->errors());
            $this->assertSame(0, Project::count());
        }
    }

    public function test_pass_multiscene_replacement_uses_reservations_not_monthly_projects(): void
    {
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('project_id')->nullable();
            $t->string('operation'); $t->integer('credits'); $t->integer('balance_after');
            $t->json('metadata')->nullable(); $t->timestamps();
        });
        $credits = $this->createMock(CreditService::class);
        $credits->method('planTier')->willReturn('ugc_pass');
        $credits->method('balance')->willReturn(600);
        $credits->method('limitFor')->willReturnCallback(fn ($id, $key) => in_array($key, ['ugc_ads', 'custom_characters'], true) ? true : 2);
        $this->app->instance(CreditService::class, $credits);
        $controller = new UgcController;
        $shot = $this->shot(['kind' => 'b_roll', 'source' => 'generate', 'script_text' => '']);
        $request = $this->request([$shot], 'demo', [
            'character_ids' => [1], 'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'variants' => [['label' => 'Second', 'segments' => [$shot]]],
        ]);
        $this->assertSame(201, $controller->generate($request)->status());
        $this->assertSame(2, $controller::passTakesUsed(1));
        $this->assertSame(2, DB::table('credit_ledger')->whereNotNull('project_id')->count());
        $this->assertSame(200, $controller->generate($request)->status());
        $this->assertSame(2, $controller::passTakesUsed(1));
        app(\App\Services\UgcPassTakeService::class)->releaseProject((int) Project::first()->id);
        $replacement = $this->request([$shot], 'demo', [
            'character_ids' => [1], 'request_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->assertSame(201, $controller->generate($replacement)->status());
        $this->assertSame(3, Project::count());
        $this->assertSame(2, $controller::passTakesUsed(1));
    }

    public function test_one_shot_creation_failure_returns_the_reserved_take(): void
    {
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('project_id')->nullable();
            $t->string('operation'); $t->integer('credits'); $t->integer('balance_after');
            $t->json('metadata')->nullable(); $t->timestamps();
        });
        Schema::create('client_profiles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->text('brief')->nullable();
        });
        $credits = $this->createMock(CreditService::class);
        $credits->method('planTier')->willReturn('ugc_pass');
        $credits->method('balance')->willReturn(600);
        $credits->method('limitFor')->willReturnCallback(fn ($id, $key) => $key === 'ugc_ads' ? true : 2);
        $this->app->instance(CreditService::class, $credits);
        $request = $this->request([$this->shot()], 'direct_camera', [
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'credits' => 5 * CreditService::VIDEO_ONESHOT_PER_SECOND['seedance25'],
        ]);
        Schema::drop('projects'); // Inject a database failure after reservation, before dispatch.
        try {
            (new UgcController)->generateOneShot($request, $credits);
            $this->fail('Expected the injected project insert failure');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('projects', $e->getMessage());
        }
        $this->assertSame(1, DB::table('credit_ledger')->where('operation', 'ugc_pass_take')->count());
        $this->assertSame(0, UgcController::passTakesUsed(1));
        $this->assertSame(0, DB::table('ugc_run_requests')->count());
        Bus::assertNothingDispatched();
    }

    public function test_native_types_dispatch_their_composition_cast_and_silent_media(): void
    {
        Schema::create('client_profiles', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->text('brief')->nullable(); });
        Schema::table('assets', function (Blueprint $t) { $t->string('mime_type')->nullable(); });
        DB::table('assets')->where('id', 10)->update(['mime_type' => 'image/png']);
        DB::table('assets')->insert(['id' => 11, 'workspace_id' => 1, 'asset_type' => 'video', 'storage_url' => 'source.mp4', 'mime_type' => 'video/mp4']);
        Character::query()->create(['workspace_id' => 1, 'name' => 'Second speaker', 'status' => 'active', 'is_stock' => false, 'reference_asset_id' => 10]);
        $storage = $this->createMock(\App\Services\Media\StorageService::class);
        $storage->method('readStream')->willReturnCallback(function () { $stream = fopen('php://temp', 'r+'); fwrite($stream, 'image-bytes'); rewind($stream); return $stream; });
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        foreach (['asmr', 'green_screen', 'skit'] as $type) {
            $shots = [$this->shot()];
            $extra = ['ugc_type' => $type, 'character_id' => 1, 'engine' => 'omni'];
            $format = $type === 'green_screen' ? 'direct_camera' : 'story';
            if ($type === 'asmr') $shots = [$this->shot(['kind' => 'b_roll', 'source' => 'upload', 'asset_id' => 11, 'script_text' => '', 'motion_prompt' => 'Listen to the pouring.'])];
            if ($type === 'green_screen') $extra['composition'] = ['layout' => 'green_screen', 'background_asset_id' => 10];
            if ($type === 'skit') {
                $shots = [$this->shot(['speaker' => 1]), $this->shot(['speaker' => 2])];
                $extra['cast_character_ids'] = [1, 2];
            }
            // Billed for the seconds generated (#4); the ASMR take only replays uploaded footage, so it is free.
            $segments = \App\Services\Ugc\UgcPlan::normalise($shots, $format);
            $extra['credits'] = (int) (\App\Services\Ugc\UgcOneShotPricing::quoteSeconds(\App\Services\Ugc\UgcOneShotCompiler::compile($segments, ['ugc_type' => $type], \App\Services\Ugc\UgcOneShotCompiler::OMNI_SEGMENT_SECONDS), $segments, 'omni') * CreditService::VIDEO_ONESHOT_PER_SECOND['omni']);
            $response = (new UgcController)->generateOneShot($this->request($shots, $format, $extra), app(CreditService::class));
            $this->assertSame(201, $response->getStatusCode(), $type.' '.json_encode($response->getData(true)));
            Bus::assertDispatched(\App\Jobs\GenerateOneShotUgcJob::class, function ($job) use ($type) {
                if ($type === 'asmr') return ($job->chunks[0]['source_media']['url'] ?? '') === 'source.mp4' && $job->chunks[0]['requires_audio'] && $job->chunks[0]['dialogue'] === '';
                if ($type === 'green_screen') return ($job->composition['assets'][0]['id'] ?? null) === 10;
                // Each speaker's photos travel as ids, read by the job (#7), never inline copies.
                return count($job->chunks) === 2 && ($job->chunks[0]['reference_asset_ids'][0] ?? null) === 10 && ! empty($job->chunks[1]['reference_asset_ids'])
                    && empty($job->chunks[0]['reference_images']);
            });
        }
    }

    public function test_veo_fast_never_makes_a_take_with_a_presenter(): void
    {
        $credits = app(CreditService::class);
        $user = (new User)->forceFill(['id' => 1, 'workspace_id' => 1]);
        $talking = UgcPlan::normalise([$this->shot()], 'direct_camera');
        // Asked for by name, with a presenter on camera: refused, with the reason.
        try {
            \App\Services\Ugc\UgcOneShotPricing::price($user, ['engine' => 'veo', 'character_id' => 1], $talking, $credits);
            $this->fail('Veo 3.1 Fast must refuse a take with a presenter');
        } catch (ValidationException $e) {
            $this->assertSame(\App\Services\Ugc\UgcOneShotPricing::VEO_PRESENTER, $e->errors()['engine'][0]);
        }
        // Too long for one Seedance take: a presenter goes to Omni (references), not Veo Fast (a start frame).
        $long = UgcPlan::normalise([$this->shot(['seconds' => 35, 'script_text' => str_repeat('Here is one more useful idea for you today. ', 12)])], 'direct_camera');
        $this->assertSame('omni', \App\Services\Ugc\UgcOneShotPricing::price($user, ['engine' => 'seedance25', 'character_id' => 1], $long, $credits)['engine']);
        // Nobody on camera (hands and product only): Veo Fast is still a choice.
        $this->assertFalse(\App\Services\Ugc\UgcOneShotPricing::hasPresenter([], [['kind' => 'b_roll', 'source' => 'generate']]));
        $this->assertTrue(\App\Services\Ugc\UgcOneShotPricing::hasPresenter([], $talking));
    }

    public function test_composition_assets_cannot_cross_workspaces(): void
    {
        DB::table('assets')->insert(['id' => 20, 'workspace_id' => 2, 'asset_type' => 'image', 'storage_url' => 'private.png']);
        $this->expectException(ValidationException::class);
        \App\Services\Ugc\UgcComposition::resolve((new User)->forceFill(['workspace_id' => 1]), ['layout' => 'split', 'left_asset_id' => 10, 'right_asset_id' => 20]);
    }

}
