<?php
namespace App\Http\Middleware;
use App\Models\{Project, Scene, ExportJob};
use Closure;
use Illuminate\Http\Request;

/** Existing scene and delivery endpoints must explicitly opt into composition support. */
class GuardCompositionAccess
{
    public function handle(Request $r, Closure $next)
    {
        if (!$r->user() || $r->is('api/v1/create/*')) return $next($r);
        $ids = [$r->route('projectId'), $r->route('videoId'), $r->input('project_id')];
        if (is_numeric($r->route('sceneId'))) $ids[] = Scene::find($r->route('sceneId'))?->project_id;
        if (is_numeric($r->input('export_job_id'))) $ids[] = ExportJob::where('workspace_id',$r->user()->workspace_id)->find($r->input('export_job_id'))?->project_id;
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id)) continue;
            $project = Project::where('workspace_id',$r->user()->workspace_id)->find($id);
            if (!$project?->isComposition()) continue;
            // These reads have composition-aware serialization/freshness. Public
            // delivery and scene mutation stay closed until their own UI acceptance.
            if ($r->isMethod('GET') && preg_match('~^api/v1/projects/\d+(?:/exports(?:/\d+/freshness)?)?$~D',$r->path())) continue;
            return response()->json(['error'=>['code'=>'unsupported_editor_kind','message'=>'Open this video in Create. This action is not supported for compositions.']],422);
        }
        return $next($r);
    }
}
