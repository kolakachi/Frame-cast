<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Create\References\ReferenceStudy;
use App\Services\Media\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Reads one reference video both ways (todo G2): every sheet to the creative model, and the split (cheap per-frame
 * facts, then the creative model on facts and motion frames). Nothing is saved; the comparison is printed, and the
 * full readings written next to each other for a side-by-side look.
 */
class CreateStudyAb extends Command
{
    protected $signature = 'create:study-ab {asset : A reference video asset id} {--out= : Directory for the two readings (JSON)}';
    protected $description = 'Compare the full and the split reading of a reference video: moments, text, moves, systems, cost and time';

    public function handle(): int
    {
        $asset = Asset::find((int) $this->argument('asset'));
        if (! $asset || $asset->asset_type !== 'video') { $this->error('Not a video asset.'); return self::FAILURE; }
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        $sha = hash('sha256', (string) $bytes);
        $out = [];
        foreach (['opus', 'split'] as $mode) {
            $dir = sys_get_temp_dir().'/create-ab-'.Str::uuid(); @mkdir($dir, 0700, true);
            file_put_contents($dir.'/in.mp4', $bytes);
            $study = app(ReferenceStudy::class); $study->readingMode = $mode;
            $t = microtime(true);
            $r = $study->study($dir.'/in.mp4', $sha.'-ab-'.$mode, $dir, 'maximum');
            $out[$mode] = ['seconds' => round(microtime(true) - $t), 'study' => $r];
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
        }
        if ($d = $this->option('out')) { @mkdir($d, 0755, true); foreach ($out as $m => $o) file_put_contents($d.'/'.$m.'.json', json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
        $row = fn ($m) => [$m, $out[$m]['seconds'].' s', '$'.round(($out[$m]['study']['cost_microusd'] ?? 0) / 1e6, 3), count($out[$m]['study']['moments'] ?? []),
            collect($out[$m]['study']['moments'] ?? [])->filter(fn ($x) => ($x['on_screen_text'] ?? '') !== '')->count(),
            collect($out[$m]['study']['moments'] ?? [])->pluck('move')->filter()->countBy()->map(fn ($n, $k) => $k.'×'.$n)->implode(' '),
            count($out[$m]['study']['systems'] ?? []), $out[$m]['study']['moments_status'] ?? '?'];
        $this->table(['reading', 'time', 'model cost', 'moments', 'with text', 'moves', 'systems', 'status'], [$row('opus'), $row('split')]);
        return self::SUCCESS;
    }
}
