<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('service');
            $table->string('operation')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('succeeded');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['provider', 'service', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_events');
    }
};
