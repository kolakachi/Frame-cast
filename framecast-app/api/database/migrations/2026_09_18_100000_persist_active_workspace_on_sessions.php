<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', fn (Blueprint $t) => $t->unsignedBigInteger('active_workspace_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('auth_sessions', fn (Blueprint $t) => $t->dropColumn('active_workspace_id'));
    }
};
