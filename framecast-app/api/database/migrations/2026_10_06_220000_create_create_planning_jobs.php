<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_planning_jobs', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->uuid('conversation_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->unsignedInteger('expected_version');
            $table->boolean('skip_questions')->default(false);
            $table->string('state', 24)->default('queued')->index();
            $table->uuid('execution_token')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('result_json')->nullable();
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'idempotency_key']);
        });
    }

    public function down(): void { Schema::dropIfExists('create_planning_jobs'); }
};
