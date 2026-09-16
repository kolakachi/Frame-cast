<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Check that the credit ledger still explains the balances.
 *
 * The ledger is written best-effort — every write is wrapped in rescue() so a
 * logging failure can never cost a customer their generation — which is the
 * right trade and also means the ledger can silently fall behind the balances
 * it is supposed to explain. Nothing noticed that until somebody asked.
 *
 * Run it whenever the answer matters: `php artisan credits:verify`.
 */
class VerifyCreditLedger extends Command
{
    protected $signature = 'credits:verify {--workspace= : Check one workspace} {--quiet-ok : Print only problems}';

    protected $description = 'Reconcile credit balances against the ledger and report anything that does not add up';

    public function handle(): int
    {
        $problems = 0;
        $checked = 0;

        $workspaces = Workspace::query()
            ->when($this->option('workspace'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        $this->line('');
        $this->info('1. Does the last ledger row agree with the balance it claims?');
        foreach ($workspaces as $w) {
            $last = DB::table('credit_ledger')->where('workspace_id', $w->getKey())
                ->orderByDesc('id')->first(['id', 'balance_after']);
            if (! $last || $last->balance_after === null) {
                continue;
            }
            $checked++;

            // A pooled client's charges are written against its agency, so its
            // own last row (if any) describes the agency's balance, not its own.
            $actual = $w->parent_workspace_id && ! $w->isFunded()
                ? (int) ($w->parent?->creditsBalance() ?? 0)
                : (int) $w->creditsBalance();

            if ((int) $last->balance_after !== $actual) {
                $problems++;
                $this->warn(sprintf(
                    '   ws%-5d ledger says %s, balance is %s  (drift %+d, row #%d)',
                    $w->getKey(), number_format((int) $last->balance_after), number_format($actual),
                    $actual - (int) $last->balance_after, $last->id,
                ));
            }
        }
        $this->line("   checked {$checked} workspaces with ledger history");

        $this->line('');
        $this->info('2. Does every transfer have both of its sides?');
        $out = DB::table('credit_ledger')->where('operation', 'like', 'transfer:client_%')
            ->selectRaw('SUM(ABS(credits)) AS total, COUNT(*) AS n')->first();
        $in = DB::table('credit_ledger')->where('operation', 'like', 'transfer:agency_%')
            ->selectRaw('SUM(ABS(credits)) AS total, COUNT(*) AS n')->first();
        if ((int) ($out->n ?? 0) !== (int) ($in->n ?? 0) || (int) ($out->total ?? 0) !== (int) ($in->total ?? 0)) {
            $problems++;
            $this->warn(sprintf('   agency side %d rows / %s credits, client side %d rows / %s credits',
                $out->n ?? 0, number_format((int) ($out->total ?? 0)), $in->n ?? 0, number_format((int) ($in->total ?? 0))));
        } else {
            $this->line(sprintf('   %d pairs, %s credits each way', (int) ($out->n ?? 0), number_format((int) ($out->total ?? 0))));
        }

        $this->line('');
        $this->info('3. Do the signs mean what they should?');
        // Positive = left the workspace, negative = arrived.
        $wrong = DB::table('credit_ledger')
            ->where(fn ($q) => $q->where('operation', 'like', 'grant:%')->orWhere('operation', 'like', 'refund:%'))
            ->where('credits', '>', 0)->count();
        $wrong += DB::table('credit_ledger')
            ->where('operation', 'not like', 'grant:%')
            ->where('operation', 'not like', 'refund:%')
            ->where('operation', 'not like', 'transfer:%')
            ->where('credits', '<', 0)->count();
        if ($wrong > 0) {
            $problems++;
            $this->warn("   {$wrong} rows carry a sign that contradicts their operation");
        } else {
            $this->line('   grants and refunds arrive, charges leave');
        }

        $this->line('');
        $this->info('4. Do pooled clients hold credits they should not?');
        $holders = Workspace::query()->whereNotNull('parent_workspace_id')
            ->where('funding_mode', '!=', Workspace::FUNDING_FUNDED)
            ->where(fn ($q) => $q->where('credits_topup', '>', 0)->orWhere('credits_monthly', '>', 0))
            ->get(['id', 'credits_topup', 'credits_monthly']);
        if ($holders->isNotEmpty()) {
            $problems++;
            foreach ($holders as $h) {
                $this->warn("   ws{$h->id} is pooled but holds {$h->credits_topup} top-up / {$h->credits_monthly} monthly");
            }
        } else {
            $this->line('   none');
        }

        $this->line('');
        $this->info('5. Does every attributed charge point at a real client of that pool?');
        $orphans = DB::table('credit_ledger as l')
            ->whereNotNull('l.spent_by_workspace_id')
            ->whereNotExists(fn ($q) => $q->from('workspaces as w')
                ->whereColumn('w.id', 'l.spent_by_workspace_id')
                ->whereColumn('w.parent_workspace_id', 'l.workspace_id'))
            ->count();
        if ($orphans > 0) {
            $problems++;
            $this->warn("   {$orphans} rows name a spender that is not a client of the workspace charged");
        } else {
            $this->line('   all attributed charges trace to a client of the pool that paid');
        }

        $this->line('');
        if ($problems === 0) {
            $this->info('Ledger is consistent.');

            return self::SUCCESS;
        }

        $this->error("{$problems} check(s) found something. Nothing was changed — this command only reads.");

        return self::FAILURE;
    }
}
