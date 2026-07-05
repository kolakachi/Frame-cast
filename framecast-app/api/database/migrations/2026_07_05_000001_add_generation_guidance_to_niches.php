<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-niche generation "playbook" override. Left null for existing
 * niches — the code falls back to Niche::PLAYBOOK — so no backfill is needed;
 * this column just lets a niche's guidance be tuned/edited later without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('niches', function (Blueprint $table) {
            $table->text('generation_guidance')->nullable()->after('default_music_mood');
        });
    }

    public function down(): void
    {
        Schema::table('niches', function (Blueprint $table) {
            $table->dropColumn('generation_guidance');
        });
    }
};
