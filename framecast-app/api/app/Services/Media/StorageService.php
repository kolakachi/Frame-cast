<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Unified storage abstraction.
 *
 * All NEW writes go to MinIO and are stored as "minio://<path>".
 * Reads for legacy "b2://<path>" (or bare paths) try MinIO first, then fall back
 * to the real Backblaze B2 bucket ("b2_legacy" disk).
 */
class StorageService
{
    private const MINIO = 'minio';
    private const B2    = 'b2_legacy';

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * Store a file on MinIO and return the canonical "minio://<path>" storage URL.
     */
    public function put(string $path, mixed $data, array $options = []): string
    {
        Storage::disk(self::MINIO)->put($path, $data, $options);

        return 'minio://'.$path;
    }

    // ── Deletes ──────────────────────────────────────────────────────────────

    /**
     * Delete a file from storage. Only acts on managed URLs (minio:// / b2://).
     * External HTTP URLs are silently ignored (we don't own them).
     * Returns true when the file was deleted, false when it was not found or
     * is not a managed URL.
     */
    public function delete(string $storageUrl): bool
    {
        $path = $this->extractPath($storageUrl);

        if ($path === null) {
            return false; // external URL — not our file to delete
        }

        try {
            if ($this->isMinio($storageUrl) || $this->minioHas($path)) {
                return Storage::disk(self::MINIO)->delete($path);
            }

            // Legacy b2:// path not on MinIO — delete from B2.
            return Storage::disk(self::B2)->delete($path);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Return a public HTTP URL for the given storage URL.
     * For legacy b2:// entries: checks MinIO first, falls back to B2 public URL.
     */
    public function url(string $storageUrl): string
    {
        $path = $this->extractPath($storageUrl);

        if ($path === null) {
            return $storageUrl; // already a plain HTTP URL
        }

        if ($this->isMinio($storageUrl)) {
            return Storage::disk(self::MINIO)->url($path);
        }

        // Legacy b2:// or bare path — prefer MinIO if the object was migrated.
        if ($this->minioHas($path)) {
            return Storage::disk(self::MINIO)->url($path);
        }

        return Storage::disk(self::B2)->url($path);
    }

    /**
     * Open a readable stream. Tries MinIO first for legacy URLs, falls back to B2.
     *
     * @return resource|false
     */
    public function readStream(string $storageUrl): mixed
    {
        $path = $this->extractPath($storageUrl);

        if ($path === null) {
            return false;
        }

        if ($this->isMinio($storageUrl) || $this->minioHas($path)) {
            return Storage::disk(self::MINIO)->readStream($path);
        }

        return Storage::disk(self::B2)->readStream($path);
    }

    /**
     * Get raw file contents. Tries MinIO first for legacy URLs, falls back to B2.
     */
    public function get(string $storageUrl): ?string
    {
        $path = $this->extractPath($storageUrl);

        if ($path === null) {
            return null;
        }

        if ($this->isMinio($storageUrl) || $this->minioHas($path)) {
            return Storage::disk(self::MINIO)->get($path);
        }

        return Storage::disk(self::B2)->get($path);
    }

    /**
     * Check whether the object exists on any configured disk.
     */
    public function exists(string $storageUrl): bool
    {
        $path = $this->extractPath($storageUrl);

        if ($path === null) {
            return false;
        }

        if ($this->isMinio($storageUrl)) {
            return Storage::disk(self::MINIO)->exists($path);
        }

        return $this->minioHas($path) || Storage::disk(self::B2)->exists($path);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns true when the storage URL points to one of our managed disks
     * (minio:// or b2:// scheme, or a bare path with no http(s) scheme).
     * Returns false for plain external HTTP(S) URLs.
     */
    public function isManagedUrl(string $storageUrl): bool
    {
        $url = trim($storageUrl);

        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, 'minio://') || str_starts_with($url, 'b2://')) {
            return true;
        }

        // Bare path (no scheme, not starting with /): treated as a legacy disk path.
        if (! str_contains($url, '://') && ! str_starts_with($url, '/')) {
            return true;
        }

        return false;
    }

    /**
     * Extract the raw disk path from a storage URL (strips scheme and bucket prefix).
     * Returns null for plain external HTTP(S) URLs.
     */
    public function extractPath(string $storageUrl): ?string
    {
        $url = trim($storageUrl);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'minio://')) {
            return ltrim(substr($url, 8), '/');
        }

        if (str_starts_with($url, 'b2://')) {
            return ltrim(substr($url, 5), '/');
        }

        // Bare path (no scheme, no leading /).
        if (! str_contains($url, '://') && ! str_starts_with($url, '/')) {
            return ltrim($url, '/');
        }

        // Full HTTP URL — try to strip the bucket prefix from the path.
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parts = parse_url($url);
            $path  = trim((string) ($parts['path'] ?? ''), '/');

            foreach ([self::MINIO, self::B2] as $disk) {
                $bucket = trim((string) config("filesystems.disks.{$disk}.bucket"), '/');
                if ($bucket !== '' && str_starts_with($path, $bucket.'/')) {
                    return substr($path, strlen($bucket) + 1);
                }
            }
        }

        return null;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function isMinio(string $storageUrl): bool
    {
        return str_starts_with(trim($storageUrl), 'minio://');
    }

    private function minioHas(string $path): bool
    {
        try {
            return Storage::disk(self::MINIO)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
