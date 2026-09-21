<?php
namespace Tests\Feature;

use App\Models\{ExportJob, Project, Scene};
use App\Services\Export\ExportFreshnessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class ExportFreshnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'freshness_test', 'database.connections.freshness_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('freshness_test');
        foreach ([
            'projects' => ['workspace_id', 'title', 'aspect_ratio', 'primary_language', 'music_asset_id', 'music_settings_json', 'visual_brief'],
            'scenes' => ['project_id', 'scene_order', 'script_text', 'visual_asset_id', 'voice_settings_json', 'caption_settings_json'],
            'export_jobs' => ['project_id', 'workspace_id', 'status', 'queued_at', 'output_asset_id'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id(); foreach ($columns as $column) $t->text($column)->nullable(); $t->timestamps();
            });
        }
        (require database_path('migrations/2026_09_22_010000_add_export_source_fingerprint.php'))->up();
    }

    private function baseline(): array
    {
        $project = Project::withoutEvents(fn () => Project::create(['workspace_id' => 1, 'title' => 'Video']));
        $scene = Scene::withoutEvents(fn () => Scene::create(['project_id' => $project->id, 'scene_order' => 1, 'script_text' => 'Hello', 'visual_asset_id' => 10]));
        $export = ExportJob::create(['project_id' => $project->id, 'workspace_id' => 1, 'status' => 'completed', 'output_asset_id' => 20, 'queued_at' => now()]);
        return [$project, $scene, $export];
    }

    public function test_snapshot_is_captured_and_edits_are_detected_even_in_same_second(): void
    {
        [$project, $scene, $export] = $this->baseline();
        $service = new ExportFreshnessService;
        $this->assertSame(64, strlen($export->source_fingerprint));
        $this->assertFalse($service->check($project, $export)['is_stale']);
        foreach (['script_text' => 'Changed', 'visual_asset_id' => 12, 'scene_order' => 2, 'caption_settings_json' => ['color' => '#fff'], 'voice_settings_json' => ['volume' => 50]] as $field => $value) {
            $original = $scene->$field;
            $scene->forceFill([$field => $value])->saveQuietly();
            $this->assertTrue($service->check($project, $export)['is_stale'], $field);
            $scene->forceFill([$field => $original])->saveQuietly();
        }
        $project->forceFill(['music_asset_id' => 30])->saveQuietly();
        $this->assertTrue($service->check($project, $export)['is_stale']);
    }

    public function test_metadata_is_ignored_and_deleted_scenes_are_detected(): void
    {
        [$project, $scene, $export] = $this->baseline();
        $service = new ExportFreshnessService;
        $project->forceFill(['title' => 'Renamed', 'visual_brief' => ['editor_opened_at' => now()->toIso8601String()]])->saveQuietly();
        $this->assertFalse($service->check($project, $export)['is_stale']);
        $scene->delete();
        $this->assertTrue($service->check($project, $export)['is_stale']);
    }

    public function test_legacy_export_uses_conservative_time_check(): void
    {
        [$project, $scene, $export] = $this->baseline();
        $export->source_fingerprint = null;
        $export->queued_at = now()->subDay();
        $result = (new ExportFreshnessService)->check($project, $export);
        $this->assertFalse($result['verified']);
        $this->assertTrue($result['is_stale']);
    }
    public function test_endpoint_scopes_workspace_and_rejects_unavailable_exports(): void
    {
        [$project, $scene, $export] = $this->baseline();
        $controller = new \App\Http\Controllers\Api\V1\Project\ProjectController(
            $this->createMock(\App\Services\WorkspaceUsageService::class),
            $this->createMock(\App\Services\CreditService::class));
        $request = \Illuminate\Http\Request::create('/freshness');
        $request->setUserResolver(fn () => (new \App\Models\User)->forceFill(['workspace_id' => 1]));
        $this->assertSame(200, $controller->exportFreshness($request, $project->id, $export->id)->getStatusCode());
        $export->forceFill(['output_asset_id' => null])->saveQuietly();
        $this->assertSame(422, $controller->exportFreshness($request, $project->id, $export->id)->getStatusCode());
        $request->setUserResolver(fn () => (new \App\Models\User)->forceFill(['workspace_id' => 99]));
        $this->assertSame(404, $controller->exportFreshness($request, $project->id, $export->id)->getStatusCode());
    }

}
