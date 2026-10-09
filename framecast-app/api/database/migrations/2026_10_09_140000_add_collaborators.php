<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Collaborators (phase 3, 2026-10-09): an agency's team members spend the agency's credits up to a monthly allowance
// the agency sets per person, so every spend now records the person behind it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedInteger('monthly_credit_allowance')->nullable();
        });
        Schema::table('api_operations', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->index();
        });
        Schema::table('credit_ledger', function (Blueprint $t) {
            $t->index(['user_id', 'created_at'], 'credit_ledger_user_month_idx');
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', fn (Blueprint $t) => $t->dropIndex('credit_ledger_user_month_idx'));
        Schema::table('api_operations', fn (Blueprint $t) => $t->dropColumn('user_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('monthly_credit_allowance'));
    }
};
