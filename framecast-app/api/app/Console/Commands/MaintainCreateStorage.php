<?php

namespace App\Console\Commands;

use App\Services\Create\{CreateStorage, DiskSpace};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MaintainCreateStorage extends Command
{
    protected $signature = 'create:maintain-storage {--apply : Reclaim eligible local bytes}
        {--originals : Also verify and reclaim local files already catalogued on private B2}
        {--limit=100 : Maximum candidate files per invocation}';
    protected $description = 'Inspect disk capacity and reclaim idle cache/verified local copies; dry run by default';

    public function handle(CreateStorage $storage, DiskSpace $space): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 10000) { $this->error('Limit must be 1 to 10000.'); return self::FAILURE; }
        $report = $storage->maintainLocal((bool) $this->option('apply'), $limit, (bool) $this->option('originals'));
        $report['storage'] = $space->inspect(Storage::disk('local')->path(''));
        $report['scratch'] = $space->inspect(sys_get_temp_dir());
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $report['errors'] || ! $report['storage']['ok'] || ! $report['scratch']['ok'] ? self::FAILURE : self::SUCCESS;
    }
}
