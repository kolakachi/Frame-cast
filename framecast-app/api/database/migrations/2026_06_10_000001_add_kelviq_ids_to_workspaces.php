<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kelviq (MOR) customer + subscription identifiers, mirroring the
 * fastspring_/paddle_ columns. Set from Kelviq webhook events so future
 * events resolve back to the right workspace. See spec/KELVIQ_INTEGRATION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('kelviq_account_id')->nullable()->after('fastspring_subscription_id');
            $table->string('kelviq_subscription_id')->nullable()->after('kelviq_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['kelviq_account_id', 'kelviq_subscription_id']);
        });
    }
};
