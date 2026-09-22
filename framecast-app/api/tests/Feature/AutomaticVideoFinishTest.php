<?php

namespace Tests\Feature;

use App\Jobs\FinishGeneratedVideoJob;
use App\Jobs\ProcessExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Export\ProjectExportService;
use App\Services\WorkspaceUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Event, Http, Redis, Schema};
use Tests\TestCase;

class AutomaticVideoFinishTest extends TestCase
{
    private ProjectExportService $exports;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['database.default' => 'finish_test', 'database.connections.finish_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('finish_test');
        Bus::fake();
        Event::fake([\App\Events\ExportProgressed::class]);
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);
        foreach ([
            'projects' => ['workspace_id', 'created_by_user_id', 'title', 'aspect_ratio', 'primary_language', 'status', 'source_type', 'visual_brief', 'generation_status_json', 'music_asset_id'],
            'scenes' => ['project_id', 'scene_order', 'script_text', 'visual_type', 'visual_asset_id', 'voice_settings_json', 'caption_settings_json', 'image_generation_settings_json'],
            'users' => ['workspace_id'],
            'workspaces' => ['plan_tier', 'plan_source', 'plan_status', 'status'],
            'assets' => ['workspace_id', 'asset_type', 'storage_url'],
            'export_jobs' => ['workspace_id', 'project_id', 'variant_id', 'aspect_ratio', 'language', 'file_name', 'watermark_enabled', 'status', 'progress_percent', 'priority', 'failure_reason', 'output_asset_id', 'queued_at', 'started_at', 'completed_at'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) $t->text($column)->nullable();
                $t->timestamps();
            });
        }
        DB::table('projects')->insert(['id' => 215, 'workspace_id' => 1, 'status' => 'ready_for_review', 'source_type' => 'script']);
        DB::table('workspaces')->insert(['id' => 1, 'plan_tier' => 'free']);
        DB::table('users')->insert(['id' => 1, 'workspace_id' => 99]);
        DB::table('assets')->insert([['id'=>10,'workspace_id'=>1,'asset_type'=>'image'], ['id'=>11,'workspace_id'=>1,'asset_type'=>'audio'], ['id'=>99,'workspace_id'=>1,'asset_type'=>'audio']]);
        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('hasReachedExportLimit')->willReturn(false);
        $this->exports = new ProjectExportService($usage);
    }

    private function project(array $settings = [], array $brief = []): Project
    {
        $project = Project::withoutEvents(fn () => Project::create([
            'workspace_id' => 1, 'created_by_user_id' => 1, 'status' => 'ready_for_review',
            'source_type' => 'prompt', 'title' => 'My video', 'visual_brief' => $brief,
        ]));
        Scene::create(['project_id' => $project->id, 'scene_order' => 1, 'script_text' => 'Hello',
            'visual_type' => 'ai_image', 'visual_asset_id' => 10,
            'voice_settings_json' => ['audio_asset_id' => 11], 'image_generation_settings_json' => $settings]);
        return $project;
    }

    public function test_legacy_projects_are_untouched_even_by_already_queued_jobs(): void
    {
        $project = Project::findOrFail(215);
        $this->assertFalse($project->usesAutomaticFinish());
        $this->assertNull($this->exports->finishInitial($project));
        $job = new FinishGeneratedVideoJob(215);
        $job->handle($this->exports);
        $job->failed(new \RuntimeException('timeout'));
        $project->update(['status' => 'generating']);
        $project->update(['status' => 'ready_for_review']);
        Bus::assertNotDispatched(FinishGeneratedVideoJob::class);
        Bus::assertNotDispatched(ProcessExportJob::class);
        $this->assertSame(0, DB::table('export_jobs')->count());
    }

    public function test_initial_export_is_idempotent_and_watermarked_on_free(): void
    {
        $project = $this->project();
        $this->assertSame(216, $project->id);
        $first = $this->exports->finishInitial($project);
        $again = $this->exports->finishInitial($project);
        $this->assertSame($first->id, $again->id);
        $this->assertTrue($first->watermark_enabled);
        $this->assertSame('queued', $first->status);
        Bus::assertDispatchedTimes(ProcessExportJob::class, 1);
    }

    public function test_waits_for_visual_animation_and_music_then_queues(): void
    {
        $project = $this->project(['in_progress' => true, 'auto_animate' => true, 'include_music' => true]);
        $this->assertNull($this->exports->finishInitial($project));
        $scene = $project->scenes()->first();
        $scene->update(['image_generation_settings_json' => ['auto_animate' => true, 'include_music' => true]]);
        $this->assertNull($this->exports->finishInitial($project));
        $scene->update(['image_generation_settings_json' => ['auto_animate' => true, 'animation_video_asset_id' => 12, 'include_music' => true]]);
        $this->assertNull($this->exports->finishInitial($project));
        $project->update(['music_asset_id' => 13]);
        $this->assertSame('queued', $this->exports->finishInitial($project)->status);
    }

    public function test_silent_cards_are_exportable(): void
    {
        $project = $this->project();
        $project->scenes()->first()->update(['script_text' => '', 'voice_settings_json' => []]);
        $this->assertSame('queued', $this->exports->finishInitial($project)->status);
    }

    public function test_failed_visual_does_not_render_a_partial_video_or_retry_forever(): void
    {
        $project = $this->project(['auto_animate' => true, 'last_error' => 'provider failed']);
        $failed = $this->exports->finishInitial($project);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('needs attention', $failed->failure_reason);
        $this->assertSame($failed->id, $this->exports->finishInitial($project)->id);
        Bus::assertNotDispatched(ProcessExportJob::class);
    }

    public function test_whole_video_keeps_native_audio_without_rendering(): void
    {
        DB::table('workspaces')->where('id', 1)->update(['plan_tier' => 'creator', 'plan_source' => 'manual']);
        DB::table('assets')->where('id', 10)->update(['asset_type' => 'video', 'storage_url' => 'native.mp4']);
        $project = $this->project([], ['ugc_format' => 'one_shot']);
        $project->scenes()->first()->update(['voice_settings_json' => []]);
        $job = $this->exports->finishInitial($project);
        $this->assertSame('completed', $job->status);
        $this->assertSame(10, (int) $job->output_asset_id);
        Bus::assertNotDispatched(ProcessExportJob::class);
    }

    public function test_limit_failure_uses_project_workspace_even_after_creator_switches(): void
    {
        $project = $this->project();
        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->expects($this->once())->method('hasReachedExportLimit')->with($this->callback(fn ($user) => (int) $user->workspace_id === 1))->willReturn(true);
        $usage->method('exportLimitContext')->willReturn(['used' => 10, 'limit' => 10, 'plan' => 'Free']);
        $job = (new ProjectExportService($usage))->finishInitial($project);
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('10 of 10 exports', $job->failure_reason);
        Bus::assertNotDispatched(ProcessExportJob::class);
    }

    public function test_pending_exports_reserve_the_remaining_allowance(): void
    {
        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('hasReachedExportLimit')->willReturn(false);
        $usage->method('exportsRemaining')->willReturn(1);
        $exports = new ProjectExportService($usage);
        $this->assertSame('queued', $exports->finishInitial($this->project())->status);
        $this->assertSame('failed', $exports->finishInitial($this->project())->status);
        Bus::assertDispatchedTimes(ProcessExportJob::class, 1);
    }

    public function test_music_in_flight_blocks_even_if_the_scene_has_no_music_flag(): void
    {
        $project = $this->project();
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\GenerateAIMusicJob::inFlightKey($project->id), true, 60);
        $this->assertNull($this->exports->finishInitial($project));
        Bus::assertNotDispatched(ProcessExportJob::class);
    }

    public function test_video_download_streams_an_attachment_instead_of_redirecting_to_a_player(): void
    {
        DB::table('assets')->insert(['id' => 12, 'asset_type' => 'video', 'storage_url' => 'minio://video.mp4']);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'video bytes');
        rewind($stream);
        $storage = $this->createMock(\App\Services\Media\StorageService::class);
        $storage->method('extractPath')->willReturn('video.mp4');
        $storage->method('readStream')->willReturn($stream);
        $storage->method('size')->willReturn(11);
        $storage->expects($this->never())->method('url');
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $response = (new \App\Http\Controllers\Api\V1\Asset\AssetController)->content(
            \Illuminate\Http\Request::create('/media/assets/10?download=1'), 10);
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $this->assertSame('video bytes', ob_get_clean());
    }

    public function test_background_worker_waits_then_finishes_without_a_browser(): void
    {
        $project = $this->project(['in_progress' => true]);
        $queued = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('release')->once()->with(30);
        $job = new FinishGeneratedVideoJob($project->id);
        $job->setJob($queued);
        $job->handle($this->exports);
        Bus::assertNotDispatched(ProcessExportJob::class);
        $project->scenes()->first()->update(['image_generation_settings_json' => []]);
        $job->handle($this->exports);
        Bus::assertDispatchedTimes(ProcessExportJob::class, 1);
    }

    public function test_exhausted_finishing_worker_leaves_one_retryable_failure(): void
    {
        $project = $this->project(['in_progress' => true]);
        $job = new FinishGeneratedVideoJob($project->id);
        $job->failed(new \RuntimeException('timeout'));
        $job->failed(new \RuntimeException('timeout'));
        $this->assertSame(1, DB::table('export_jobs')->count());
        $this->assertSame('failed', DB::table('export_jobs')->value('status'));
    }

    public function test_editor_choice_is_persisted_without_overwriting_the_brief(): void
    {
        $project = $this->project([], ['ugc_format' => 'demo', 'product' => 'Keep this']);
        $controller = new \App\Http\Controllers\Api\V1\Project\ProjectController(
            $this->createMock(WorkspaceUsageService::class), $this->createMock(\App\Services\CreditService::class));
        $request = \Illuminate\Http\Request::create('/projects/'.$project->id.'/editor-opened', 'POST');
        $request->setUserResolver(fn () => (new \App\Models\User)->forceFill(['workspace_id' => 1]));
        $this->assertSame(200, $controller->editorOpened($request, $project->id)->getStatusCode());
        $brief = $project->fresh()->visual_brief;
        $this->assertNotEmpty($brief['editor_opened_at']);
        $this->assertSame('Keep this', $brief['product']);
        $controller->editorOpened($request, $project->id);
        $this->assertSame($brief, $project->fresh()->visual_brief);
        $request->setUserResolver(fn () => (new \App\Models\User)->forceFill(['workspace_id' => 99]));
        $this->assertSame(404, $controller->editorOpened($request, $project->id)->getStatusCode());
    }

    public function test_revisions_do_not_start_an_initial_render(): void
    {
        $project = $this->project([], ['ugc_revision_at' => now()->toIso8601String()]);
        $this->assertNull($this->exports->finishInitial($project));
        Bus::assertNotDispatched(ProcessExportJob::class);
    }

    public function test_new_generation_schedules_finishing_but_blank_projects_do_not(): void
    {
        Project::create(['workspace_id' => 1, 'status' => 'generating', 'source_type' => 'prompt']);
        Project::create(['workspace_id' => 1, 'status' => 'ready_for_review', 'source_type' => 'blank']);
        Bus::assertDispatchedTimes(FinishGeneratedVideoJob::class, 1);
    }
}
