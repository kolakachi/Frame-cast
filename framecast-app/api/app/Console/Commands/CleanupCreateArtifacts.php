<?php

namespace App\Console\Commands;

use App\Services\Create\ArtifactRetentionService;
use Illuminate\Console\Command;

class CleanupCreateArtifacts extends Command
{
    protected $signature = 'create:cleanup';
    protected $description = 'Remove orphaned local Create inputs/previews older than 24 hours; preserve saved versions and unknown work';

    public function handle(ArtifactRetentionService $retention): int
    {
        $this->info('Removed '.$retention->sweep().' orphaned private files.');
        return self::SUCCESS;
    }
}
