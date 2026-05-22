<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table): void {
            $table->string('token', 96)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('workspace_id')->nullable()->after('token');
            $table->unsignedBigInteger('export_job_id')->nullable()->after('project_id');
            $table->string('reviewer_email')->nullable()->after('requested_by_user_id');
            $table->string('reviewer_name')->nullable()->after('reviewer_email');
            $table->timestamp('reviewed_at')->nullable()->after('comment');
            $table->timestamp('expires_at')->nullable()->after('reviewed_at');
            $table->json('metadata_json')->nullable()->after('expires_at');

            $table->index(['workspace_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'token', 'workspace_id', 'export_job_id', 'reviewer_email', 'reviewer_name',
                'reviewed_at', 'expires_at', 'metadata_json',
            ]);
        });
    }
};
