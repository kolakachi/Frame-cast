<?php
namespace App\Console\Commands;
use App\Services\Create\{ProviderReceiptVerifier,ReconciliationService};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ReconcileCreateAttempt extends Command
{
    protected $signature='create:reconcile-attempt {attempt} {--worker-stopped} {--cost-microusd=} {--billing-reference=}';
    protected $description='Verify an interrupted Replicate prediction and reconcile operator-confirmed billing without regenerating';
    public function handle(ProviderReceiptVerifier $verifier, ReconciliationService $service): int {
        if (!config('create.enabled') || !$this->option('worker-stopped')) {
            $this->error('Local only. Confirm the host worker stopped before reconciliation.'); return self::FAILURE;
        }
        try {
            $attempt=DB::table('composition_attempts')->where('id',$this->argument('attempt'))->firstOrFail();
            $cost=$this->option('cost-microusd');
            if (!is_string($cost) || !ctype_digit($cost) || strlen($cost)>15) throw new \RuntimeException('Supply the actual billing cost in integer micro-USD.');
            $receipt=$verifier->verify($attempt,(int)$cost,$this->option('billing-reference'));
            $result=$service->reconcile($receipt,true);
            $this->info($result['replayed']?'Receipt already applied; no debit repeated.':'Receipt reconciled. No provider work was repeated.');
            return self::SUCCESS;
        } catch (\Throwable $e) { $this->error('Reconciliation stopped; hold retained. '.$e->getMessage());return self::FAILURE; }
    }
}
