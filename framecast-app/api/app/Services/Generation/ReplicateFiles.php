<?php

namespace App\Services\Generation;

use App\Models\Asset;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A copy of one of our files in Replicate's own file store, for a model that could not fetch it from ours (B2 answered
 * "Network is unreachable" to Replicate on 2026-09-24, and a paying customer's lip-sync and animation failed with it).
 */
final class ReplicateFiles
{
    public static function copy(Asset $asset, string $what = 'file'): string
    {
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') throw new RuntimeException("The {$what} could not be read to retry.");
        $mime = (string) ($asset->mime_type ?: 'application/octet-stream');
        $ext = match (true) {
            str_contains($mime, 'jpeg') => 'jpg', str_contains($mime, 'webp') => 'webp', str_contains($mime, 'png') => 'png',
            str_contains($mime, 'mpeg') => 'mp3', str_contains($mime, 'wav') => 'wav', str_contains($mime, 'mp4') => 'mp4',
            default => pathinfo((string) $asset->storage_url, PATHINFO_EXTENSION) ?: 'bin',
        };
        $r = Http::withToken((string) config('services.replicate.api_token'))->timeout(60)
            ->attach('content', $bytes, $what.'.'.$ext, ['Content-Type' => $mime])
            ->post('https://api.replicate.com/v1/files');
        $url = $r->json('urls.get');
        if (! $r->successful() || ! is_string($url)) throw new RuntimeException("The {$what} could not be handed to the model.");

        return $url;
    }
}
