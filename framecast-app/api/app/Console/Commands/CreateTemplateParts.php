<?php

namespace App\Console\Commands;

use App\Services\Create\TemplateLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Re-reads the parts of every template that starts from code (after partsOf learns something new, e.g. product footage). */
class CreateTemplateParts extends Command
{
    protected $signature = 'create:template-parts {--dry : Show what would change}';

    protected $description = 'Recompute the parts list of templates that start from a version';

    public function handle(): int
    {
        foreach (DB::table('create_templates')->whereNull('archived_at')->where('source', 'version')->whereNotNull('revision_id')->orderBy('position')->get() as $t) {
            $revision = DB::table('composition_revisions')->where('id', $t->revision_id)->first();
            if (! $revision) { $this->line("{$t->id}: version missing"); continue; }
            $parts = TemplateLibrary::partsOf($revision);
            $footage = count($parts['footage'] ?? []);
            if (! $this->option('dry')) DB::table('create_templates')->where('id', $t->id)->update(['parts_json' => json_encode($parts), 'updated_at' => now()]);
            $this->line(sprintf('%s: %s%s', $t->id, implode(', ', TemplateLibrary::summary($parts)), $footage ? '  <- product footage' : ''));
        }

        return self::SUCCESS;
    }
}
