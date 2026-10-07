<?php

namespace App\Console\Commands;

use App\Services\Create\CreateStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Schema, Storage};

class MigrateCreateStorage extends Command
{
    protected $signature = 'create:migrate-storage
        {--apply : Copy and verify bytes; otherwise only inventory local files}
        {--prefix= : One durable prefix, such as uploads or previews; defaults to all}
        {--after= : Resume after this logical path, in bytewise sort order}
        {--limit=100 : Maximum files in this batch (1 to 10000)}';
    protected $description = 'Inventory or copy Create files to its private bucket, preserving every local original';

    public function handle(CreateStorage $storage): int
    {
        $prefix = (string) $this->option('prefix');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (($prefix && ! in_array($prefix, CreateStorage::PREFIXES, true)) || ! $limit || $limit < 1 || $limit > 10000) {
            $this->error('Choose a durable prefix and a batch limit from 1 to 10000.');
            return self::FAILURE;
        }
        if ($this->option('apply') && ! Schema::hasTable('create_stored_files')) {
            $this->error('Apply the Create storage catalog migration first.');
            return self::FAILURE;
        }
        $local = Storage::disk('local');
        $files = [];
        foreach ($prefix ? [$prefix] : CreateStorage::PREFIXES as $p) {
            $files = array_merge($files, $local->allFiles('create/'.$p));
        }
        sort($files, SORT_STRING);
        $after = (string) $this->option('after');
        $files = array_values(array_filter($files, fn ($p) => strcmp($p, $after) > 0));
        $batch = array_slice($files, 0, $limit);
        $bytes = 0;
        foreach ($batch as $path) {
            try {
                $bytes += $local->size($path);
                $result = $this->option('apply') ? $storage->migrate($path) : 'inventory';
                $this->line($result.' '.$path);
            } catch (\Throwable $e) {
                report($e);
                $this->error('Migration stopped at '.$path.'. Originals retained; inspect the application log and rerun this batch.');
                return self::FAILURE;
            }
        }
        $this->info(count($batch).' files; '.$bytes.' local bytes. No originals deleted.');
        if (count($files) > count($batch)) $this->line('Next --after='.end($batch));
        if (! $this->option('apply')) $this->comment('Inventory only. Drain Create writes on every host before using --apply.');
        return self::SUCCESS;
    }
}
