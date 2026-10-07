<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\Storage;

/** A shared lock lives for the request/job, including subprocesses using paths returned by CreateStorage. */
class LocalStorageUse
{
    private array $handles = [];

    public static function open(): mixed
    {
        $dir = Storage::disk('local')->path('create');
        if (is_link($dir)) throw new \RuntimeException('Invalid Create storage directory.');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) throw new \RuntimeException('Create storage is unavailable.');
        $path = $dir.'/storage-use.lock';
        if (is_link($path)) throw new \RuntimeException('Invalid Create storage lock.');
        $handle = fopen($path, 'c');
        if (! $handle) throw new \RuntimeException('Create storage is unavailable.');
        return $handle;
    }

    public function hold(): void
    {
        $root = Storage::disk('local')->path('');
        if (isset($this->handles[$root])) return;
        $handle = self::open();
        if (! flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            abort(503, 'Storage maintenance is in progress. Your saved work is safe. Please try again shortly.');
        }
        $this->handles[$root] = $handle;
    }

    public function __destruct()
    {
        foreach ($this->handles as $handle) { flock($handle, LOCK_UN); fclose($handle); }
    }
}
