<?php

namespace App\Services\Media;

use App\Models\Asset;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Small JPEG previews of image and video assets for assistants to show
 * inline. ffmpeg does the work (the API image carries no GD), reading the
 * object through the storage stream so it works the same for MinIO and B2.
 * Results are cached on local disk per asset, width and asset version.
 */
class PreviewRenderer
{
    public const WIDTH = 512;

    public function __construct(private readonly StorageService $storage)
    {
    }

    /** JPEG bytes, or null when the asset cannot be rendered. */
    public function jpeg(Asset $asset, int $width = self::WIDTH): ?string
    {
        if (! in_array($asset->asset_type, ['image', 'video'], true) || ! $asset->storage_url) {
            return null;
        }
        $width = max(64, min(1024, $width));
        $dir = storage_path('app/previews');
        File::ensureDirectoryExists($dir);
        $cached = $dir.'/'.$asset->getKey().'-'.$width.'-'.($asset->updated_at?->getTimestamp() ?? 0).'.jpg';
        if (is_file($cached) && filesize($cached) > 0) {
            return (string) file_get_contents($cached);
        }

        // Stock clips and images are plain URLs (Pexels and the like); ffmpeg
        // reads those directly and seeks by range instead of downloading the
        // whole file. Our own objects come through the storage stream.
        $url = (string) $asset->storage_url;
        $direct = preg_match('#^https?://#i', $url) === 1 && $this->storage->url($url) === $url;
        $source = null;
        $stream = null;
        if (! $direct) {
            $stream = $this->storage->readStream($url);
            if (! is_resource($stream)) {
                return null;
            }
        }
        $out = tempnam(sys_get_temp_dir(), 'wyv-preview-out-').'.jpg';
        try {
            if ($stream) {
                $source = tempnam(sys_get_temp_dir(), 'wyv-preview-src-');
                $sink = fopen($source, 'wb');
                stream_copy_to_stream($stream, $sink);
                fclose($sink);
                fclose($stream);
            }
            $input = $direct ? $url : $source;

            $args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y'];
            if ($asset->asset_type === 'video') {
                // A frame one second in: past any fade-up, before the story moves on.
                $seek = min(1.0, max(0.0, ((float) ($asset->duration_seconds ?? 0)) / 2));
                array_push($args, '-ss', (string) $seek);
            }
            array_push($args, '-i', $input, '-vf', "scale='min({$width},iw)':-2", '-frames:v', '1', '-q:v', '4', '-f', 'image2', $out);
            $process = new Process($args, null, null, null, 60);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
                return null;
            }
            $bytes = (string) file_get_contents($out);
            @file_put_contents($cached, $bytes);

            return $bytes;
        } finally {
            if ($source) {
                @unlink($source);
            }
            @unlink($out);
        }
    }
}
