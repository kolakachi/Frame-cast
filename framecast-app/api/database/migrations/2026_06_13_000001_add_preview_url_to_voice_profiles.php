<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache a generated voice preview (stable storage path) per voice so the
 * editor's "Select voice" modal can play a sample. Generated lazily on first
 * preview; for global Gemini voices that's effectively one-time-for-everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->string('preview_url')->nullable()->after('provider_voice_key');
        });
    }

    public function down(): void
    {
        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->dropColumn('preview_url');
        });
    }
};
