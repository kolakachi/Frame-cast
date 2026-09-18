<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Samples stills out of a reference so its structure can be read from what was
 * shown, not only from what was said.
 *
 * A transcript alone misses everything an ad does with pictures: where it cuts,
 * when the product first appears, a card of text carrying the claim. It cannot
 * read a silent ad at all. Frames answer all of that, and the timestamps make
 * a beat boundary findable at a cut rather than only at a sentence ending.
 */
class UgcFrameSampler
{
    /** The multimodal message caps at 15 images, so there is no point sampling more. */
    public const MAX_FRAMES = 15;

    public function __construct(private readonly StorageService $storage) {}

    /**
     * @return array<int, array{url: string, at: float}> data URIs with their source time
     */
    public function sample(Asset $asset, float $durationSeconds): array
    {
        if (! str_starts_with((string) $asset->mime_type, 'video/')) {
            return [];
        }

        $temp = null;
        $dir = null;

        try {
            // ffmpeg reads http(s) directly, so a stock clip needs no download
            // at all; only our own storage has to be fetched first.
            [$input, $temp] = $this->input($asset);
            if ($input === null) {
                return [];
            }

            $dir = sys_get_temp_dir().'/framecast-frames-'.Str::uuid();
            if (! @mkdir($dir, 0700, true)) {
                $dir = null;

                return [];
            }

            // One frame every few seconds, spaced so a whole ad is covered
            // rather than only its opening. A 30s ad lands about one every two.
            $every = max(1.0, round(($durationSeconds ?: 30) / self::MAX_FRAMES, 2));

            $result = Process::timeout(120)->run([
                'ffmpeg', '-y', '-i', $input,
                '-vf', 'fps=1/'.$every.',scale=512:-2',
                '-frames:v', (string) self::MAX_FRAMES,
                '-q:v', '6',
                $dir.'/f%03d.jpg',
            ]);

            if (! $result->successful()) {
                Log::warning('UGC frame sampling failed', ['error' => mb_substr($result->errorOutput(), 0, 200)]);

                return [];
            }

            $frames = [];
            foreach (glob($dir.'/f*.jpg') ?: [] as $i => $path) {
                $bytes = @file_get_contents($path);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                $frames[] = [
                    'url' => 'data:image/jpeg;base64,'.base64_encode($bytes),
                    // Where in the reference this frame came from, so the model
                    // can line a picture up with the words spoken over it.
                    'at' => round($i * $every, 1),
                ];
            }

            return $frames;
        } catch (\Throwable $e) {
            Log::warning('UGC frame sampling errored', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return [];
        } finally {
            // Frames are a few hundred KB each and a downloaded reference tens
            // of megabytes; leaving either behind fills the container's disk.
            // Guarded because both paths can be skipped before they exist.
            if ($dir !== null) {
                foreach (glob($dir.'/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /**
     * What to hand ffmpeg, and the temp file to delete afterwards if we made
     * one. An external clip is read over http; ours is fetched first because
     * the bucket is not public to ffmpeg.
     *
     * @return array{0: ?string, 1: ?string}  [input, temp path to clean up]
     */
    /**
     * The clip's real length, probed from the file. Uploads recorded before
     * durations were probed have none on the row, and a reader told a video
     * is "0 seconds" concludes it is empty.
     */
    public function duration(Asset $asset): ?float
    {
        $temp = null;
        try {
            [$input, $temp] = $this->input($asset);
            if ($input === null) {
                return null;
            }
            $result = Process::timeout(60)->run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $input,
            ]);
            $probed = (float) trim($result->output());

            return $probed > 0 ? round($probed, 2) : null;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    private function input(Asset $asset): array
    {
        $raw = trim((string) $asset->storage_url);
        if ($raw === '') {
            return [null, null];
        }

        if (! $this->storage->isManagedUrl($raw)) {
            return [str_starts_with($raw, 'http') ? $raw : null, null];
        }

        $bytes = $this->storage->get($raw);
        if ($bytes === null || $bytes === '') {
            return [null, null];
        }
        $ext = pathinfo((string) $this->storage->extractPath($raw), PATHINFO_EXTENSION) ?: 'mp4';
        $path = sys_get_temp_dir().'/framecast-ref-'.Str::uuid().'.'.$ext;
        file_put_contents($path, $bytes);

        return [$path, $path];
    }
}
