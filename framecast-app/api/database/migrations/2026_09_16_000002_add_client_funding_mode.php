<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a client workspace spends the agency's credits or its own.
 *
 * 'pooled' is what every client has been until now: no balance of its own,
 * every charge resolved up to the agency. 'funded' gives the client a real
 * balance the agency transfers into, and its own balance is then the limit —
 * which is the whole point of handing one out rather than sharing.
 *
 * No new balance column. A funded client holds its credits in credits_topup
 * like any other workspace, so every existing query, grant and deduction keeps
 * working; only which workspace those operations resolve to changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Default matches existing behaviour exactly. A migration that
            // switched live clients to funded would strand them at a zero
            // balance mid-project.
            $table->string('funding_mode', 16)->default('pooled')->after('monthly_credit_cap');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('funding_mode');
        });
    }
};
