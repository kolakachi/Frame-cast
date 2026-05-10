<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->integer('credits_monthly')->notNull()->default(0)->after('plan_renews_at');
            $table->integer('credits_topup')->notNull()->default(0)->after('credits_monthly');
            $table->integer('credits_free_granted')->notNull()->default(0)->after('credits_topup');
            $table->timestamp('billing_renews_at')->nullable()->after('credits_free_granted');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['credits_monthly', 'credits_topup', 'credits_free_granted', 'billing_renews_at']);
        });
    }
};
