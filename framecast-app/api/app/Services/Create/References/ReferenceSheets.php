<?php
namespace App\Services\Create\References;

use App\Models\Asset;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\{Process, Storage};

/**
 * A contact sheet of a studied reference video (20 frames in order), made once
 * and kept beside the asset, so the planner can design from the frames and the
 * reviewer can compare a draft against them. Never placed in an output.
 */
class ReferenceSheets
{
    public const FRAMES = 20;

    /** Visual style evidence for image generation, from immutable quote inputs; never output footage. */
    public function characterStyleImages(array $files, string $dir): array
    {
        $references = array_values(array_filter($files, fn ($f) => ($f['purpose'] ?? '') === 'reference'
            && in_array($f['asset_type'] ?? '', ['image', 'video'], true) && ($f['reference']['from'] ?? '') !== 'page'));
        usort($references, fn ($a, $b) => ($a['asset_type'] === 'video' ? 0 : 1) <=> ($b['asset_type'] === 'video' ? 0 : 1));
        $references = array_slice($references, 0, 2);
        app(\App\Services\Create\InputSnapshotService::class)->verify($references);
        $images = [];
        foreach ($references as $f) {
            $input = app(\App\Services\Create\CreateStorage::class)->path($f['storage_path']);
            $times = [null];
            if ($f['asset_type'] === 'video') {
                $probe = Process::timeout(15)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $input]);
                $duration = (float) trim($probe->output());
                abort_unless($probe->successful() && $duration > 0, 422, 'The reference video could not be inspected for the character preview.');
                // Two early frames retain useful character detail, unlike a tiny full-video grid.
                $times = [min(.5, $duration / 4), min(2, $duration / 2)];
            }
            foreach ($times as $time) {
                if (count($images) >= 3) break 2;
                $output = $dir.'/style-'.count($images).'.jpg';
                $r = Process::timeout(20)->run(['ffmpeg', '-v', 'error', '-y', ...($time !== null ? ['-ss', (string) $time] : []),
                    '-i', $input, '-vf', 'scale=min(1280\,iw):-2', '-frames:v', '1', '-q:v', '3', $output]);
                abort_unless($r->successful() && is_file($output), 422, 'A reference image could not be prepared for the character preview.');
                $images[] = $output;
            }
        }
        return $images;
    }

    /** Storage path of the sheet, generating it on first use; null when it cannot be made. */
    public function pathFor(Asset $asset): ?string
    {
        if ($asset->asset_type !== 'video' || ! $asset->storage_url) return null;
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') return null;
        $path = 'create/references/'.$asset->id.'/'.hash('sha256', $bytes).'/sheet.jpg';
        if (app(\App\Services\Create\CreateStorage::class)->exists($path)) return $path;
        $dir = sys_get_temp_dir().'/ref-sheet-'.\Illuminate\Support\Str::uuid();
        @mkdir($dir, 0700, true);
        try {
            file_put_contents($dir.'/in.mp4', $bytes);
            $duration = (float) trim(Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $dir.'/in.mp4'])->output());
            if ($duration <= 0) return null;
            $fps = self::FRAMES / $duration;
            $r = Process::timeout(90)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $dir.'/in.mp4', '-vf', "fps={$fps},scale=320:-2,tile=5x4", '-frames:v', '1', '-q:v', '5', $dir.'/sheet.jpg']);
            if (! $r->successful() || ! is_file($dir.'/sheet.jpg')) return null;
            app(\App\Services\Create\CreateStorage::class)->put($path, file_get_contents($dir.'/sheet.jpg'));
            return $path;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
    /** Three cut windows (before/at/after); local extraction, no additional model call. */
    public function transitionsFor(Asset $asset): ?array
    {
        $cuts = array_slice(array_values(array_filter((array) data_get($asset->metadata_json, 'reference_analysis.cuts', []),
            fn ($t) => is_numeric($t) && (float) $t > 0)), 0, 3);
        if (! $cuts || $asset->asset_type !== 'video') return null;
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') return null;
        $duration = (float) data_get($asset->metadata_json, 'reference_analysis.duration_seconds', $asset->duration_seconds);
        $times = [];
        foreach ($cuts as $cut) foreach ([-.12, 0, .12] as $offset) $times[] = round(max(0, min($duration - .04, (float) $cut + $offset)), 3);
        if ($duration <= .04) return null;
        $path = 'create/references/'.$asset->id.'/'.hash('sha256', $bytes.json_encode($times)).'/transitions.jpg';
        if (app(\App\Services\Create\CreateStorage::class)->exists($path)) return ['path' => $path, 'times' => $times];
        $dir = sys_get_temp_dir().'/ref-cuts-'.\Illuminate\Support\Str::uuid();
        mkdir($dir, 0700, true);
        try {
            file_put_contents($dir.'/in.mp4', $bytes);
            foreach ($times as $i => $time) {
                $r = Process::timeout(15)->run(['ffmpeg', '-v', 'error', '-y', '-ss', (string) $time, '-i', $dir.'/in.mp4',
                    '-vf', 'scale=480:270:force_original_aspect_ratio=decrease,pad=480:270:(ow-iw)/2:(oh-ih)/2', '-frames:v', '1', $dir.'/'.sprintf('%02d', $i).'.jpg']);
                if (! $r->successful()) return null;
            }
            $r = Process::timeout(20)->run(['ffmpeg', '-v', 'error', '-y', '-i', $dir.'/%02d.jpg', '-vf', 'tile=3x'.count($cuts), '-frames:v', '1', '-q:v', '4', $dir.'/sheet.jpg']);
            if (! $r->successful() || ! is_file($dir.'/sheet.jpg')) return null;
            app(\App\Services\Create\CreateStorage::class)->put($path, file_get_contents($dir.'/sheet.jpg'));
            return ['path' => $path, 'times' => $times];
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

}
