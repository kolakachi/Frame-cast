<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which AppSumo terms a licence was bought on (2026-10-14: the listing's new tiers were approved). Null is the
 * original terms, so every licence already on file keeps what it bought; new purchases and upgrades are 'v2'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appsumo_licenses', function (Blueprint $t) {
            $t->string('credit_schedule', 8)->nullable()->after('granted_credits');
        });
    }

    public function down(): void
    {
        Schema::table('appsumo_licenses', function (Blueprint $t) {
            $t->dropColumn('credit_schedule');
        });
    }
};
