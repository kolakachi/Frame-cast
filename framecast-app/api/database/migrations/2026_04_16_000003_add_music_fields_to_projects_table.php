<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('music_asset_id')->nullable()->after('brand_kit_id');
            $table->jsonb('music_settings_json')->nullable()->after('music_asset_id');

            $table->foreign('music_asset_id')->references('id')->on('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['music_asset_id']);
            $table->dropColumn(['music_asset_id', 'music_settings_json']);
        });
    }
};
