<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->foreignId('batch_job_id')
                ->nullable()
                ->after('variant_id')
                ->constrained('batch_jobs')
                ->nullOnDelete();

            $table->index(['batch_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->dropIndex(['batch_job_id', 'status']);
            $table->dropConstrainedForeignId('batch_job_id');
        });
    }
};
