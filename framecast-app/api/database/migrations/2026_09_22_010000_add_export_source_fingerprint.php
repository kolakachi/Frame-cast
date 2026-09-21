<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', fn (Blueprint $table) => $table->string('source_fingerprint', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('export_jobs', fn (Blueprint $table) => $table->dropColumn('source_fingerprint'));
    }
};
