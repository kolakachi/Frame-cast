<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects now know which character to use as their on-screen presence so the
 * wizard can pre-bind a character at creation time. Scene generation jobs read
 * this field and stamp scene.character_id when each scene is first generated.
 * Nullable — projects without a recurring character (b-roll-only, audiogram,
 * etc.) leave it unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_character_id')->nullable()->after('niche_id');
            $table->index('default_character_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['default_character_id']);
            $table->dropColumn('default_character_id');
        });
    }
};
