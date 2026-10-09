<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Documents in Weave (2026-10-09): a PDF, Word or PowerPoint file read for a conversation, what it holds (pages,
// pictures, parts, small thumbnails), the facts read from it, and what the user chose to use.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('create_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->uuid('conversation_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('title', 255);
            $t->string('source', 10);
            $t->string('path', 255);
            $t->unsignedInteger('page_count')->default(0);
            $t->longText('analysis_json')->nullable();
            $t->text('facts_json')->nullable();
            $t->longText('text')->nullable();
            $t->string('status', 20)->default('reading');
            $t->string('error', 255)->nullable();
            $t->text('picks_json')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('create_documents');
    }
};
