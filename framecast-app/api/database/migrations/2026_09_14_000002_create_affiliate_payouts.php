<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout runs.
 *
 * Marking conversions paid one flag at a time answers "is this settled?" but
 * not the question that actually comes up once an affiliate has been paid
 * more than once: which sales did that payment cover, and what has accrued
 * since. Lifetime totals cannot say — after the second payment they are the
 * sum of two periods nobody recorded the boundary of.
 *
 * It is also where money quietly goes missing. The statement is exported,
 * sent, and settled in three separate steps; a sale landing between the
 * export and the settle is marked paid without ever having appeared on a
 * statement, and no later export will show it as outstanding.
 *
 * So a payout is a row. It fixes its membership at the moment it is created,
 * the statement is rendered from that membership rather than from a fresh
 * query, and anything arriving afterwards belongs to the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            // Quoted in the transfer, so it can be matched against a bank line
            // later. Never reused, including by a voided run.
            $table->string('reference', 40)->unique();
            // The span the included sales actually cover, derived from them
            // rather than from the calendar: an affiliate asking "what was the
            // September payment for?" wants the sales, not the month.
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('sales_count')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 16)->default('paid');   // paid | void
            $table->string('method', 32)->nullable();        // how it was sent
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
        });

        Schema::table('affiliate_conversions', function (Blueprint $table) {
            // Null while outstanding. Set once, by the run that settles it,
            // and cleared only by voiding that run.
            $table->unsignedBigInteger('payout_id')->nullable()->after('payout_status');
            $table->index('payout_id');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropIndex(['payout_id']);
            $table->dropColumn('payout_id');
        });
        Schema::dropIfExists('affiliate_payouts');
    }
};
