<?php

namespace App\Console\Commands;

use App\Services\Generation\Image\DalleImageAdapter;
use App\Services\Generation\Image\ImageStyleDescriptors;
use App\Services\Media\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-shot generator for the editor's style-picker thumbnails. Renders a
 * single neutral subject in every style in ImageStyleDescriptors::META and
 * stores the result in B2 at style-samples/<key>.jpg.
 *
 * Run once during setup; re-run with --force when adding a new style. The
 * cost is ~$3.57 (21 × gpt-image-1 medium ≈ $0.17) — tiny one-time spend
 * in exchange for the picker showing actual renders instead of emojis.
 *
 * Usage:
 *   php artisan generate:style-samples              # skip styles already in B2
 *   php artisan generate:style-samples --force      # re-render every style
 *   php artisan generate:style-samples --style=anime
 */
class GenerateStyleSamplesCommand extends Command
{
    protected $signature = 'generate:style-samples
        {--force : Re-render styles whose B2 file already exists}
        {--style= : Limit to a single style key (e.g. anime, photorealistic)}';

    protected $description = 'Render one preview image per style for the editor picker, push to B2 under style-samples/';

    private const SAMPLE_PROMPT = 'a young woman in a cream sweater holding a glass mug in a sunlit kitchen, soft morning light through a window, gentle smile, medium close-up';

    public function handle(DalleImageAdapter $adapter, StorageService $storage): int
    {
        $only = $this->option('style');
        $force = (bool) $this->option('force');

        $styles = $only ? [$only] : array_keys(ImageStyleDescriptors::META);
        $skipped = 0;
        $rendered = 0;
        $failed = [];

        foreach ($styles as $key) {
            if (! isset(ImageStyleDescriptors::META[$key])) {
                $this->warn("[skip] Unknown style: {$key}");
                continue;
            }
            $path = "style-samples/{$key}.jpg";

            if (! $force && $this->b2Has($storage, $path)) {
                $skipped++;
                $this->line("  · {$key}: already exists, skip");
                continue;
            }

            $this->info("→ {$key}: rendering…");
            try {
                $result = $adapter->generate(
                    self::SAMPLE_PROMPT,
                    $key,
                    '1:1',
                    [
                        'quality' => 'medium',
                        // No usage_context — these aren't billable to a workspace.
                    ],
                );

                // DalleImageAdapter returns either image_url (HTTP) or image_b64.
                $bytes = null;
                if (! empty($result['image_b64'])) {
                    $bytes = base64_decode($result['image_b64']);
                } elseif (! empty($result['image_url'])) {
                    $resp = Http::timeout(30)->get($result['image_url']);
                    if ($resp->successful()) $bytes = $resp->body();
                }
                if (! $bytes) {
                    $failed[$key] = 'adapter returned no image bytes';
                    $this->error("  ✗ {$key}: no bytes");
                    continue;
                }

                $storage->put($path, $bytes, ['ContentType' => 'image/jpeg']);
                $rendered++;
                $this->info("  ✓ {$key}: stored at {$path}");
            } catch (\Throwable $e) {
                $failed[$key] = $e->getMessage();
                $this->error("  ✗ {$key}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Done. Rendered: {$rendered}, skipped: {$skipped}, failed: " . count($failed));
        if ($failed) {
            $this->warn('Failed styles:');
            foreach ($failed as $k => $m) $this->line("  · {$k}: {$m}");
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    /**
     * Cheapest "does this object exist in B2" probe — try to fetch the URL
     * with a HEAD. StorageService doesn't expose head, so we fall back to a
     * range-1 GET. Returns false on any 404/error, true on 2xx.
     */
    private function b2Has(StorageService $storage, string $path): bool
    {
        $url = $storage->url($path);
        try {
            $r = Http::withHeaders(['Range' => 'bytes=0-0'])->timeout(10)->get($url);
            return $r->status() >= 200 && $r->status() < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
