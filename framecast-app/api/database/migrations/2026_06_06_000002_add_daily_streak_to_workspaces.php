<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Daily streak counter for the Spin & Win retention play. Lives on
     * workspaces (not users) so multi-seat agency accounts share one streak
     * — claiming applies to the workspace credit pool, not the individual.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_streak_count')->default(0)->after('plan_tier');
            $table->timestamp('daily_streak_last_claim_at')->nullable()->after('daily_streak_count');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['daily_streak_count', 'daily_streak_last_claim_at']);
        });
    }
};
