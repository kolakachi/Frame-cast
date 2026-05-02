<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('export_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('status', 32)->default('draft'); // draft | scheduled | processing | published | failed | cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('platform_post_id', 512)->nullable();
            $table->string('platform_post_url', 1024)->nullable();
            $table->text('caption')->nullable();
            $table->string('title', 512)->nullable(); // YouTube
            $table->string('category', 128)->nullable(); // YouTube
            $table->string('visibility', 32)->default('public'); // public | unlisted | private
            $table->jsonb('hashtags')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'scheduled_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
