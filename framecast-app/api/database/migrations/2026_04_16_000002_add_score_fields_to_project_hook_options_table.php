<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_hook_options', function (Blueprint $table): void {
            $table->unsignedSmallInteger('hook_score')->nullable()->after('hook_text');
            $table->string('hook_score_reason', 255)->nullable()->after('hook_score');
        });
    }

    public function down(): void
    {
        Schema::table('project_hook_options', function (Blueprint $table): void {
            $table->dropColumn(['hook_score', 'hook_score_reason']);
        });
    }
};
