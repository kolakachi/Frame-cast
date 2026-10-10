<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// UGC Ads as a conversation (2026-10-10): one prompt box, a director that asks only what it cannot infer, one pitch
// with a price, then the existing UGC pipeline makes the video(s) as Classic projects.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ugc_chats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('user_id');
            $t->string('title', 160)->nullable();
            $t->json('state_json')->nullable();   // materials, presenters + voices, type, shape, length
            $t->timestamps();
            $t->index(['workspace_id', 'updated_at']);
        });
        Schema::create('ugc_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->uuid('chat_id');
            $t->string('role', 12);              // user | assistant
            $t->string('kind', 16);              // text | question | pitch | making | error
            $t->text('content')->nullable();
            $t->json('payload_json')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_chat_messages');
        Schema::dropIfExists('ugc_chats');
    }
};
