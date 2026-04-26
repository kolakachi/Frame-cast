<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('paddle_customer_id')->nullable()->after('plan_tier');
            $table->string('paddle_subscription_id')->nullable()->after('paddle_customer_id');
            $table->string('plan_status')->nullable()->default('active')->after('paddle_subscription_id'); // active | past_due | paused | cancelled
            $table->timestamp('plan_renews_at')->nullable()->after('plan_status');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['paddle_customer_id', 'paddle_subscription_id', 'plan_status', 'plan_renews_at']);
        });
    }
};
