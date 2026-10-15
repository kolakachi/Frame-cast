<?php

namespace App\Console\Commands;

use App\Services\Ugc\UgcLearn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** LEARN: merge the recent take reviews into the lessons list the director, planner and sheet writer read. */
class UgcLearnLessons extends Command
{
    protected $signature = 'ugc:learn-lessons {--show : Print the lessons in use without changing them}';

    protected $description = 'Merge recent UGC take reviews into the lessons list';

    public function handle(UgcLearn $learn): int
    {
        if (! $this->option('show')) {
            // Only when there is something new since the last list.
            $last = DB::table('ugc_lessons')->orderByDesc('id')->value('created_at');
            $fresh = DB::table('ugc_take_reviews')->when($last, fn ($q) => $q->where('created_at', '>', $last))->count();
            if ($fresh === 0) {
                $this->info('No new reviews since the last lessons list.');
            } elseif ($learn->consolidate() === null) {
                $this->warn('The lessons could not be written this time.');
            }
        }
        foreach (UgcLearn::current() as $l) $this->line(sprintf('[%s] %s (seen %d)', $l['role'], $l['lesson'], $l['seen'] ?? 0));

        return self::SUCCESS;
    }
}
