<?php

namespace App\Console\Commands;

use App\Services\Vendors\ModelBalance;
use Illuminate\Console\Command;

class CreateModelBalance extends Command
{
    protected $signature = 'create:model-balance {usd? : The Anthropic balance shown after a top-up, in dollars}';
    protected $description = 'Record the Anthropic balance after a top-up, or show what is estimated to be left';

    public function handle(): int
    {
        if (($usd = $this->argument('usd')) !== null) {
            if (! is_numeric($usd) || (float) $usd < 0 || (float) $usd > 100000) { $this->error('Give the balance in dollars, e.g. 200'); return self::FAILURE; }
            ModelBalance::record((float) $usd);
        }
        $e = ModelBalance::estimate();
        if (! $e) { $this->line('No balance recorded yet. After a top-up run: php artisan create:model-balance 200'); return self::SUCCESS; }
        $this->line(sprintf('About $%.2f left: $%.2f recorded %s, about $%.2f spent since. Warns below $%d.', $e['left'], $e['balance'], $e['set_at'], $e['spent'], (int) config('create.model_balance_warn_usd', 40)));
        return self::SUCCESS;
    }
}
