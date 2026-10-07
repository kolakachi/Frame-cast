<?php

namespace App\Services\Vendors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Our Anthropic balance, estimated: the balance recorded after a top-up (`create:model-balance 200`), less what Create has
 * measured spending since (build and reviewer calls, planning), plus a margin for the small checking calls it does not
 * meter. Anthropic offers no balance API, so this warns before the account runs dry instead of after (2026-10-07 it ran
 * out mid-round and Create stopped for everyone until a top-up).
 */
class ModelBalance
{
    public const VENDOR = 'anthropic';
    /** Unmetered calls (file sorting, the final look, suggestions) on top of the metered spend. */
    public const MARGIN = 1.10;

    public static function record(float $usd): void
    {
        DB::table('vendor_balances')->updateOrInsert(['vendor' => self::VENDOR], ['balance_usd' => round($usd, 2), 'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{balance:float, set_at:string, spent:float, left:float}|null  null until a balance is recorded */
    public static function estimate(): ?array
    {
        if (! Schema::hasTable('vendor_balances')) return null;
        $row = DB::table('vendor_balances')->where('vendor', self::VENDOR)->first();
        if (! $row) return null;
        $calls = (int) DB::table('composition_attempts')->where('provider', 'anthropic')->where('created_at', '>=', $row->set_at)->sum('cost_microusd');
        // Planning stores its real cost in credits (4,000 micro-USD each).
        $planning = DB::table('create_plans')->where('created_at', '>=', $row->set_at)->pluck('plan_json')
            ->sum(fn ($j) => (int) (json_decode((string) $j, true)['planning_charge']['cost_credits'] ?? 0)) * 4000;
        $spent = round(($calls + $planning) / 1_000_000 * self::MARGIN, 2);
        return ['balance' => (float) $row->balance_usd, 'set_at' => (string) $row->set_at, 'spent' => $spent, 'left' => round((float) $row->balance_usd - $spent, 2)];
    }
}
