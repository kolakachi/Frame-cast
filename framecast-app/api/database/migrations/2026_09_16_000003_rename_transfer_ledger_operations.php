<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the transfer rows written before transfers had a name of their own.
 *
 * Funding a client and reclaiming from one were recorded as `client_funding`
 * and `agency_reclaim`, neither of which any spend query could distinguish
 * from a charge — so a client that had just been topped up appeared to have
 * already spent the money. The code now writes `transfer:*` and excludes that
 * prefix everywhere spend is summed; these rows predate it and would go on
 * being counted forever.
 *
 * Renaming rather than deleting: the movement did happen, and an agency
 * looking at its history should still see where its credits went.
 */
return new class extends Migration
{
    private const RENAMES = [
        'client_funding'      => 'transfer:client_funding',
        'agency_reclaim'      => 'transfer:agency_reclaim',
        'grant:client_refund' => 'transfer:client_reclaim',
        'grant:agency_funding' => 'transfer:agency_funding',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('credit_ledger')->where('operation', $from)->update(['operation' => $to]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('credit_ledger')->where('operation', $to)->update(['operation' => $from]);
        }
    }
};
