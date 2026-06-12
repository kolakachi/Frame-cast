<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the character "Generate image" modal pick the image model (it was
 * hard-routed to gpt-image-2). Null = the default reference engine
 * (nano-banana-pro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_image_generations', function (Blueprint $table) {
            $table->string('model_key')->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('character_image_generations', function (Blueprint $table) {
            $table->dropColumn('model_key');
        });
    }
};
