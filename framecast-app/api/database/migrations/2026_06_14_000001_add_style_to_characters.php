<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A character's dominant visual style (e.g. 3d_animated, anime, photorealistic)
 * — set from the style its reference image was generated in. The one-shot/
 * assistant inherit it so a 3D character produces 3D video unless the user
 * explicitly asks for another style.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('style')->nullable()->after('description');
        });

        // Backfill from each character's most recent succeeded reference image
        // generation (prefer the one set as primary) so existing characters
        // inherit a style immediately, not only after a fresh generation.
        if (Schema::hasTable('character_image_generations')) {
            DB::statement(<<<'SQL'
                UPDATE characters c SET style = sub.style
                FROM (
                    SELECT DISTINCT ON (character_id) character_id, style
                    FROM character_image_generations
                    WHERE status = 'succeeded' AND style IS NOT NULL AND style <> ''
                    ORDER BY character_id, set_as_reference DESC, id DESC
                ) sub
                WHERE c.id = sub.character_id AND c.style IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('style');
        });
    }
};
