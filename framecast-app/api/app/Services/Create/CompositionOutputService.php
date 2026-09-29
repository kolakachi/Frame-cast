<?php
namespace App\Services\Create;

use App\Models\{Asset, ExportJob, Project, User, Workspace};
use App\Services\{CreditService, WorkspaceUsageService};
use Illuminate\Support\Facades\{DB, Storage};

/** Explicit private library registration; never dispatches a scene render or publishes. */
class CompositionOutputService
{
    public function register(User $user, string $conversationId, string $revisionId, int $version): array
    {
        $conversations = app(ConversationService::class);
        $conversations->authorize($user, true);
        return DB::transaction(function () use ($user, $conversationId, $revisionId, $version, $conversations) {
            $workspace = Workspace::whereKey($user->workspace_id)->lockForUpdate()->firstOrFail();
            $c = $conversations->conversation($user, $conversationId, true);
            $revision = DB::table('composition_revisions')->where('conversation_id', $c->id)->where('id', $revisionId)->lockForUpdate()->firstOrFail();
            abort_if($c->archived_at, 409, 'Restore this conversation first.');
            abort_unless((int)$c->version === $version && $c->head_revision_id === $revisionId, 409, 'Choose the current version before saving.');
            // The offline sample is unwatermarked. Never use it to bypass Free entitlements.
            abort_if((WorkspaceUsageService::plans()[$workspace->plan_tier]['watermark'] ?? true), 402, 'Your plan requires a watermarked export. Saving Create outputs is not available on this plan yet.');
            if ($revision->export_job_id) return $this->result($c->project_id, $revision);
            $user->setRelation('workspace',$workspace);
            $remaining = app(WorkspaceUsageService::class)->exportsRemaining($user);
            $pending = ExportJob::where('workspace_id', $workspace->id)->whereIn('status',['queued','processing'])->count();
            abort_if($remaining !== null && $remaining <= $pending, 402, 'Your monthly export allowance is already used or reserved.');
            $path = Storage::disk('local')->path($revision->artifact_path ?? 'missing');
            abort_unless(is_file($path) && !is_link($path) && hash_equals($revision->artifact_hash ?? '', hash_file('sha256',$path)), 409, 'The saved video is unavailable or changed.');
            abort_unless(preg_match('~^create/previews/[a-f0-9-]{36}/[a-f0-9]{64}\.mp4$~D', $revision->artifact_path), 422);
            // Normal shared contracts, distinct editor. No Scene rows are created.
            $project = $c->project_id ? Project::where('workspace_id',$workspace->id)->findOrFail($c->project_id) : new Project;
            $project->forceFill(['workspace_id'=>$workspace->id,'created_by_user_id'=>$c->created_by_user_id,
                'editor_kind'=>'composition','source_type'=>'composition','title'=>$c->title,'status'=>'ready_for_review',
                'aspect_ratio'=>'9:16','duration_target_seconds'=>15,'primary_language'=>'en']);
            $project->save();
            $asset = Asset::create(['workspace_id'=>$workspace->id,'created_by_user_id'=>$user->id,'asset_type'=>'video',
                'title'=>$c->title.' — version '.$revision->number,'storage_url'=>'create-private://'.substr($revision->artifact_path,16),
                'mime_type'=>'video/mp4','file_size_bytes'=>filesize($path),'duration_seconds'=>15,
                'dimensions_json'=>['width'=>1080,'height'=>1920], 'restriction_scope'=>'workspace','status'=>'active',
                'metadata_json'=>['composition_revision_id'=>$revision->id,'composition_hash'=>$revision->bundle_hash,'fixture'=>true]]);
            $export = new ExportJob;
            $export->forceFill(['workspace_id'=>$workspace->id,'project_id'=>$project->id,'aspect_ratio'=>'9:16','language'=>'en',
                'file_name'=>'creation-v'.$revision->number.'.mp4','watermark_enabled'=>false,'status'=>'completed','progress_percent'=>100,
                'output_asset_id'=>$asset->id,'priority'=>0,'queued_at'=>now(),'started_at'=>now(),'completed_at'=>now(),
                'composition_revision_id'=>$revision->id,'composition_hash'=>$revision->bundle_hash]);
            $export->save();
            DB::table('create_conversations')->where('id',$c->id)->update(['project_id'=>$project->id]);
            DB::table('composition_revisions')->where('id',$revision->id)->update(['output_asset_id'=>$asset->id,'export_job_id'=>$export->id]);
            return ['project_id'=>$project->id,'asset_id'=>$asset->id,'export_job_id'=>$export->id];
        });
    }
    private function result(int $projectId, object $revision): array {
        return ['project_id'=>$projectId,'asset_id'=>$revision->output_asset_id,'export_job_id'=>$revision->export_job_id];
    }
}
