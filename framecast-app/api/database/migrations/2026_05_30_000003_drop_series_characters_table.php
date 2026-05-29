<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the dormant series_characters table.
 *
 * series_characters was a CRUD-only feature never wired into image generation.
 * The workspace-level `characters` table (added 2026_05_28_000001) supersedes it
 * with full flux-pulid integration, multi-reference photos, identity strength,
 * and scene binding. As of this migration, both prod and local have zero rows
 * in series_characters, so no data migration is needed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('series_characters');
    }

    public function down(): void
    {
        // Mirror the original schema from 2026_04_22_000001 in case of rollback.
        Schema::create('series_characters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('visual_description')->nullable();
            $table->text('personality_notes')->nullable();
            $table->jsonb('appearance_json')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });
    }
};
