<?php

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, URL};

/**
 * A client's home (phase 3, A4; mockup view 4): what is waiting for their approval, each with its video to watch,
 * and who made it. The week comes from the calendar and messages go to the agency as client requests.
 */
class ClientHomeController extends Controller
{
    public function show(Request $r): JsonResponse
    {
        $w = Workspace::findOrFail($r->user()->workspace_id);
        $agency = $w->parent_workspace_id ? Workspace::find($w->parent_workspace_id) : null;
        $pending = DB::table('approvals')->where('workspace_id', $w->id)->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('created_at')->limit(20)->get();
        $decided = DB::table('approvals')->where('workspace_id', $w->id)->whereIn('status', ['approved', 'rejected'])->orderByDesc('reviewed_at')->limit(5)->get();

        return response()->json(['data' => [
            'client_name' => $w->client_label ?: $w->name, 'agency_name' => $agency?->name,
            'waiting' => $pending->map(fn ($a) => $this->shape($a))->values(),
            'recent' => $decided->map(fn ($a) => $this->shape($a) + ['decision' => $a->status === 'approved' ? 'approved' : 'changes', 'comment' => $a->comment, 'reviewed_at' => $a->reviewed_at])->values(),
        ]]);
    }

    private function shape(object $a): array
    {
        $export = $a->export_job_id ? DB::table('export_jobs')->where('id', $a->export_job_id)->first(['output_asset_id', 'composition_revision_id', 'aspect_ratio']) : null;
        $title = (string) (DB::table('projects')->where('id', $a->project_id)->value('title') ?: (json_decode((string) $a->metadata_json, true)['project_title'] ?? 'Your video'));
        $poster = $export?->composition_revision_id && app(\App\Services\Create\CreateStorage::class)->exists(\App\Jobs\MakeCreatePoster::path($export->composition_revision_id))
            ? URL::temporarySignedRoute('media.create.poster', now()->addHours(2), ['revisionId' => $export->composition_revision_id]) : null;

        return ['id' => $a->id, 'title' => $title, 'sent_at' => $a->created_at, 'expires_at' => $a->expires_at, 'aspect_ratio' => $export?->aspect_ratio,
            'video_url' => $export?->output_asset_id ? URL::temporarySignedRoute('media.assets.content', now()->addHours(2), ['assetId' => $export->output_asset_id]) : null,
            'poster_url' => $poster];
    }
}
