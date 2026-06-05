<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // FastSpring's customer + subscription identifiers live alongside
            // the existing Paddle ones rather than replacing them. We can
            // switch between providers via BILLING_PROVIDER env without a
            // schema migration; only the populated columns differ.
            $table->string('fastspring_account_id')->nullable()->after('paddle_subscription_id');
            $table->string('fastspring_subscription_id')->nullable()->after('fastspring_account_id');

            $table->index('fastspring_account_id');
            $table->index('fastspring_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['fastspring_account_id']);
            $table->dropIndex(['fastspring_subscription_id']);
            $table->dropColumn(['fastspring_account_id', 'fastspring_subscription_id']);
        });
    }
};
