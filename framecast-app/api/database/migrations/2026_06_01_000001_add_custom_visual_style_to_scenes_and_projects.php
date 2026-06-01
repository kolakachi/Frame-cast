<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Custom" visual style — when visual_style = 'custom', this field holds the
 * free-text descriptor the user typed. The adapter swaps the preset
 * ImageStyleDescriptors lookup for this string at prompt-build time. Capped
 * at 500 chars to keep prompts inside the upstream models' working window.
 *
 * Added to both scenes (per-scene override) and projects (project default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->string('custom_visual_style', 500)->nullable()->after('visual_style');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('custom_visual_style', 500)->nullable()->after('default_visual_style');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn('custom_visual_style');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('custom_visual_style');
        });
    }
};
