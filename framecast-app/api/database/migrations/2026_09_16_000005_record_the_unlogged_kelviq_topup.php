<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Write the ledger row for a top-up that was paid for and delivered but never
 * recorded.
 *
 * Workspace 45 bought the 5,000-credit pack through Kelviq on 2026-09-16
 * (webhook `checkout.completed`, plan wyvstudio-new-topup-5000, $70). The
 * credits reached the balance; the ledger row did not, and `grant:topup_kelviq`
 * has never appeared in the ledger at all. So the customer has their credits
 * and no record of buying them — their credit history simply skips the
 * purchase, and `credits:verify` reports the workspace as 5,000 adrift.
 *
 * This writes the missing row. It does not touch the balance, which was always
 * correct; the discrepancy was only ever in the telling.
 *
 * Guarded on the row being absent and the drift being exactly what it should
 * be, so re-running it — or running it anywhere the state differs — does
 * nothing.
 */
return new class extends Migration
{
    private const WORKSPACE_ID = 45;
    private const CREDITS = 5000;
    private const PAID_AT = '2026-09-16 01:01:29';

    public function up(): void
    {
        $exists = DB::table('credit_ledger')
            ->where('workspace_id', self::WORKSPACE_ID)
            ->where('operation', 'grant:topup_kelviq')
            ->exists();

        if ($exists) {
            return;
        }

        $workspace = DB::table('workspaces')->where('id', self::WORKSPACE_ID)
            ->first(['credits_monthly', 'credits_topup']);
        $last = DB::table('credit_ledger')->where('workspace_id', self::WORKSPACE_ID)
            ->orderByDesc('id')->first(['balance_after']);

        if (! $workspace || ! $last) {
            return;
        }

        $balance = (int) $workspace->credits_monthly + (int) $workspace->credits_topup;

        // Only correct the discrepancy this migration was written for. If the
        // numbers have moved on, the right row is no longer knowable from here.
        if ($balance - (int) $last->balance_after !== self::CREDITS) {
            return;
        }

        DB::table('credit_ledger')->insert([
            'workspace_id'  => self::WORKSPACE_ID,
            'operation'     => 'grant:topup_kelviq',
            'credits'       => -self::CREDITS,   // negative: credits arriving
            'balance_after' => $balance,
            'metadata'      => json_encode([
                'reason' => 'topup_kelviq',
                'reconstructed' => true,
                'note' => 'Paid and delivered; ledger row lost at the time. Restored by migration.',
            ]),
            'created_at'    => self::PAID_AT,
            'updated_at'    => self::PAID_AT,
        ]);
    }

    public function down(): void
    {
        DB::table('credit_ledger')
            ->where('workspace_id', self::WORKSPACE_ID)
            ->where('operation', 'grant:topup_kelviq')
            ->whereRaw("metadata->>'reconstructed' = 'true'")
            ->delete();
    }
};
