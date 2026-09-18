<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role');
            $t->string('delivery_status')->default('pending');
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'user_id']);
        });
        DB::table('users')->whereIn('role', ['client', 'client_editor', 'client_admin'])->whereNotNull('workspace_id')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $u) {
                DB::table('workspace_memberships')->insert([
                    'workspace_id' => $u->workspace_id, 'user_id' => $u->id, 'role' => $u->role,
                    'delivery_status' => 'sent', 'invited_at' => $u->created_at,
                    'accepted_at' => $u->last_seen_at ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        Schema::create('client_profiles', function (Blueprint $t) {
            $t->foreignId('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $t->json('brief')->nullable();
            $t->timestamps();
        });
        Schema::create('client_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 160);
            $t->text('brief');
            $t->string('status')->default('requested');
            $t->date('due_at')->nullable();
            $t->json('asset_ids')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'status', 'due_at']);
        });
        Schema::create('client_activity', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->timestamp('created_at');
            $t->index(['workspace_id', 'created_at']);
        });
        Schema::create('approval_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('export_job_id');
            $t->string('author', 160);
            $t->decimal('at_seconds', 10, 2)->nullable();
            $t->text('body');
            $t->timestamp('created_at');
        });
        Schema::create('client_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('token', 64)->unique();
            $t->string('title', 160);
            $t->text('message')->nullable();
            $t->json('export_ids');
            $t->json('asset_ids')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['client_deliveries', 'approval_comments', 'client_activity', 'client_requests', 'client_profiles', 'workspace_memberships'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
