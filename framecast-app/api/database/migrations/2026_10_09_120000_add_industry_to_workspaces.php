<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What the workspace sells (D5, 2026-10-09): learned from an existing workspace's videos, asked by the new onboarding.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('industry', 40)->nullable();
            $table->string('industry_source', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['industry', 'industry_source']);
        });
    }
};
