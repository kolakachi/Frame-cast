<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Per-workspace pref: when true, Cruise Control auto-applies
     * confirmation_class='auto' tool calls immediately on resolve, so the
     * user sees ✓ Applied instead of an Apply button. Default true —
     * the cards are auto-class for a reason (cheap, reversible).
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('cruise_auto_apply')->default(true)->after('daily_streak_last_claim_at');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('cruise_auto_apply');
        });
    }
};
