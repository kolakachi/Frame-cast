<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the existing transfer rows the sign their operation means.
 *
 * Both sides of a transfer were written with abs(), so the ledger said the
 * credits left the agency AND left the client — the same credits leaving two
 * places at once. Spend queries never noticed because transfers are excluded
 * from them, but any attempt to reconstruct a balance from the ledger would
 * have come out wrong, which is exactly what `credits:verify` now does.
 *
 * Convention, shared with deduct() and grant(): positive left this workspace,
 * negative arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Credits arriving: into the client when funded, back to the agency on
        // a reclaim. Both were recorded positive.
        DB::table('credit_ledger')->where('operation', 'transfer:agency_funding')
            ->where('credits', '>', 0)
            ->update(['credits' => DB::raw('-credits')]);

        DB::table('credit_ledger')->where('operation', 'transfer:client_reclaim')
            ->where('credits', '>', 0)
            ->update(['credits' => DB::raw('-credits')]);

        // Credits leaving the client on a reclaim: correct already, but make it
        // explicit rather than relying on it.
        DB::table('credit_ledger')->where('operation', 'transfer:agency_reclaim')
            ->where('credits', '<', 0)
            ->update(['credits' => DB::raw('ABS(credits)')]);
    }

    public function down(): void
    {
        foreach (['transfer:agency_funding', 'transfer:client_reclaim'] as $op) {
            DB::table('credit_ledger')->where('operation', $op)
                ->where('credits', '<', 0)
                ->update(['credits' => DB::raw('ABS(credits)')]);
        }
    }
};
