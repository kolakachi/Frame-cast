<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Schema, Storage};

/** Logical Create paths stay stable. A catalog pins the authoritative disk, key and digest per file. */
class CreateStorage
{
    public const PREFIXES = ['uploads', 'inputs', 'previews', 'references', 'reference-studies', 'image-jobs', 'posters'];
    private ?bool $hasCatalog = null;

    public function __construct(private PrivateBucketGuard $privacy) {}

    private function validate(string $path, bool $directory = false): void
    {
        $parts = explode('/', $path);
        if (strlen($path) > 1024 || preg_match('/[\x00-\x20\\\\]/', $path) || in_array('..', $parts, true)
            || in_array('.', $parts, true) || in_array('', $parts, true) || ($parts[0] ?? '') !== 'create'
            || ! in_array($parts[1] ?? '', self::PREFIXES, true) || (! $directory && count($parts) < 3)) {
            throw new \InvalidArgumentException('Invalid private Create file path.');
        }
    }

    private function catalog(): bool
    {
        return $this->hasCatalog ??= Schema::hasTable('create_stored_files');
    }

    private function record(string $path): ?object
    {
        $this->validate($path);
        return $this->catalog() ? DB::table('create_stored_files')->where('path_hash', hash('sha256', $path))->first() : null;
    }

    private function disk(string $name): \Illuminate\Filesystem\FilesystemAdapter
    {
        if ($name !== 'local') $this->privacy->assertPrivate($name);
        return Storage::disk($name);
    }

    public function exists(string $path): bool
    {
        $record = $this->record($path);
        if ($record?->deleted_at) return false;
        // A missing remote object is an error/missing object, never permission to serve an older local copy.
        return $this->disk($record->disk ?? 'local')->exists($record->object_key ?? $path);
    }

    public function get(string $path): ?string
    {
        if (! $this->exists($path)) return null;
        $bytes = file_get_contents($this->path($path));
        if ($bytes === false) throw new \RuntimeException('Could not read private Create file.');
        return $bytes;
    }

    public function readStream(string $path): mixed
    {
        return $this->exists($path) ? fopen($this->path($path), 'rb') : false;
    }

    /** Read-only materialization for ffmpeg and BinaryFileResponse. Never use this to write new objects. */
    public function path(string $path): string
    {
        app(LocalStorageUse::class)->hold();
        $record = $this->record($path);
        if ($record?->deleted_at) throw new \RuntimeException('Private Create file was deleted.');
        if (! $record || $record->disk === 'local') return Storage::disk('local')->path($record->object_key ?? $path);
        $disk = $this->disk($record->disk);
        $cache = Storage::disk('local')->path('create/object-cache/'.$record->sha256.'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'bin'));
        if (is_file($cache) && ! is_link($cache) && filesize($cache) === (int) $record->bytes
            && hash_equals($record->sha256, hash_file('sha256', $cache))) { touch($cache); return $cache; }
        if (! is_dir(dirname($cache)) && ! @mkdir(dirname($cache), 0700, true) && ! is_dir(dirname($cache))) {
            throw new \RuntimeException('Cannot create private Create cache.');
        }
        app(DiskSpace::class)->requireSpace(dirname($cache), (int) $record->bytes);
        $tmp = tempnam(dirname($cache), '.download-');
        if ($tmp === false) throw new \RuntimeException('Cannot stage private Create file.');
        try {
            $stream = $disk->readStream($record->object_key);
            if (! is_resource($stream)) throw new \RuntimeException('Private Create object is unavailable.');
            $out = fopen($tmp, 'wb');
            if (! $out) { fclose($stream); throw new \RuntimeException('Cannot stage private Create file.'); }
            try { $bytes = stream_copy_to_stream($stream, $out, (int) $record->bytes + 1); }
            finally { fclose($stream); fclose($out); }
            if ($bytes !== (int) $record->bytes || ! hash_equals($record->sha256, hash_file('sha256', $tmp))) {
                throw new \RuntimeException('Private Create object failed its integrity check.');
            }
            chmod($tmp, 0600);
            if (! rename($tmp, $cache)) throw new \RuntimeException('Could not stage private Create file.');
            return $cache;
        } finally { if (is_file($tmp)) unlink($tmp); }
    }

    public function put(string $path, mixed $contents, array $options = []): bool
    {
        return $this->write($path, $contents, (string) config('create.storage_disk', 'local'));
    }

    public function putFileAs(string $directory, mixed $file, string $name): string
    {
        $path = $directory.'/'.$name;
        $stream = fopen($file->getRealPath(), 'rb');
        if (! $stream) throw new \RuntimeException('Cannot read Create upload.');
        try { $this->put($path, $stream); } finally { fclose($stream); }
        return $path;
    }

    private function write(string $path, mixed $contents, string $diskName, bool $migration = false): bool
    {
        $this->validate($path);
        app(LocalStorageUse::class)->hold();
        if ($diskName !== 'local' && ! $this->catalog()) throw new \RuntimeException('Run the Create storage migration before enabling remote storage.');
        $disk = $this->disk($diskName); // Verify privacy before uploading any bytes.
        app(DiskSpace::class)->requireSpace(sys_get_temp_dir(), is_string($contents) ? strlen($contents) : 8388608);
        $tmp = tmpfile();
        if (! $tmp) throw new \RuntimeException('Cannot stage Create upload.');
        try {
            if (is_resource($contents)) {
                while (! feof($contents)) {
                    app(DiskSpace::class)->requireSpace(sys_get_temp_dir(), 8388608);
                    $copied = stream_copy_to_stream($contents, $tmp, 8388608);
                    if ($copied === false || ($copied === 0 && ! feof($contents))) throw new \RuntimeException('Could not stage Create upload.');
                }
            } elseif (is_string($contents)) {
                if (fwrite($tmp, $contents) !== strlen($contents)) throw new \RuntimeException('Could not stage Create upload.');
            } else throw new \InvalidArgumentException('Create storage accepts bytes or streams.');
            fflush($tmp);
            $file = stream_get_meta_data($tmp)['uri'];
            $sha = hash_file('sha256', $file); $bytes = filesize($file);
            if ($diskName === 'local') app(DiskSpace::class)->requireSpace(Storage::disk('local')->path(''), $bytes);
            $key = $diskName === 'local' ? $path : 'create/objects/'.hash('sha256', $path).'/'.$sha;
            rewind($tmp);
            if (! $disk->put($key, $tmp, ['visibility' => 'private'])) throw new \RuntimeException('Private Create upload failed.');
            if ($diskName !== 'local') $this->verifyObject($diskName, $key, $sha, $bytes);
            if ($this->catalog()) {
                DB::transaction(function () use ($path, $diskName, $key, $sha, $bytes, $migration) {
                    $hash = hash('sha256', $path);
                    $old = DB::table('create_stored_files')->where('path_hash', $hash)->lockForUpdate()->first();
                    if ($migration) {
                        // Migrations run with admissions drained; still refuse a concurrent delete or changed source.
                        if ($old && ($old->deleted_at || $old->disk !== 'local')) throw new \RuntimeException('Create file changed during migration.');
                        $source = Storage::disk('local')->path($path);
                        if (! is_file($source) || is_link($source) || ! hash_equals($sha, hash_file('sha256', $source))) throw new \RuntimeException('Local source changed during migration.');
                    }
                    DB::table('create_stored_files')->updateOrInsert(['path_hash' => $hash], [
                        'path' => $path, 'disk' => $diskName, 'object_key' => $key, 'sha256' => $sha, 'bytes' => $bytes,
                        'deleted_at' => null, 'created_at' => $old->created_at ?? now(), 'updated_at' => now(),
                    ]);
                });
            }
            return true;
        } finally { fclose($tmp); }
    }

    private function verifyObject(string $disk, string $key, string $sha, int $bytes): void
    {
        $stream = $this->disk($disk)->readStream($key);
        if (! is_resource($stream)) throw new \RuntimeException('Cannot verify private Create upload.');
        try { $hash = hash_init('sha256'); $count = hash_update_stream($hash, $stream, $bytes + 1); }
        finally { fclose($stream); }
        if ($count !== $bytes || ! hash_equals($sha, hash_final($hash))) throw new \RuntimeException('Private Create upload failed verification; original file preserved.');
    }

    /** Copy and verify; intentionally never remove the original local file. */
    public function migrate(string $path): string
    {
        $record = $this->record($path);
        if ($record?->deleted_at) return 'deleted';
        if ($record && $record->disk !== 'local') {
            $this->verifyObject($record->disk, $record->object_key, $record->sha256, (int) $record->bytes);
            return 'verified';
        }
        $source = Storage::disk('local')->path($path);
        if (! is_file($source) || is_link($source)) throw new \RuntimeException('Local migration source is unavailable.');
        $stream = fopen($source, 'rb');
        if (! $stream) throw new \RuntimeException('Cannot open local migration source.');
        try { $this->write($path, $stream, 'create_private', true); } finally { fclose($stream); }
        return 'copied';
    }

    public function delete(string $path): bool
    {
        app(LocalStorageUse::class)->hold();
        $record = $this->record($path);
        if ($record?->deleted_at) return true;
        if (! $record) return Storage::disk('local')->delete($path);
        // Record deletion before removing bytes so leftover legacy copies cannot reappear as a fallback.
        DB::table('create_stored_files')->where('path_hash', $record->path_hash)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return $this->disk($record->disk)->delete($record->object_key);
    }

    public function size(string $path): int
    {
        $record = $this->record($path);
        if ($record?->deleted_at) throw new \RuntimeException('Private Create file was deleted.');
        return $record ? (int) $record->bytes : Storage::disk('local')->size($path);
    }

    public function lastModified(string $path): int
    {
        $record = $this->record($path);
        return $record && $record->disk !== 'local' ? strtotime($record->updated_at) : Storage::disk('local')->lastModified($path);
    }

    public function allFiles(string $directory): array
    {
        $this->validate($directory, true);
        $files = array_fill_keys(Storage::disk('local')->allFiles($directory), true);
        if ($this->catalog()) foreach (DB::table('create_stored_files')->where('path', 'like', $directory.'/%')->cursor() as $record) {
            // LIKE is only a prefilter: underscore and percent may occur in a logical name.
            if (! str_starts_with($record->path, $directory.'/')) continue;
            if ($record->deleted_at) unset($files[$record->path]); else $files[$record->path] = true;
        }
        return array_keys($files);
    }

    /** Reclaim host-local bytes only. Saved objects/catalog entries are never deleted by this operation. */
    public function maintainLocal(bool $apply = false, int $limit = 100, bool $originals = false): array
    {
        if ($limit < 1 || $limit > 10000) throw new \InvalidArgumentException('Choose a cleanup limit from 1 to 10000.');
        $report = ['apply' => $apply, 'busy' => false, 'candidates' => 0, 'removed_files' => 0, 'removed_bytes' => 0, 'errors' => 0];
        $lock = LocalStorageUse::open();
        if (! flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return [...$report, 'busy' => true]; }
        try {
            $local = Storage::disk('local');
            $cache = $local->path('create/object-cache');
            // Never follow a symlink into a directory outside Create's own cache.
            if (is_link($cache)) throw new \RuntimeException('Invalid Create cache directory.');
            $cutoff = time() - max(1, (int) config('create.cache_retention_hours', 24)) * 3600;
            foreach (array_merge(glob($cache.'/*') ?: [], glob($cache.'/.download-*') ?: []) as $file) {
                if ($report['candidates'] >= $limit) break;
                if (! is_file($file) || is_link($file) || filemtime($file) >= $cutoff
                    || ! preg_match('/^(?:[a-f0-9]{64}\.[a-z0-9]+|\.download-[a-zA-Z0-9]+)$/D', basename($file))) continue;
                $report['candidates']++;
                if ($apply) {
                    $bytes = filesize($file);
                    if (unlink($file)) { $report['removed_files']++; $report['removed_bytes'] += $bytes; }
                    else $report['errors']++;
                }
            }
            if ($originals && $this->catalog()) foreach (self::PREFIXES as $prefix) {
                foreach ($local->allFiles('create/'.$prefix) as $path) {
                    if ($report['candidates'] >= $limit) break 2;
                    try {
                        DB::transaction(function () use ($path, $local, $apply, &$report) {
                            $this->validate($path);
                            $r = DB::table('create_stored_files')->where('path_hash', hash('sha256', $path))->lockForUpdate()->first();
                            if (! $r || $r->disk !== 'create_private' || $r->deleted_at) return;
                            $file = $local->path($path);
                            if (! is_file($file) || is_link($file)) return;
                            $report['candidates']++;
                            if (! $apply) return; // Inventory makes no B2 requests.
                            if (filesize($file) !== (int) $r->bytes || ! hash_equals($r->sha256, hash_file('sha256', $file))) {
                                throw new \RuntimeException('Local Create copy differs from its catalog; retained.');
                            }
                            // Re-read the actual object now. An earlier successful migration is insufficient proof.
                            $this->verifyObject($r->disk, $r->object_key, $r->sha256, (int) $r->bytes);
                            if (! unlink($file)) throw new \RuntimeException('Could not reclaim verified Create copy.');
                            $report['removed_files']++; $report['removed_bytes'] += (int) $r->bytes;
                        });
                    } catch (\Throwable $e) { $report['errors']++; report($e); }
                }
            }
            return $report;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
