<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Media\StorageService;

/**
 * Server-rendered public share page (/sample/{token}).
 *
 * Exists because the SPA route gave every scraper — WhatsApp, Slack, X,
 * Googlebot — an empty index.html: no title, no preview image, no video
 * markup, so shared links unfurled as nothing and the pages could never
 * rank. This page carries full OpenGraph + VideoObject JSON-LD for the
 * machines, and a hover-to-play player for the humans, in one response
 * with no JS framework.
 */
class SharePageController extends Controller
{
    public function show(string $token, StorageService $storage)
    {
        $project = Project::query()
            ->where('share_token', $token)
            ->where('is_shared', true)
            ->first();

        if (! $project) {
            return response()->view('share.unavailable', [], 404);
        }

        $export = ExportJob::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', ['completed', 'succeeded'])
            ->whereNotNull('output_asset_id')
            ->orderByDesc('completed_at')
            ->first();

        $videoUrl = null;
        $duration = null;
        if ($export) {
            $asset = Asset::query()->find($export->output_asset_id);
            if ($asset?->storage_url) {
                $videoUrl = $storage->url($asset->storage_url);
                $duration = (float) ($asset->duration_seconds ?: 0);
            }
        }

        // Poster: the first scene that has an IMAGE visual. Scrapers need a
        // real thumbnail URL or the unfurl is a grey box.
        $poster = null;
        foreach (Scene::query()->where('project_id', $project->getKey())->orderBy('scene_order')->get(['visual_asset_id']) as $s) {
            if (! $s->visual_asset_id) {
                continue;
            }
            $a = Asset::query()->find($s->visual_asset_id);
            if ($a && $a->asset_type === 'image' && $a->storage_url) {
                $poster = $storage->url($a->storage_url);
                break;
            }
        }

        [$w, $h] = match ($project->aspect_ratio) {
            '16:9'  => [1280, 720],
            '1:1'   => [1080, 1080],
            default => [720, 1280],   // 9:16
        };

        $title = trim((string) ($project->title ?: 'A video made with WyvStudio'));
        $firstLine = Scene::query()->where('project_id', $project->getKey())
            ->orderBy('scene_order')->value('script_text');
        $description = mb_substr(trim((string) ($firstLine ?: 'Branded short-form video, generated with WyvStudio.')), 0, 160);

        return response()->view('share.page', [
            'title'       => $title,
            'description' => $description,
            'videoUrl'    => $videoUrl,
            'poster'      => $poster,
            'width'       => $w,
            'height'      => $h,
            'isoDuration' => $duration ? 'PT'.max(1, (int) round($duration)).'S' : null,
            'uploadDate'  => ($export?->completed_at ?? $project->created_at)?->toIso8601String(),
            'canonical'   => 'https://app.wyvstudio.com/sample/'.$token,
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
