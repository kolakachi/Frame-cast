<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every finished video someone downloads (owner, 2026-10-15: "we need to keep track of when users download from
 * create or UGC"): who, from where (Create, UGC, the Classic editor) and how (the app or the API). Recorded where the
 * file is served for download, never on playback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_downloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source', 16); // create | ugc | editor
            $table->string('via', 8);     // app | api
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('conversation_id', 40)->nullable()->index();
            $table->string('revision_id', 40)->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_downloads');
    }
};
