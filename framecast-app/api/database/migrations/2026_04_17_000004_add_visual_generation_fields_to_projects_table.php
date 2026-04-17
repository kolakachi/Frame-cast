<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('visual_generation_mode')->nullable()->after('source_image_asset_ids');
            $table->string('ai_broll_style')->nullable()->after('visual_generation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['visual_generation_mode', 'ai_broll_style']);
        });
    }
};
