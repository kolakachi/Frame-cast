<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each account came from (owner, 2026-10-11): its first visit as captured (Google click id, UTM tags, referring
 * site, landing page) and one channel derived from it. Accounts made before this are filled from what was already
 * known (AppSumo licence, affiliate code, friend referral), else "unknown": never guessed as direct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->string('acquisition_channel', 20)->nullable()->index();
            $t->json('acquisition_json')->nullable();
        });

        $appsumo = Schema::hasTable('appsumo_licenses') ? DB::table('appsumo_licenses')->whereNotNull('workspace_id')->pluck('workspace_id')->all() : [];
        if ($appsumo) DB::table('workspaces')->whereIn('id', $appsumo)->update(['acquisition_channel' => 'appsumo']);
        if (Schema::hasColumn('workspaces', 'affiliate_code')) {
            DB::table('workspaces')->whereNull('acquisition_channel')->whereNotNull('affiliate_code')->where('affiliate_code', '!=', '')->update(['acquisition_channel' => 'affiliate']);
        }
        DB::table('workspaces')->whereNull('acquisition_channel')->whereNotNull('referred_by_workspace_id')->update(['acquisition_channel' => 'referral']);
        DB::table('workspaces')->whereNull('acquisition_channel')->update(['acquisition_channel' => 'unknown']);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->dropIndex(['acquisition_channel']);
            $t->dropColumn(['acquisition_channel', 'acquisition_json']);
        });
    }
};
