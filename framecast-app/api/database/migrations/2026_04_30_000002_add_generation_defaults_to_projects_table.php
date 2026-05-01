<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('default_visual_style', 64)->nullable()->after('waveform_settings_json');
            $table->jsonb('default_voice_settings_json')->nullable()->after('tone');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['default_visual_style', 'default_voice_settings_json']);
        });
    }
};
