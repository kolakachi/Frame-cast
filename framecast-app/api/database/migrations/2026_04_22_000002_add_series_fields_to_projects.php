<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete()->after('channel_id');
            $table->unsignedInteger('series_episode_number')->nullable()->after('series_id');
            $table->text('series_episode_summary')->nullable()->after('series_episode_number');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['series_id']);
            $table->dropColumn(['series_id', 'series_episode_number', 'series_episode_summary']);
        });
    }
};
