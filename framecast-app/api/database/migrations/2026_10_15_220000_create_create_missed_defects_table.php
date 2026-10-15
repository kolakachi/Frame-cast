<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RD-14 (the Opus Motion Design Harness, 2026-10-15): a fault a user points out in a delivered Create video that the
 * checks let through, with the check that would have caught it. A proposed check runs on every build once approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('create_missed_defects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('conversation_id', 40)->index();
            $table->string('revision_id', 40)->nullable();
            $table->text('note');                         // the user's words
            $table->string('defect', 160);                // what was wrong, short
            $table->string('area', 24)->default('other'); // text, layout, motion, audio, timing, brand, other
            $table->text('proposed_check');               // one thing a reviewer can see on frames
            $table->string('status', 16)->default('proposed')->index(); // proposed | approved | rejected
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('create_missed_defects');
    }
};
