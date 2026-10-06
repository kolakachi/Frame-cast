<?php
namespace App\Console\Commands;

use App\Services\Create\{ReconciliationService, VerifiedAttemptReceipt};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverCreateProviderReceipt extends Command
{
    protected $signature = 'create:recover-provider-receipt {attempt} {--worker-stopped : Confirm the original host and sandbox have stopped}';
    protected $description = 'Settle an interrupted Anthropic call from its app-saved response; never generate again';

    public function handle(): int
    {
        abort_unless(config('create.enabled') && $this->option('worker-stopped'), 403);
        $a = DB::table('composition_attempts')->where('id', $this->argument('attempt'))->firstOrFail();
        abort_unless($a->provider === 'anthropic' && $a->provider_response_json, 409, 'No saved Anthropic receipt. Keep the hold; locate provider evidence.');
        $saved = json_decode($a->provider_response_json, true);
        $body = json_decode($saved['body'] ?? '', true);
        abort_unless(($saved['status'] ?? 0) === 200 && preg_match('/^[A-Za-z0-9_-]{1,160}$/D', (string) ($body['id'] ?? '')), 409, 'No confirmed successful response.');
        $u = $body['usage'] ?? [];
        foreach (['input_tokens', 'output_tokens'] as $key) abort_unless(isset($u[$key]) && is_int($u[$key]) && $u[$key] >= 0, 409, 'Incomplete usage receipt.');
        $r = $saved['rates'] ?? null;
        abort_unless(is_array($r) && count(array_intersect(['input', 'output', 'cache_write', 'cache_read'], array_keys($r))) === 4, 409, 'Original metering rates are missing.');
        $cost = (int) ceil($u['input_tokens'] * $r['input'] + $u['output_tokens'] * $r['output']
            + ($u['cache_creation_input_tokens'] ?? 0) * $r['cache_write'] + ($u['cache_read_input_tokens'] ?? 0) * $r['cache_read']);
        abort_unless($cost <= $a->cost_limit_microusd, 409, 'Receipt exceeds the approved ceiling; manual review required.');
        $result = app(ReconciliationService::class)->reconcile(new VerifiedAttemptReceipt($a->id, 'succeeded', $body['id'], $cost,
            'pilot-tariff:2026-09-30; app-saved Anthropic response '.hash('sha256', $a->provider_response_json)), true);
        $this->info('Receipt recovered. Charged credits: '.$result['charged_credits'].'. No generation repeated.');
        return self::SUCCESS;
    }
}
