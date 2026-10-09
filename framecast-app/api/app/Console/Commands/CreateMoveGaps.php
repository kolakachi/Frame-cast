<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Create\MotionMoves;
use Illuminate\Console\Command;

/**
 * What our moves cannot yet make (2026-10-09): every moment and recurring element of the reference videos users have
 * attached that the study labelled "custom" (none of the motion kit's moves reproduces it), grouped by kind with how
 * often it comes up. The list is the to-do for new kits. Read-only.
 */
class CreateMoveGaps extends Command
{
    protected $signature = 'create:move-gaps {--days=90 : Studies from the last N days} {--json : Print JSON}';
    protected $description = 'List the reference moments no motion-kit move reproduces, most frequent first';

    public function handle(): int
    {
        $known = array_keys(MotionMoves::MOVES);
        $rows = []; $studies = 0; $moments = 0;
        Asset::where('asset_type', 'video')->where('updated_at', '>=', now()->subDays((int) $this->option('days')))
            ->whereNotNull('metadata_json->reference_study')->orderByDesc('updated_at')
            ->chunk(100, function ($assets) use (&$rows, &$studies, &$moments, $known) {
                foreach ($assets as $a) {
                    $study = data_get($a->metadata_json, 'reference_study');
                    if (! is_array($study)) continue;
                    $studies++;
                    $items = array_merge(array_map(fn ($m) => $m + ['_kind' => $m['kind'] ?? 'other'], (array) ($study['moments'] ?? [])),
                        array_map(fn ($s) => $s + ['_kind' => 'system', 'motion' => trim(($s['entry'] ?? '').'; '.($s['active'] ?? ''), '; ')], (array) ($study['systems'] ?? [])));
                    foreach ($items as $m) {
                        $moments++;
                        $move = $m['move'] ?? null;
                        // Built by hand, or never named: a gap only when the motion is graphic work, not footage.
                        if (($move !== null && $move !== 'custom' && in_array($move, $known, true)) || in_array($m['method'] ?? '', ['footage', 'stock', 'presenter', 'screen_recording'], true)) continue;
                        $what = trim((string) ($m['motion'] ?? '')) ?: trim((string) ($m['visual'] ?? ''));
                        if ($what === '') continue;
                        $rows[] = ['kind' => $m['_kind'], 'method' => $m['method'] ?? '', 'motion' => mb_substr($what, 0, 140), 'asset_id' => $a->id];
                    }
                }
            });
        $byKind = collect($rows)->groupBy('kind')->map(fn ($g) => ['count' => $g->count(), 'examples' => $g->pluck('motion')->unique()->take(8)->values()->all()])->sortByDesc('count');
        if ($this->option('json')) { $this->line(json_encode(['studies' => $studies, 'moments' => $moments, 'gaps' => count($rows), 'by_kind' => $byKind], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); return self::SUCCESS; }
        $this->info("{$studies} studied references, {$moments} moments and elements, ".count($rows).' with no move that reproduces them.');
        foreach ($byKind as $kind => $g) {
            $this->line("\n{$kind} ({$g['count']})");
            foreach ($g['examples'] as $e) $this->line('  - '.$e);
        }
        return self::SUCCESS;
    }
}
