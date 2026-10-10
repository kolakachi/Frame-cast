<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A chat's messages go with it, and a workspace's chats go with the workspace (review, 2026-10-10). The status page
// and My ads look up "making" messages by kind.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ugc_chat_messages', function (Blueprint $t) {
            $t->foreign('chat_id')->references('id')->on('ugc_chats')->cascadeOnDelete();
            $t->index(['kind', 'id']);
        });
        Schema::table('ugc_chats', function (Blueprint $t) {
            $t->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ugc_chats', fn (Blueprint $t) => $t->dropForeign(['workspace_id']));
        Schema::table('ugc_chat_messages', function (Blueprint $t) {
            $t->dropIndex(['kind', 'id']);
            $t->dropForeign(['chat_id']);
        });
    }
};
