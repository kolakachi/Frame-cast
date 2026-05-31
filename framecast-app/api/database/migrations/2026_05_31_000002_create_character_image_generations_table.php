<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks each "generate test image" request for a Character so the API can
 * dispatch the slow OpenAI call (30-90s for gpt-image-2 /edits at high
 * quality) to a queue worker instead of holding the HTTP connection open.
 * The connection-held version 502'd against Cloudflare's request timeout.
 *
 * Frontend polls the row's status until it becomes succeeded|failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_image_generations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->text('prompt');
            $table->string('style', 64)->nullable();
            $table->string('aspect_ratio', 16)->nullable();
            $table->string('quality', 16)->nullable();
            $table->boolean('set_as_reference')->default(false);
            $table->boolean('used_reference')->nullable(); // which adapter path ran

            // queued | processing | succeeded | failed
            $table->string('status', 16)->default('queued');
            $table->unsignedBigInteger('result_asset_id')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('credits_charged')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['character_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_image_generations');
    }
};
