<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('export_jobs', fn (Blueprint $t) => $t->json('render_snapshot')->nullable()); }
    public function down(): void { Schema::table('export_jobs', fn (Blueprint $t) => $t->dropColumn('render_snapshot')); }
};
