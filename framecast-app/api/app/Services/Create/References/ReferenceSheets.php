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

    /** Storage path of the sheet, generating it on first use; null when it cannot be made. */
    public function pathFor(Asset $asset): ?string
    {
        $path = 'create/references/'.$asset->id.'/sheet.jpg';
        if (Storage::disk('local')->exists($path)) return $path;
        if ($asset->asset_type !== 'video' || ! $asset->storage_url) return null;
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') return null;
        $dir = sys_get_temp_dir().'/ref-sheet-'.$asset->id.'-'.getmypid();
        @mkdir($dir, 0700, true);
        try {
            file_put_contents($dir.'/in.mp4', $bytes);
            $duration = (float) trim(Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $dir.'/in.mp4'])->output());
            if ($duration <= 0) return null;
            $fps = self::FRAMES / $duration;
            $r = Process::timeout(90)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $dir.'/in.mp4', '-vf', "fps={$fps},scale=320:-2,tile=5x4", '-frames:v', '1', '-q:v', '5', $dir.'/sheet.jpg']);
            if (! $r->successful() || ! is_file($dir.'/sheet.jpg')) return null;
            Storage::disk('local')->put($path, file_get_contents($dir.'/sheet.jpg'));
            return $path;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}
