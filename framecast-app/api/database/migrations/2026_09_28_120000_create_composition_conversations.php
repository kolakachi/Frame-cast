<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained();
            $t->foreignId('created_by_user_id')->constrained('users');
            $t->string('title', 160);
            $t->uuid('head_revision_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->json('settings_json');
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'updated_at']);
        });
        Schema::create('create_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('create_conversations');
            $t->string('role', 16);
            $t->unsignedInteger('sequence');
            $t->text('content');
            $t->string('idempotency_key', 128);
            $t->string('request_hash', 64);
            $t->timestamps();
            $t->unique(['conversation_id', 'idempotency_key']);
            $t->unique(['conversation_id', 'sequence']);
        });
        Schema::create('create_attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('create_conversations');
            $t->foreignId('asset_id')->constrained('assets');
            $t->string('purpose', 16); // source or reference; reference never becomes footage automatically
            $t->timestamps();
            $t->unique(['conversation_id', 'asset_id']);
        });
        Schema::create('composition_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('create_conversations');
            $t->foreignId('workspace_id')->constrained();
            $t->string('quote_id', 32)->unique();
            $t->string('operation_id', 32)->nullable();
            $t->string('idempotency_key', 128);
            $t->string('request_hash', 64);
            $t->json('input_json');
            $t->string('status', 32)->default('queued');
            $t->string('lease_hash', 64)->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->unsignedInteger('sequence')->default(0);
            $t->string('stage')->default('Queued');
            $t->text('error')->nullable();
            $t->string('result_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
            $t->index(['status', 'lease_expires_at']);
        });
        Schema::create('composition_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('create_conversations');
            $t->uuid('run_id')->nullable()->unique();
            $t->unsignedInteger('number');
            $t->unique(['conversation_id', 'number']);
            $t->uuid('parent_revision_id')->nullable();
            $t->uuid('restored_from_id')->nullable();
            $t->json('bundle_json');
            $t->string('bundle_hash', 64);
            $t->string('artifact_path')->nullable();
            $t->string('artifact_hash', 64)->nullable();
            $t->string('summary', 2000);
            $t->boolean('conflict')->default(false);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['composition_revisions', 'composition_runs', 'create_attachments', 'create_messages', 'create_conversations'] as $table) Schema::dropIfExists($table);
    }
};
