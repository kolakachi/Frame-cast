<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cruise_action_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('message_id', 64);
            $table->unsignedInteger('action_index')->default(0);
            $table->string('tool', 80);
            $table->json('params_json')->nullable();
            $table->json('expected_stages')->nullable();
            $table->json('completed_stages')->nullable();
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('estimated_credits')->default(0);
            $table->unsignedInteger('actual_credits')->default(0);
            $table->unsignedBigInteger('affected_scene_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'project_id', 'message_id', 'action_index'], 'cruise_action_runs_message_action_unique');
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'affected_scene_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cruise_action_runs');
    }
};
