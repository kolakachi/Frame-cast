<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_operations', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('quote_id', 32)->index();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('pool_workspace_id')->index();
            $t->unsignedBigInteger('api_key_id')->nullable()->index();
            $t->unsignedInteger('capacity_slots')->default(1);
            $t->unsignedInteger('authorized_credits');
            $t->unsignedInteger('spent_credits')->default(0);
            $t->unsignedInteger('reserved_credits');
            $t->boolean('producer_closed')->default(false);
            $t->string('status', 24)->default('running');
            $t->timestamps();
        });
        Schema::create('api_operation_jobs', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->string('operation_id', 32)->index();
            $t->string('status', 24)->default('pending');
            $t->timestamps();
        });
        Schema::table('credit_ledger', function (Blueprint $t) {
            $t->string('api_operation_id', 32)->nullable()->index();
            $t->unsignedBigInteger('api_key_id')->nullable()->index();
        });
        // Freeze the existing best-effort attribution at migration time. Future
        // dashboard charges remain unassigned rather than following project ownership.
        \Illuminate\Support\Facades\DB::statement('UPDATE credit_ledger SET api_key_id = (SELECT projects.api_key_id FROM projects WHERE projects.id = credit_ledger.project_id) WHERE project_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $t) {
            $t->dropIndex(['api_operation_id']);
            $t->dropIndex(['api_key_id']);
            $t->dropColumn(['api_operation_id', 'api_key_id']);
        });
        Schema::dropIfExists('api_operation_jobs');
        Schema::dropIfExists('api_operations');
    }
};
