<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace defaults the Cruise Assistant biases towards. The LLM
 * still wins on explicit override ("use Kling for this one") — these
 * are hints, not locks. Plan §1B-pref.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Image model: gpt-image-1 (default) | gpt-image-2 | nano-banana
            // | flux-schnell | sdxl-lightning. Stored as the registry key.
            $table->string('cruise_image_model', 32)->nullable()->after('cruise_auto_apply');

            // Animation tier: quick (default) | seedance_lite | balanced
            // | seedance_pro | premium.
            $table->string('cruise_animation_tier', 32)->nullable()->after('cruise_image_model');

            // Visual source bias: ai_image (default, what we have today)
            // | stock_video | stock_image | audiogram. When set, the LLM
            // routes vague "add a visual" / "swap the visual" intents to
            // the matching tool rather than always falling to AI image.
            $table->string('cruise_visual_source', 16)->nullable()->after('cruise_animation_tier');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['cruise_image_model', 'cruise_animation_tier', 'cruise_visual_source']);
        });
    }
};
