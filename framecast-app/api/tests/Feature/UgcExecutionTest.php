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
        Character::query()->create(['workspace_id' => 1, 'name' => 'Creator', 'status' => 'active', 'is_stock' => false, 'reference_asset_id' => 10, 'gender' => 'female']);
        $reference = new Asset(['workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'test/creator.png']);
        $reference->id = 10;
        $reference->save();
        $credits = $this->createMock(CreditService::class);
        $credits->method('balance')->willReturn(100000);
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
            'voice_direction' => 'Warm and curious.', 'motion_prompt' => '', 'source' => null], $changes);
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
}
