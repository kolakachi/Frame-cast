<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon_emoji')->default('🎬');
            $table->string('default_template_type')->nullable();
            $table->string('default_visual_style')->nullable();
            $table->string('default_caption_preset_name')->nullable();
            $table->string('default_voice_tone')->nullable();
            $table->string('default_music_mood')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('niche_id')->nullable()->after('template_id');
            $table->foreign('niche_id')->references('id')->on('niches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['niche_id']);
            $table->dropColumn('niche_id');
        });

        Schema::dropIfExists('niches');
    }
};
