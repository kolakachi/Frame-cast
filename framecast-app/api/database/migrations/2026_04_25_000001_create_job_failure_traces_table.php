<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_failure_traces', function (Blueprint $table): void {
            $table->id();
            $table->string('job_class');
            $table->string('entity_type', 60)->nullable();   // 'export', 'project', 'scene', 'variant', 'asset', etc.
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('exception_class')->nullable();
            $table->text('exception_message')->nullable();
            $table->text('exception_trace')->nullable();     // truncated to ~10 KB
            $table->timestamp('failed_at')->useCurrent();

            $table->index('failed_at');
            $table->index(['entity_type', 'entity_id']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_failure_traces');
    }
};
