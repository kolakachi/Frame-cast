<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likeness consent attestation for character reference photos. When a user
 * uploads a real face as a reference, they must affirm they have the rights/
 * consent to use that likeness — recorded here for audit. Required by MOR/
 * Stripe AUP (IP + impersonation). See spec/MOR_COMPLIANCE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('consent_acknowledged_at')->nullable()->after('is_auto');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('consent_acknowledged_at');
        });
    }
};
