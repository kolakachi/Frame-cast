<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One row per finished video downloaded, and the same as a PostHog event. Never fails the download it records. */
final class DownloadLog
{
    public const SOURCES = ['create', 'ugc', 'editor'];

    public static function record(int $workspaceId, ?int $userId, string $source, string $via, array $refs = []): void
    {
        rescue(function () use ($workspaceId, $userId, $source, $via, $refs): void {
            if (! Schema::hasTable('video_downloads')) return;
            $row = ['workspace_id' => $workspaceId, 'user_id' => $userId ?: null, 'source' => in_array($source, self::SOURCES, true) ? $source : 'editor',
                'via' => $via === 'api' ? 'api' : 'app', 'project_id' => $refs['project_id'] ?? null, 'conversation_id' => $refs['conversation_id'] ?? null,
                'revision_id' => $refs['revision_id'] ?? null, 'asset_id' => $refs['asset_id'] ?? null, 'created_at' => now()];
            DB::table('video_downloads')->insert($row);
            PostHogService::capture($userId, 'video_download_served',
                array_filter(['source' => $row['source'], 'via' => $row['via'], 'project_id' => $row['project_id'], 'revision_id' => $row['revision_id']]), $workspaceId);
        }, null, true);
    }

    /** What a signed download link carries: the person who asked for it and whether through the API. Signed, so not forgeable. */
    public static function linkParams(?int $userId, bool $api = false): array
    {
        return array_filter(['u' => $userId, 'via' => $api ? 'api' : null]);
    }
}
