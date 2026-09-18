<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController;
use App\Models\Project;
use App\Models\User;
use App\Services\CreditService;
use App\Services\CruiseControl\{CruiseActionRunService, CruiseControlService, CruiseToolRegistry, ProjectBriefService};
use App\Services\CruiseControl\Tools\CruiseTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class UgcRevisionApprovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'revision_test', 'database.connections.revision_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('revision_test');
        foreach (['workspaces' => [], 'projects' => ['workspace_id', 'visual_brief'], 'export_jobs' => ['project_id'],
            'cruise_audit_logs' => ['workspace_id', 'user_id', 'project_id', 'phase', 'resolved_tool', 'resolved_params', 'applied', 'credits_spent', 'outcome', 'error_message']] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) $t->text($column)->nullable();
                $t->timestamps();
            });
        }
        DB::table('workspaces')->insert(['id' => 1]);
    }

    private function apply(string $name, int $actualCost, int $approvedCost, bool $shouldExecute): array
    {
        $project = Project::create(['workspace_id' => 1, 'visual_brief' => ['ugc_format' => 'demo', 'keep' => 'existing metadata']]);
        DB::table('export_jobs')->insert(['id' => 9, 'project_id' => $project->id]);
        $tool = $this->createMock(CruiseTool::class);
        $tool->method('estimateCost')->willReturn($actualCost);
        $tool->expects($shouldExecute ? $this->once() : $this->never())->method('execute')->willReturn(['summary' => 'Changed', 'credits_spent' => $actualCost]);
        $tool->method('affectedSection')->willReturn('visual');
        $registry = $this->createMock(CruiseToolRegistry::class);
        $registry->method('get')->willReturn($tool);
        $credits = $this->createMock(CreditService::class);
        $credits->method('balance')->willReturn(10000);
        $controller = new CruiseControlController($this->createMock(CruiseControlService::class), $registry, $credits,
            $this->createMock(CruiseActionRunService::class), $this->createMock(ProjectBriefService::class));
        $request = Request::create('/cruise/apply', 'POST', ['project_id' => $project->id, 'tool' => $name,
            'params' => ['scene_id' => 0], 'expected_credits' => $approvedCost]);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 1, 'workspace_id' => 1]));
        return [$controller->apply($request), $project->fresh()];
    }

    public function test_changed_revision_price_requires_another_approval(): void
    {
        [$response, $project] = $this->apply('regenerate_image', 30, 25, false);
        $this->assertSame(409, $response->status());
        $this->assertArrayNotHasKey('ugc_revision_at', $project->visual_brief);
    }

    public function test_applied_revision_persists_export_invalidation_without_losing_metadata(): void
    {
        [$response, $project] = $this->apply('regenerate_image', 25, 25, true);
        $this->assertSame(200, $response->status());
        $this->assertNotEmpty($project->visual_brief['ugc_revision_at']);
        $this->assertSame(9, $project->visual_brief['ugc_revision_export_id']);
        $this->assertSame('existing metadata', $project->visual_brief['keep']);
    }

    public function test_exporting_does_not_invalidate_the_export_it_just_started(): void
    {
        [$response, $project] = $this->apply('export_video', 0, 0, true);
        $this->assertSame(200, $response->status());
        $this->assertArrayNotHasKey('ugc_revision_at', $project->visual_brief);
    }
}
