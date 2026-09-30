<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Workspace styles the user chose to save from a finished version or a
// reference. Only saved on an explicit request; every edit bumps the version.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_styles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('created_by_user_id');
            $t->string('name', 80);
            $t->string('source', 16);
            $t->string('source_ref', 64)->nullable();
            $t->json('style_json');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('create_styles'); }
};
