<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHECK-GUARD (2026-10-16): a finding of our own checks that a build disputed (finished past after two repairs) or that
 * kept repeating before a build failed. We review each one: a check marked wrong refunds the failed build it stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('create_check_disputes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('conversation_id', 40)->index();
            $table->string('run_id', 40)->index();
            $table->string('revision_id', 40)->nullable();
            $table->string('outcome', 16);                    // delivered (finished past it) | failed (the build stopped)
            $table->string('code', 48)->index();
            $table->string('selector', 160)->nullable();
            $table->text('message')->nullable();
            $table->json('seen_at')->nullable();              // the revisions it was seen at
            $table->string('verdict', 16)->default('pending')->index(); // pending | wrong | right
            $table->unsignedInteger('refunded_credits')->default(0);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('create_check_disputes');
    }
};
