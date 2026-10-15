<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEARN (owner, 2026-10-15): every UGC take is reviewed after it finishes (ugc_take_reviews), and the recurring
 * pitfalls are merged daily into a short lessons list (ugc_lessons, newest row current) that the director, the shot
 * planner and the action-sheet writer read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ugc_take_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('ugc_type', 40)->nullable();
            $table->string('engine', 20)->nullable();
            $table->string('outcome', 20); // delivered | failed
            $table->unsignedTinyInteger('score')->nullable(); // 1-10, how close to the brief
            $table->json('pitfalls')->nullable();
            $table->timestamps();
        });
        Schema::create('ugc_lessons', function (Blueprint $table) {
            $table->id();
            $table->json('lessons'); // [{role: director|planner|sheet, lesson, seen}]
            $table->unsignedInteger('reviews_read')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_lessons');
        Schema::dropIfExists('ugc_take_reviews');
    }
};
