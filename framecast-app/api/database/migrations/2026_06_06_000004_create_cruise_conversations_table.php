<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One conversation per (workspace, project). Messages stored as a JSON
     * array — chats are small (< 100 turns typical) so per-message rows
     * would be overkill. cruise_audit_logs already captures the forensic
     * per-action trail; this table is the user-facing chat thread.
     */
    public function up(): void
    {
        Schema::create('cruise_conversations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id')->index();   // first member to chat in this project
            $table->json('messages')->nullable();             // ordered array of {id, role, text, action?, action_status?, action_credits?, created_at}
            $table->integer('message_count')->default(0);     // denormalised; cheap ordering
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();

            // One row per (workspace, project) — agencies sharing the same
            // project share one thread.
            $table->unique(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cruise_conversations');
    }
};
