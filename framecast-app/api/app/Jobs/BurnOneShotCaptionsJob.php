<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Media\StorageService;
use App\Traits\RendersExportScenes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

/**
 * The finished file of a one-take UGC ad: its captions burned in, timed from the video's own speech, over the video's
 * own picture and sound. A one-take is not rebuilt scene by scene (that would strip its native audio), so until
 * 2026-10-10 its export simply handed back the raw video and Download had no captions.
 */
class BurnOneShotCaptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RendersExportScenes;

    public $timeout = 900;

    public $tries = 1;

    public function __construct(public readonly int $exportJobId)
    {
        $this->onQueue(ProcessExportJob::queueForExport($exportJobId));
    }

    public function handle(StorageService $storage): void
    {
        $export = ExportJob::query()->find($this->exportJobId);
        if (! $export || $export->status === 'completed') return;
        $project = Project::query()->find($export->project_id);
        $scene = Scene::query()->where('project_id', $export->project_id)->orderBy('scene_order')->first();
        $video = $scene?->visual_asset_id ? Asset::query()->find($scene->visual_asset_id) : null;
        if (! $project || ! $video) throw new \RuntimeException('The video for this take could not be found.');
        $export->forceFill(['status' => 'processing', 'started_at' => now(), 'progress_percent' => 10])->save();

        $temps = [];
        try {
            $in = tempnam(sys_get_temp_dir(), 'oneshot-cap-in-').'.mp4';
            $temps[] = $in;
            file_put_contents($in, (string) $storage->get((string) $video->storage_url));
            if (! filesize($in)) throw new \RuntimeException('The video for this take could not be read.');
            $size = trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', $in])->output());
            [$w, $h] = array_map('intval', explode('x', $size) + [1 => 0]);
            $duration = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $in])->output());
            if ($w <= 0 || $h <= 0 || $duration <= 0) throw new \RuntimeException('The video for this take could not be read.');

            $c = $scene->caption_settings_json ?? [];
            $ass = tempnam(sys_get_temp_dir(), 'oneshot-cap-').'.ass';
            $temps[] = $ass;
            $this->buildASSCaption(
                (string) $scene->script_text, (string) ($c['style_key'] ?? 'impact'), (string) ($c['position'] ?? 'bottom_third'),
                (string) ($c['font'] ?? 'Arial'), $duration, ['width' => $w, 'height' => $h], $ass,
                (string) ($c['highlight_mode'] ?? 'line_by_line'), $this->captionTimingWordsFromAsset($video),
                (string) ($c['color'] ?? '#ffffff'), (string) ($c['size'] ?? 'medium'), (string) ($c['highlight_color'] ?? '#ffffff'),
                ['animation' => (string) ($c['animation'] ?? 'plain'), 'highlight_style' => (string) ($c['highlight_style'] ?? 'color'),
                    'panel_color' => $c['panel_color'] ?? null, 'backdrop' => $c['backdrop'] ?? null],
            );
            $out = tempnam(sys_get_temp_dir(), 'oneshot-cap-out-').'.mp4';
            $temps[] = $out;
            $run = Process::timeout(800)->run(['ffmpeg', '-y', '-i', $in, '-vf', 'subtitles='.$ass, '-map', '0:v', '-map', '0:a?',
                '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'copy', '-movflags', '+faststart', $out]);
            if (! $run->successful() || ! filesize($out)) throw new \RuntimeException('The captions could not be added to this video.');

            $url = $storage->put('exports/export-'.$export->getKey().'.mp4', fopen($out, 'rb'), ['ContentType' => 'video/mp4']);
            $asset = Asset::query()->create([
                'workspace_id' => $export->workspace_id, 'asset_type' => 'video', 'title' => $export->file_name, 'description' => 'Rendered export output',
                'storage_url' => $url, 'duration_seconds' => $duration, 'dimensions_json' => ['width' => $w, 'height' => $h],
                'file_size_bytes' => filesize($out), 'mime_type' => 'video/mp4', 'tags' => ['export', $export->aspect_ratio, $export->language],
                'usage_count' => 1, 'status' => 'active',
            ]);
            $export->forceFill(['status' => 'completed', 'progress_percent' => 100, 'output_asset_id' => $asset->id, 'completed_at' => now()])->save();
        } finally {
            foreach ($temps as $t) @unlink($t);
        }
    }

    public function failed(\Throwable $e): void
    {
        ExportJob::query()->whereKey($this->exportJobId)->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 300)]);
    }
}
