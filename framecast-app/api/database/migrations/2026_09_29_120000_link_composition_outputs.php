<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('projects', fn(Blueprint $t) => $t->string('editor_kind', 24)->default('scene'));
        Schema::table('create_conversations', fn(Blueprint $t) => $t->foreignId('project_id')->nullable()->unique()->constrained('projects'));
        Schema::table('composition_revisions', function(Blueprint $t) {
            $t->foreignId('output_asset_id')->nullable()->constrained('assets');
            $t->foreignId('export_job_id')->nullable()->unique()->constrained('export_jobs');
        });
        Schema::table('export_jobs', function(Blueprint $t) {
            $t->uuid('composition_revision_id')->nullable()->unique();
            $t->string('composition_hash',64)->nullable();
        });
    }
    public function down(): void {
        Schema::table('export_jobs', fn(Blueprint $t) => $t->dropColumn(['composition_revision_id','composition_hash']));
        Schema::table('composition_revisions', function(Blueprint $t) {$t->dropConstrainedForeignId('export_job_id');$t->dropConstrainedForeignId('output_asset_id');});
        Schema::table('create_conversations', fn(Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::table('projects', fn(Blueprint $t) => $t->dropColumn('editor_kind'));
    }
};
