<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Undo/redo for the Classic editor (2026-10-10): one row per edit a person or an assistant made, holding what the
// changed scenes and project settings were before it (and, once undone, what they were when it was undone).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_edits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('workspace_id');
            $t->string('actor', 20);            // user | assistant | cruise
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('label', 160);
            $t->longText('before_json');
            $t->longText('redo_json')->nullable();
            $t->timestamp('undone_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_edits');
    }
};
