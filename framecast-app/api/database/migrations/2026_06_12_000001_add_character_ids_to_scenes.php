<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scene can feature MORE than one named character (a two-shot / dialogue).
 * `character_id` stays the PRIMARY character (face-lock + backward compat);
 * `character_ids` is the full cast present in the scene — generation pulls
 * every referenced character's reference image + appearance into the prompt.
 * Empty/null = single-character behaviour via character_id, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->json('character_ids')->nullable()->after('character_id');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('character_ids');
        });
    }
};
