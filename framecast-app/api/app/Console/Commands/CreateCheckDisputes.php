<?php

namespace App\Console\Commands;

use App\Services\Create\CheckDisputes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** CHECK-GUARD: review the checks builds disputed or failed on; marking one wrong refunds the failed build. */
class CreateCheckDisputes extends Command
{
    protected $signature = 'create:check-disputes {--wrong= : Id whose check was mistaken (refunds a failed build)} {--right= : Id whose check was correct} {--all : Include decided ones}';

    protected $description = 'Review the Create checks that builds disputed or failed on';

    public function handle(): int
    {
        foreach (['wrong', 'right'] as $verdict) {
            if ($id = $this->option($verdict)) {
                $r = CheckDisputes::decide((int) $id, $verdict);
                $this->line(! $r ? 'No such id.' : ($r['refunded'] ? "Marked {$verdict}; refunded {$r['refunded']} credits to ws {$r['workspace_id']}." : "Marked {$verdict}."));
            }
        }
        $rows = DB::table('create_check_disputes')->when(! $this->option('all'), fn ($q) => $q->where('verdict', 'pending'))->orderByDesc('id')->limit(50)->get();
        foreach ($rows as $r) {
            $credits = $r->outcome === 'failed' ? ' · would refund '.CheckDisputes::refundable($r->run_id).' cr' : '';
            $this->line(sprintf("#%d [%s] %s on %s · ws %d · seen at %s%s\n    %s\n    %s", $r->id, $r->verdict, $r->code, $r->outcome, $r->workspace_id,
                implode(', ', json_decode((string) $r->seen_at, true) ?: []), $credits, mb_substr((string) $r->message, 0, 200), config('app.frontend_url').'/create/'.$r->conversation_id));
        }
        $this->line($rows->count().' shown.');

        return self::SUCCESS;
    }
}
