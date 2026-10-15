<?php

namespace App\Console\Commands;

use App\Services\Create\MissedDefects;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** RD-14: list the checks proposed from faults users found, and approve (optionally reworded) or reject them. */
class CreateMissedDefects extends Command
{
    protected $signature = 'create:missed-defects {--approve= : Id to approve} {--reject= : Id to reject} {--check= : Reworded check, with --approve} {--all : Include decided ones}';

    protected $description = 'Review checks proposed from defects the Create checks missed';

    public function handle(): int
    {
        if ($id = $this->option('approve')) {
            $ok = MissedDefects::decide((int) $id, 'approved', $this->option('check') ?: null);
            $this->line($ok ? 'Approved: every build\'s reviewer now checks it.' : 'No such id.');
        } elseif ($id = $this->option('reject')) {
            $this->line(MissedDefects::decide((int) $id, 'rejected') ? 'Rejected.' : 'No such id.');
        }
        $rows = DB::table('create_missed_defects')->when(! $this->option('all'), fn ($q) => $q->where('status', 'proposed'))->orderByDesc('id')->limit(50)->get();
        foreach ($rows as $r) $this->line(sprintf("#%d [%s] %s (%s, ws %d)\n    said: %s\n    check: %s", $r->id, $r->status, $r->defect, $r->area, $r->workspace_id, mb_substr($r->note, 0, 160), $r->proposed_check));
        $this->line(count(MissedDefects::approved()).' approved checks in use.');

        return self::SUCCESS;
    }
}
