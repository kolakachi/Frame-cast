<?php

namespace App\Console\Commands;

use App\Services\Analytics\Scoreboard;
use Illuminate\Console\Command;

/** CMO-4: the weekly growth scoreboard (docs/product/cmo-plan.md §1). Read-only. */
class GrowthScoreboard extends Command
{
    protected $signature = 'growth:scoreboard {--since= : First day (Y-m-d); default 8 weeks ago} {--json : Print JSON}';

    protected $description = 'Weekly sign-ups by source, first-day own videos, purchases, second purchases and refunds';

    public function handle(): int
    {
        $since = $this->option('since') ?: date('Y-m-d', strtotime('monday this week -7 weeks'));
        $board = Scoreboard::weeks($since);
        if ($this->option('json')) {
            $this->line(json_encode($board));
            return self::SUCCESS;
        }
        $this->table(['Week of', 'Sign-ups', 'Own video, day 1', 'Purchases', 'Bought again', 'Refunds', 'By channel', 'By utm_source'],
            array_map(fn ($w) => [$w['week'], $w['signups'], $w['own_video_first_day'], $w['purchases'], $w['bought_again'], $w['refunds'],
                collect($w['by_channel'])->map(fn ($n, $c) => "$c $n")->implode(', '), collect($w['by_source'])->map(fn ($n, $s) => "$s $n")->implode(', ')], $board['weeks']));
        $this->line('Teams that bought again, to date (the goal): '.$board['bought_again_to_date']);

        return self::SUCCESS;
    }
}
