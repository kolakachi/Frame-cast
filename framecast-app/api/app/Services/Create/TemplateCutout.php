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
            $path = sprintf('workspaces/%d/assets/cutouts/%s.png', $photo->workspace_id, Str::uuid());
            return Asset::query()->create([
                'workspace_id' => $photo->workspace_id, 'asset_type' => 'image', 'title' => self::title($photo),
                'storage_url' => $storage->put($path, $png, ['ContentType' => 'image/png']), 'mime_type' => 'image/png',
                'file_size_bytes' => strlen($png), 'tags' => ['cutout'], 'status' => 'active', 'created_by_user_id' => $photo->created_by_user_id,
            ]);
        } catch (\Throwable $e) {
            // Without it the photo still goes in as it is; the planner may then rebuild, as before.
            \Illuminate\Support\Facades\Log::warning('template cut-out failed', ['asset_id' => $photo->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
    }

    public static function title(Asset $photo): string
    {
        return mb_substr('Cut out · '.($photo->title ?: 'product photo'), 0, 255);
    }
}
