<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->jsonb('platform_targets')->nullable()->after('channel_id');
            $table->string('aspect_ratio', 8)->nullable()->after('platform_targets');
            $table->unsignedInteger('duration_target_seconds')->nullable()->after('aspect_ratio');
            $table->string('posting_cadence', 32)->nullable()->after('duration_target_seconds');
            $table->string('visual_mode', 32)->nullable()->after('posting_cadence');
            $table->string('visual_style', 64)->nullable()->after('visual_mode');
            $table->string('visual_palette', 64)->nullable()->after('visual_style');
            $table->text('visual_description')->nullable()->after('visual_palette');
            $table->foreignId('default_voice_profile_id')->nullable()->constrained('voice_profiles')->nullOnDelete()->after('visual_description');
            $table->foreignId('default_caption_preset_id')->nullable()->constrained('caption_presets')->nullOnDelete()->after('default_voice_profile_id');
            $table->string('default_music_setting', 32)->nullable()->after('default_caption_preset_id');
            $table->unsignedTinyInteger('default_music_volume')->default(20)->after('default_music_setting');
            $table->string('default_language', 16)->nullable()->after('default_music_volume');
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table): void {
            $table->dropForeign(['default_voice_profile_id', 'default_caption_preset_id']);
            $table->dropColumn([
                'platform_targets', 'aspect_ratio', 'duration_target_seconds', 'posting_cadence',
                'visual_mode', 'visual_style', 'visual_palette', 'visual_description',
                'default_voice_profile_id', 'default_caption_preset_id',
                'default_music_setting', 'default_music_volume', 'default_language',
            ]);
        });
    }
};
