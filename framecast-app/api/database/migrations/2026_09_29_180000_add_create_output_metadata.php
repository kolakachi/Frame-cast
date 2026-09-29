<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('composition_revisions',fn(Blueprint $t)=>$t->json('metadata_json')->nullable()); }
 public function down(): void { Schema::table('composition_revisions',fn(Blueprint $t)=>$t->dropColumn('metadata_json')); }
};
