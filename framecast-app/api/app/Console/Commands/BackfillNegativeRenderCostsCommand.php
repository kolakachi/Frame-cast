<?php

namespace App\Console\Commands;

use App\Models\ApiUsageEvent;
use Illuminate\Console\Command;

/**
 * One-off repair for export render rows written with a negative cost.
 *
 * `ConcatenateExportJob` computed the render duration as
 * `now()->diffInSeconds($startedAt)`. That returned an absolute value under
 * Carbon 2, but Carbon 3 returns a SIGNED diff — so every render since the
 * upgrade stored a negative `render_seconds`, which dragged
 * `estimated_cost_usd` below zero and understated COGS.
 *
 * The magnitude survived in `metadata_json.render_seconds`, so the correct
 * cost is recomputed exactly rather than estimated. Idempotent: only rows with
 * a negative cost are touched.
 */
class BackfillNegativeRenderCostsCommand extends Command
{
    protected $signature = 'usage:backfill-negative-render-costs {--dry-run : Report what would change without writing}';

    protected $description = 'Repair export render usage rows that recorded a negative estimated cost';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = ApiUsageEvent::query()
            ->where('operation', 'render')
            ->where('estimated_cost_usd', '<', 0)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $before = 0.0;
        $after  = 0.0;
        $table  = [];

        foreach ($rows as $row) {
            $meta = $row->metadata_json ?? [];

            // The stored duration is the negated true value; the file size is
            // untouched. Fall back to the row's own units if metadata is gone.
            $seconds = (int) abs((int) ($meta['render_seconds'] ?? $row->units ?? 0));
            $bytes   = (int) ($meta['file_size_bytes'] ?? 0);

            $cost = round($seconds * 0.0001 + ($bytes / 1_048_576) * 0.00001, 6);

            $before += (float) $row->estimated_cost_usd;
            $after  += $cost;

            $table[] = [
                $row->getKey(),
                $row->workspace_id,
                number_format((float) $row->estimated_cost_usd, 6),
                number_format($cost, 6),
                $seconds,
            ];

            if (! $dryRun) {
                $meta['render_seconds'] = $seconds;
                $meta['cost_backfilled'] = true;

                $row->forceFill([
                    'estimated_cost_usd' => $cost,
                    // record() clamped this to 0 on the way in; restore it.
                    'units'              => $seconds,
                    'metadata_json'      => $meta,
                ])->save();
            }
        }

        $this->table(['id', 'workspace', 'old_cost', 'new_cost', 'seconds'], $table);
        $this->info(sprintf(
            '%s %d row(s). Total %s => %s (delta %s).',
            $dryRun ? 'Would repair' : 'Repaired',
            $rows->count(),
            number_format($before, 6),
            number_format($after, 6),
            number_format($after - $before, 6),
        ));

        return self::SUCCESS;
    }
}
