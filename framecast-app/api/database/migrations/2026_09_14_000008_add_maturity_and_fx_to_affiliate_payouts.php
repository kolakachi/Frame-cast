<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions the payout flow could not express.
 *
 * Maturity: a commission was owed the instant a sale landed, which is inside
 * our own 14-day refund window. `eligible_at` is stamped per row rather than
 * computed from config, so changing the policy later cannot retroactively move
 * money that an affiliate has already been told is coming.
 *
 * Currency: commissions accrue in USD and arrive in naira. The rate used is
 * recorded on the payout and never recomputed — a statement reprinted next
 * year has to show the arithmetic that was actually performed, not today's
 * rate applied to last year's total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->timestamp('eligible_at')->nullable()->after('payout_status');
            $table->index(['affiliate_id', 'payout_status', 'eligible_at']);
        });

        // Existing rows get the same rule applied from their own sale date, so
        // history reads consistently rather than showing a cliff at deploy.
        $hold = (int) config('affiliates.hold_days', 21);
        DB::table('affiliate_conversions')->whereNull('eligible_at')->update([
            'eligible_at' => DB::raw("created_at + interval '{$hold} days'"),
        ]);

        Schema::table('affiliate_payouts', function (Blueprint $table) {
            // What was actually sent, in the currency it was sent in.
            $table->string('payout_currency', 8)->nullable()->after('currency');
            $table->decimal('payout_amount', 14, 2)->nullable()->after('payout_currency');

            // The arithmetic, preserved. 6dp because USD/NGN is in the
            // thousands and a rounded rate does not reproduce the total.
            $table->decimal('fx_rate', 18, 6)->nullable()->after('payout_amount');
            $table->string('fx_source', 40)->nullable()->after('fx_rate');
            $table->timestamp('fx_captured_at')->nullable()->after('fx_source');

            // Quoted back by the bank; what reconciles a statement to a
            // transfer that has actually cleared.
            $table->string('payment_reference', 120)->nullable()->after('method');
            $table->text('failure_reason')->nullable()->after('void_reason');
        });

        // status was paid|void; it now carries the whole lifecycle. Existing
        // rows were only ever created as settled, so they stay 'paid'.
    }

    public function down(): void
    {
        Schema::table('affiliate_payouts', function (Blueprint $table) {
            $table->dropColumn([
                'payout_currency', 'payout_amount', 'fx_rate', 'fx_source',
                'fx_captured_at', 'payment_reference', 'failure_reason',
            ]);
        });
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropIndex(['affiliate_id', 'payout_status', 'eligible_at']);
            $table->dropColumn('eligible_at');
        });
    }
};
