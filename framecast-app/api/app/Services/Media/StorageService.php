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

    public function isCreatePrivate(string $url): bool { return str_starts_with($url, 'create-private://') || str_starts_with($url, 'create-upload://'); }

    private function createPath(string $url): string
    {
        if (str_starts_with($url, 'create-upload://')) {
            abort_unless(preg_match('~^create-upload://([1-9][0-9]*/[a-f0-9-]{36}/[a-f0-9]{64}\.(?:png|jpg|webp|svg|mp4|webm|mp3|wav))$~D', $url, $matches),422);
            return 'create/uploads/'.$matches[1];
        }
        abort_unless(preg_match('~^create-private://([a-f0-9-]{36}/[a-f0-9]{64}\.(?:mp4|png|jpg|webp))$~D', $url, $matches), 422);
        return 'create/previews/'.$matches[1];
    }

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
        if ($this->isCreatePrivate($storageUrl)) { return false; /* Immutable bytes are managed by Create retention. */ }
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
    /**
     * The signed link to a private Create file, for an asset already in hand. The same link for five minutes at a
     * time, so a page that refreshes every few seconds keeps its cached image instead of downloading it again under a
     * new signature. Every link is valid for 5 to 10 minutes.
     */
    public function createPrivateUrl(int $assetId): string
    {
        $expires = \Illuminate\Support\Carbon::createFromTimestamp((intdiv(now()->timestamp, 300) + 2) * 300);
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('media.assets.content',$expires,['assetId'=>$assetId]);
    }

    public function url(string $storageUrl): string
    {
        if ($this->isCreatePrivate($storageUrl)) {
            $asset = \App\Models\Asset::where('storage_url',$storageUrl)->where('status','!=','archived')->firstOrFail();
            return $this->createPrivateUrl((int) $asset->id);
        }
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
        if ($this->isCreatePrivate($storageUrl)) { return app(\App\Services\Create\CreateStorage::class)->readStream($this->createPath($storageUrl)); }
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
     * Byte size of the stored object, or null when it can't be determined.
     *
     * Media playback needs this: a Range request can't be answered without a
     * total, and Safari won't start an <audio> element it can't range into.
     */
    public function size(string $storageUrl): ?int
    {
        if ($this->isCreatePrivate($storageUrl)) { return app(\App\Services\Create\CreateStorage::class)->exists($this->createPath($storageUrl)) ? app(\App\Services\Create\CreateStorage::class)->size($this->createPath($storageUrl)) : null; }
        $path = $this->extractPath($storageUrl);

        if ($path === null) {
            return null;
        }

        try {
            $disk = ($this->isMinio($storageUrl) || $this->minioHas($path)) ? self::MINIO : self::B2;

            return Storage::disk($disk)->size($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get raw file contents. Tries MinIO first for legacy URLs, falls back to B2.
     */
    public function get(string $storageUrl): ?string
    {
        if ($this->isCreatePrivate($storageUrl)) { return app(\App\Services\Create\CreateStorage::class)->get($this->createPath($storageUrl)); }
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
        if ($this->isCreatePrivate($storageUrl)) { return app(\App\Services\Create\CreateStorage::class)->exists($this->createPath($storageUrl)); }
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
        if ($this->isCreatePrivate($storageUrl)) { return true; }
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
        if ($this->isCreatePrivate($storageUrl)) { return $this->createPath($storageUrl); }
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
