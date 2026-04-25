<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caption_presets', function (Blueprint $table): void {
            $table->string('caption_color', 20)->nullable()->after('highlight_color');
            $table->string('caption_position', 40)->nullable()->after('caption_color');
        });
    }

    public function down(): void
    {
        Schema::table('caption_presets', function (Blueprint $table): void {
            $table->dropColumn(['caption_color', 'caption_position']);
        });
    }
};
