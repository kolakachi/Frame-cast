<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intent lock for pasted scripts. When false (the default), a user-provided
 * script is used verbatim — only broken into scenes, never rewritten. When the
 * user ticks "let AI polish my script", it gets a light edit instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('allow_script_edit')->default(false)->after('source_content_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('allow_script_edit');
        });
    }
};
