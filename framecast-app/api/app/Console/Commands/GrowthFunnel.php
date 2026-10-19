<?php

namespace App\Console\Commands;

use App\Services\Analytics\Funnel;
use Illuminate\Console\Command;

/** FUNNEL (CMO plan): each week's new workspaces through their first session, and where they stop. Read-only. */
class GrowthFunnel extends Command
{
    protected $signature = 'growth:funnel {--since= : First day (Y-m-d); default 8 weeks ago} {--hours=24 : The first session, in hours after sign-up} {--json : Print JSON}';

    protected $description = 'Signed up → started a video → approved → delivered → downloaded or shared, by week and by channel';

    private const LABELS = ['signed_up' => 'Signed up', 'started' => 'Started', 'approved' => 'Approved', 'delivered' => 'Delivered', 'downloaded_or_shared' => 'Kept (downloaded/shared)'];

    public function handle(): int
    {
        $since = $this->option('since') ?: date('Y-m-d', strtotime('monday this week -7 weeks'));
        $funnel = Funnel::weeks($since, max(1, (int) $this->option('hours')));
        if ($this->option('json')) {
            $this->line(json_encode($funnel));
            return self::SUCCESS;
        }
        $row = fn (string $label, array $counts) => array_merge([$label], array_map(fn ($step) => $this->cell($counts, $step), Funnel::STEPS));
        $this->line('First '.(int) $this->option('hours').' hours after sign-up, from '.$since.' (staff and the sample video left out).');
        $this->table(array_merge(['Week of'], array_values(self::LABELS)), array_merge(
            array_map(fn ($w) => $row($w['week'], $w), $funnel['weeks']),
            [$row('All', $funnel['total'])],
        ));
        $this->table(array_merge(['Channel'], array_values(self::LABELS)), array_map(fn ($c) => $row($c, $funnel['by_channel'][$c]), array_keys($funnel['by_channel'])));
        if ($drop = $funnel['biggest_drop']) {
            $this->line(sprintf('Biggest drop: %s → %s, %d people.', self::LABELS[$drop['from']], self::LABELS[$drop['to']], $drop['lost']));
        }

        return self::SUCCESS;
    }

    /** A step's count with its share of those who signed up. */
    private function cell(array $counts, string $step): string
    {
        $n = (int) ($counts[$step] ?? 0);
        $base = (int) ($counts['signed_up'] ?? 0);
        return $step === 'signed_up' || $base === 0 ? (string) $n : $n.' ('.round(100 * $n / $base).'%)';
    }
}
