<?php

namespace App\Console\Commands;

use App\Services\Launch\LaunchCatalogue;
use App\Services\Launch\LaunchpadService;
use Illuminate\Console\Command;

/** Grant or revoke a Launchpad product by hand: tests, support, and sales that arrive before the IPN is wired. */
class LaunchGrantCommand extends Command
{
    protected $signature = 'launch:grant {email} {product : '.'fe, bundle, pro, agency or a bump key} {--receipt= : The sale id (default admin:<time>)} {--name=} {--revoke : Revoke the receipt (optionally only this product) instead} {--chargeback : With --revoke, record a chargeback}';

    protected $description = 'Grant (or revoke) a Launchpad launch product for an email';

    public function handle(LaunchpadService $launch): int
    {
        $product = (string) $this->argument('product');
        if (LaunchCatalogue::product($product) === null) {
            $this->error('Unknown product. One of: '.implode(', ', array_keys(LaunchCatalogue::PRODUCTS)));
            return self::FAILURE;
        }
        if ($this->option('revoke')) {
            if (! $this->option('receipt')) {
                $this->error('--revoke needs --receipt.');
                return self::FAILURE;
            }
            $rows = $launch->revoke((string) $this->option('receipt'), $product, $this->option('chargeback') ? 'chargeback' : 'refunded');
            $this->line(json_encode(array_map(fn ($r) => $r->only(['workspace_id', 'product', 'status', 'credits_revoked']), $rows)));
            return self::SUCCESS;
        }
        $out = $launch->grant((string) $this->argument('email'), $this->option('name'), $product,
            (string) ($this->option('receipt') ?: 'admin:'.now()->format('YmdHisv')), [], 'admin');
        $this->line(json_encode(['created' => $out['created']] + $out['purchase']->only(['workspace_id', 'product', 'receipt', 'credits_granted'])));
        return self::SUCCESS;
    }
}
