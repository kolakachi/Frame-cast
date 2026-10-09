<?php

namespace App\Console\Commands;

use App\Jobs\MakeCreatePoster;
use App\Services\Create\CreateStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Frames for Create versions made before posters existed (D6, 2026-10-09): each live conversation's current version. */
class MakeCreatePosters extends Command
{
    protected $signature = 'create:posters {--limit=500 : Most versions in this run}';
    protected $description = 'Cut a poster frame for each Create video that has none';

    public function handle(CreateStorage $storage): int
    {
        $made = 0;
        $ids = DB::table('create_conversations')->join('composition_revisions', 'composition_revisions.id', '=', 'create_conversations.head_revision_id')
            ->whereNull('create_conversations.archived_at')->where('composition_revisions.artifact_path', 'like', '%.mp4')
            ->orderByDesc('create_conversations.updated_at')->limit(max(1, (int) $this->option('limit')))->pluck('composition_revisions.id');
        foreach ($ids as $id) {
            if ($storage->exists(MakeCreatePoster::path($id))) continue;
            MakeCreatePoster::dispatchSync($id);
            if ($storage->exists(MakeCreatePoster::path($id))) $made++;
        }
        $this->info("Made {$made} poster frames for {$ids->count()} videos.");
        return self::SUCCESS;
    }
}
