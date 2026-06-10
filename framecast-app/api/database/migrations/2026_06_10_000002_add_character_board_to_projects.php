<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project character board — the canonical appearance sheet for the
 * project's recurring subject (outfit, hair, accessories, build). Written by
 * the one-shot planner / lock_subject (vision over the anchor image); read by
 * every image-prompt build so costume/clothing stays consistent across scenes
 * and regenerations. Assistant/admin-facing only — no user UI.
 * Shape: { sheet: string, source: 'planner'|'vision', updated_at: iso }.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('character_board_json')->nullable()->after('assistant_brief_json');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('character_board_json');
        });
    }
};
