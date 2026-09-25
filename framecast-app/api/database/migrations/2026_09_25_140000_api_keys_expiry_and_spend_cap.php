<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Optional lifetime. A pilot key should not outlive the pilot.
            $table->timestamp('expires_at')->nullable()->after('last_used_at');
            // Optional monthly ceiling on credits spent by videos this key
            // created. Summed from the ledger through projects.api_key_id.
            $table->unsignedInteger('spend_cap_credits')->nullable()->after('expires_at');
            // Rotation lineage: the key this one replaced.
            $table->unsignedBigInteger('rotated_from_id')->nullable()->after('spend_cap_credits');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'spend_cap_credits', 'rotated_from_id']);
        });
    }
};
