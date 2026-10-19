<?php

namespace App\Services\Create;

use App\Models\Asset;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A product photo attached to an exact template copy gets its cut-out too (owner, 2026-10-19), made at once and at our
 * cost (a fraction of a cent), so a template whose picture slot shows a cut-out product is still a plain swap: the
 * planner puts the cut-out in the slot, and nothing is bought or rebuilt (OUTREACH-5's five template ads were priced as
 * full builds for want of one).
 */
class TemplateCutout
{
    /** The cut-out to attach beside this photo, or null when the conversation is not an exact template copy or it fails. */
    public static function for(object $conversation, Asset $photo, string $purpose): ?Asset
    {
        $settings = json_decode((string) ($conversation->settings_json ?? ''), true) ?: [];
        if (empty($settings['template_id']) || ($settings['reference_match'] ?? '') !== 'exact') return null;
        if ($photo->asset_type !== 'image' || ! in_array($purpose, ['source', 'auto'], true) || str_starts_with((string) $photo->title, 'Cut out · ')) return null;
        if (! in_array((string) $photo->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        $existing = Asset::query()->where('workspace_id', $photo->workspace_id)->where('title', self::title($photo))->where('status', '!=', 'archived')->first();
        if ($existing) return $existing;
        try {
            $storage = app(StorageService::class);
            $bytes = $storage->get((string) $photo->storage_url);
            if (! is_string($bytes) || $bytes === '') return null;
            $png = app(PlanMediaExecutor::class)->cutoutBytes($bytes, (string) $photo->mime_type);
            if ($png === '') return null;
            $thin = self::thin($png);
            $png = self::fitToSlot($conversation, $png) ?? $png;
            $path = sprintf('workspaces/%d/assets/cutouts/%s.png', $photo->workspace_id, Str::uuid());
            return Asset::query()->create([
                'workspace_id' => $photo->workspace_id, 'asset_type' => 'image', 'title' => self::title($photo),
                'storage_url' => $storage->put($path, $png, ['ContentType' => 'image/png']), 'mime_type' => 'image/png',
                'file_size_bytes' => strlen($png), 'tags' => ['cutout'], 'status' => 'active', 'created_by_user_id' => $photo->created_by_user_id,
                ...($thin ? ['metadata_json' => ['cutout_thin' => true]] : []),
            ]);
        } catch (\Throwable $e) {
            // Without it the photo still goes in as it is; the planner may then rebuild, as before.
            \Illuminate\Support\Facades\Log::warning('template cut-out failed', ['asset_id' => $photo->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
    }

    /**
     * The cut-out placed where the template's own product sits (OUTREACH-5's candle, 2026-10-19: a tight cut-out of a
     * wide jar filled the slot of a slim can and covered the name, tagline and price). The template's cut-out picture
     * is measured (its product's box inside its transparent canvas) and the new product is trimmed to its own edges,
     * scaled to fit that box (a quarter wider at most, so a wide product still reads), centred on it and standing on
     * its base, on a canvas the template's size. Null when the template has no single cut-out picture to match.
     */
    public static function fitToSlot(object $conversation, string $png): ?string
    {
        $head = $conversation->head_revision_id ? DB::table('composition_revisions')->where('id', $conversation->head_revision_id)->first() : null;
        $input = $head?->run_id ? json_decode((string) DB::table('composition_runs')->where('id', $head->run_id)->value('input_json'), true) : null;
        if (! $head || ! is_array($input)) return null;
        $files = collect($input['input_files'] ?? [])->keyBy('name');
        $slots = [];
        $tmp = [];
        try {
            foreach (PartSwap::pictures(json_decode((string) $head->bundle_json, true) ?: []) as $picture) {
                $f = $files[$picture['src']] ?? null;
                if (! $f || ! str_ends_with(strtolower($picture['src']), '.png') || empty($f['storage_path'])) continue;
                $tmp[] = $path = tempnam(sys_get_temp_dir(), 'slot-').'.png';
                file_put_contents($path, (string) app(CreateStorage::class)->get($f['storage_path']));
                $size = self::size($path); $box = self::box($path);
                // A product on a transparent canvas: its box is clearly smaller than the canvas.
                if ($size && $box && $box['w'] * $box['h'] < 0.9 * $size['w'] * $size['h']) $slots[] = ['canvas' => $size, 'box' => $box];
            }
            if (count($slots) !== 1) return null;
            ['canvas' => $canvas, 'box' => $box] = $slots[0];
            $tmp[] = $in = tempnam(sys_get_temp_dir(), 'cut-').'.png';
            file_put_contents($in, $png);
            $own = self::box($in);
            if (! $own) return null;
            $tmp[] = $out = tempnam(sys_get_temp_dir(), 'fit-').'.png';
            $w = (int) floor($box['w'] * 1.25); $h = $box['h'];
            $left = max(0, (int) round($box['x'] + ($box['w'] - $w) / 2));
            $run = \Illuminate\Support\Facades\Process::timeout(60)->run(['ffmpeg', '-v', 'error', '-y',
                '-f', 'lavfi', '-i', 'color=c=black@0.0:s='.$canvas['w'].'x'.$canvas['h'].',format=rgba', '-i', $in,
                '-filter_complex', sprintf('[1]crop=%d:%d:%d:%d,scale=%d:%d:force_original_aspect_ratio=decrease,format=rgba[p];[0][p]overlay=x=%d+(%d-overlay_w)/2:y=%d+%d-overlay_h:format=auto,format=rgba',
                    $own['w'], $own['h'], $own['x'], $own['y'], $w, $h, $left, $w, $box['y'], $h),
                '-frames:v', '1', $out]);
            return $run->successful() && filesize($out) ? (string) file_get_contents($out) : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('template cut-out could not be fitted', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        } finally {
            foreach ($tmp as $t) @unlink($t);
        }
    }

    /**
     * Whether the product fills little of its own outline once cut out: a cord bracelet across a white photo is a few
     * pixels of string in a large box, and in a template's product slot it nearly disappears (MOOSH's Green Jasper
     * bracelet, OUTREACH-5 2026-10-19). Under 15% of its box: the planner tells the user and suggests a photo.
     */
    public static function thin(string $png): bool
    {
        $in = tempnam(sys_get_temp_dir(), 'thin-').'.png';
        try {
            file_put_contents($in, $png);
            $box = self::box($in);
            return $box !== null && ($box['fill'] ?? 1.0) < 0.15;
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($in);
        }
    }

    /** @return array{w: int, h: int}|null */
    private static function size(string $path): ?array
    {
        $r = \Illuminate\Support\Facades\Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $path]);
        return preg_match('/^(\d+),(\d+)/', trim($r->output()), $m) ? ['w' => (int) $m[1], 'h' => (int) $m[2]] : null;
    }

    /**
     * Where the visible (non-transparent) pixels are, in the image's own pixels. The alpha channel is read as raw bytes at
     * up to 400 px and scanned here: ffmpeg's cropdetect differs between versions (5.1 on the servers skips frames).
     *
     * @return array{w: int, h: int, x: int, y: int, fill: float}|null
     */
    private static function box(string $path): ?array
    {
        $size = self::size($path);
        if (! $size) return null;
        $scale = min(1.0, 400 / max($size['w'], $size['h']));
        $sw = max(1, (int) round($size['w'] * $scale)); $sh = max(1, (int) round($size['h'] * $scale));
        $r = \Illuminate\Support\Facades\Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-i', $path, '-vf', "format=rgba,alphaextract,scale={$sw}:{$sh}", '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'gray', '-']);
        $raw = $r->output();
        if (! $r->successful() || strlen($raw) < $sw * $sh) return null;
        $x1 = $sw; $y1 = $sh; $x2 = -1; $y2 = -1; $seen = 0;
        for ($y = 0; $y < $sh; $y++) {
            $row = substr($raw, $y * $sw, $sw);
            if (trim($row, "\x00\x01\x02\x03\x04\x05\x06\x07\x08") === '') continue;
            for ($x = 0; $x < $sw; $x++) if (ord($row[$x]) > 8) { $x1 = min($x1, $x); $x2 = max($x2, $x); $seen++; }
            $y1 = min($y1, $y); $y2 = max($y2, $y);
        }
        if ($x2 < 0) return null;
        $fill = $seen / max(1, ($x2 - $x1 + 1) * ($y2 - $y1 + 1));
        $k = 1 / $scale;
        $x = (int) floor($x1 * $k); $y = (int) floor($y1 * $k);
        return ['x' => $x, 'y' => $y, 'w' => min($size['w'] - $x, (int) ceil(($x2 - $x1 + 1) * $k)), 'h' => min($size['h'] - $y, (int) ceil(($y2 - $y1 + 1) * $k)), 'fill' => round($fill, 3)];
    }

    public static function title(Asset $photo): string
    {
        return mb_substr('Cut out · '.($photo->title ?: 'product photo'), 0, 255);
    }
}
