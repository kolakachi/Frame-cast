<?php
namespace Tests\Feature;

use App\Models\{Asset, ExportJob, Project, Scene, User, Workspace};
use App\Services\Export\{ExportSnapshot, ExportFreshnessService, ProjectExportService};
use App\Services\{CreditService, WorkspaceUsageService};
use App\Http\Controllers\Api\V1\Scene\SceneController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Schema};
use Illuminate\Http\Request;
use Tests\TestCase;

class EditorIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'editor_test','database.connections.editor_test'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::purge('editor_test'); Bus::fake();
        foreach ([
            'workspaces'=>['plan_tier'],
            'users'=>['workspace_id','email'],
            'projects'=>['workspace_id','created_by_user_id','title','aspect_ratio','primary_language','music_asset_id','music_settings_json','visual_brief','status'],
            'scenes'=>['project_id','scene_order','script_text','duration_seconds','visual_asset_id','voice_settings_json','caption_settings_json','image_generation_settings_json','sound_asset_id','sound_settings_json','visual_type','status'],
            'assets'=>['workspace_id','asset_type','storage_url','duration_seconds'],
            'export_jobs'=>['workspace_id','project_id','variant_id','status','source_fingerprint','render_snapshot','aspect_ratio','language','file_name','watermark_enabled','progress_percent','priority','queued_at','started_at','completed_at','output_asset_id'],
        ] as $table=>$columns) Schema::create($table,function(Blueprint $t) use($columns){$t->id();foreach($columns as $c)$t->text($c)->nullable();$t->timestamps();});
    }
    private function fixture(): array
    {
        $workspace=Workspace::withoutEvents(fn()=>Workspace::create(['plan_tier'=>'creator']));
        $user=User::withoutEvents(fn()=>User::create(['workspace_id'=>$workspace->id,'email'=>'editor@example.test']));
        $project=Project::withoutEvents(fn()=>Project::create(['workspace_id'=>$workspace->id,'created_by_user_id'=>$user->id,'title'=>'Test','aspect_ratio'=>'9:16','primary_language'=>'en']));
        $asset=Asset::create(['workspace_id'=>$workspace->id,'asset_type'=>'video','storage_url'=>'minio://original.mp4']);
        $scene=Scene::create(['project_id'=>$project->id,'scene_order'=>1,'script_text'=>'','visual_asset_id'=>$asset->id,'duration_seconds'=>4]);
        return [$user,$project,$scene,$asset];
    }
    private function service(int $remaining=10): ProjectExportService
    {
        $usage=$this->createMock(WorkspaceUsageService::class);
        $usage->method('hasReachedExportLimit')->willReturn(false);
        $usage->method('exportsRemaining')->willReturn($remaining);
        return new ProjectExportService($usage);
    }
    public function test_snapshot_survives_edits_reordering_and_deleted_scene(): void
    {
        [, $project,$scene,$asset]=$this->fixture();
        $job=$this->service()->queue($project);
        $scene->forceFill(['script_text'=>'Changed','scene_order'=>2])->save();
        $scene->delete();
        $asset->forceFill(['storage_url'=>'minio://replacement.mp4'])->save();
        $snapshot=ExportSnapshot::scenes($job->fresh())->first();
        $this->assertSame('', $snapshot->script_text);
        $this->assertEquals(1,$snapshot->scene_order);
        $this->assertSame('minio://original.mp4',ExportSnapshot::asset($job->fresh(),$asset->id)->storage_url);
        $this->assertTrue((new ExportFreshnessService)->check($project,$job)['is_stale']);
    }
    public function test_pending_export_reserves_last_allowance(): void
    {
        [, $project]=$this->fixture();$service=$this->service(1);
        $service->queue($project);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('remaining exports');
        $service->queue($project);
    }
    public function test_silent_visual_is_exportable_but_outdated_voice_is_not(): void
    {
        [, $project,$scene]=$this->fixture();
        $this->service()->assertExportable($project);
        $scene->script_text='New narration';$scene->voice_settings_json=['is_outdated'=>true];$scene->save();
        $this->expectExceptionMessage('outdated narration');
        $this->service()->assertExportable($project);
    }
    public function test_cross_workspace_audio_cannot_be_assigned(): void
    {
        [$user,,$scene]=$this->fixture();
        $foreign=Asset::create(['workspace_id'=>999,'asset_type'=>'audio','storage_url'=>'minio://private.wav']);
        $request=Request::create('/scenes/'.$scene->id,'PATCH',['voice_settings_json'=>['audio_asset_id'=>$foreign->id]]);
        $request->setUserResolver(fn()=>$user);
        $controller=new SceneController($this->createMock(WorkspaceUsageService::class),new CreditService);
        $response=$controller->update($request,$scene->id);
        $this->assertSame(422,$response->getStatusCode());
        $this->assertNull($scene->fresh()->voice_settings_json);
    }
    public function test_script_edit_marks_voice_stale_and_client_cannot_clear_it(): void
    {
        [$user,,$scene]=$this->fixture();
        $controller=new SceneController($this->createMock(WorkspaceUsageService::class),new CreditService);
        foreach ([['script_text'=>'Changed narration'], ['voice_settings_json'=>['is_outdated'=>false]]] as $payload) {
            $request=Request::create('/scenes/'.$scene->id,'PATCH',$payload);
            $request->setUserResolver(fn()=>$user);
            $this->assertSame(200,$controller->update($request,$scene->id)->getStatusCode());
            $this->assertTrue($scene->fresh()->voice_settings_json['is_outdated']);
        }
    }
    public function test_narration_duration_cannot_be_silently_overridden(): void
    {
        [$user,,$scene]=$this->fixture();
        $scene->voice_settings_json=['audio_asset_id'=>50];$scene->save();
        $request=Request::create('/scenes/'.$scene->id,'PATCH',['duration_seconds'=>15]);
        $request->setUserResolver(fn()=>$user);
        $controller=new SceneController($this->createMock(WorkspaceUsageService::class),new CreditService);
        $this->assertSame(422,$controller->update($request,$scene->id)->getStatusCode());
        $this->assertEquals(4,$scene->fresh()->duration_seconds);
    }
    public function test_legacy_foreign_asset_is_rejected_at_export_consumption(): void
    {
        [, $project]=$this->fixture();
        $foreign=Asset::create(['workspace_id'=>999,'asset_type'=>'audio']);
        $job=new ExportJob(['workspace_id'=>$project->workspace_id]);
        $this->expectExceptionMessage('unavailable in this workspace');
        ExportSnapshot::asset($job,$foreign->id);
    }
    public function test_whole_video_rejects_other_ratios_and_free_watermark_bypass(): void
    {
        [, $project]=$this->fixture();
        $project->forceFill(['visual_brief'=>['ugc_format'=>'one_shot']])->saveQuietly();
        try {$this->service()->queue($project,['aspect_ratio'=>'1:1']);$this->fail('Should reject ratio');}
        catch(\RuntimeException $e){$this->assertStringContainsString('original aspect ratio',$e->getMessage());}
        Workspace::whereKey($project->workspace_id)->update(['plan_tier'=>'free']);
        $this->expectExceptionMessage('requires a watermark');
        $this->service()->queue($project);
    }
}
