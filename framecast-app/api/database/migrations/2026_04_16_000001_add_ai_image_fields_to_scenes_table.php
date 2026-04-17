<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->jsonb('image_generation_settings_json')->nullable()->after('visual_prompt');
            $table->jsonb('motion_settings_json')->nullable()->after('image_generation_settings_json');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn(['image_generation_settings_json', 'motion_settings_json']);
        });
    }
};
