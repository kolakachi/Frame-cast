<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('moderation_events', function (Blueprint $table) {
            $table->id();

            // Source of the event. Drives admin triage filtering.
            //   generation_rejection — upstream provider (OpenAI/Replicate) refused output
            //   user_report          — submitted via /report-content form
            //   pattern_alert        — DetectAbusePatternsJob flagged behavioural pattern
            //   admin_action         — admin manually escalated (warning, suspension, etc.)
            $table->string('source', 32)->index();

            // Severity ladders the response: info -> low -> medium -> high -> critical.
            // critical = drop everything, terminate account, report to authorities.
            $table->string('severity', 16)->default('low')->index();

            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable();
            $table->foreignId('scene_id')->nullable();

            // Operation context for generation_rejection events.
            // e.g. 'ai_image:character', 'animate:balanced'.
            $table->string('operation', 64)->nullable();

            // What the provider/classifier/reporter said is wrong.
            $table->text('reason')->nullable();

            // The user prompt (or other input) that triggered the event.
            // Truncated to 4000 chars by ModerationService::recordRejection.
            $table->text('prompt')->nullable();

            $table->unsignedBigInteger('reference_asset_id')->nullable();
            $table->unsignedBigInteger('resulting_asset_id')->nullable();

            // User-report-only fields.
            $table->string('report_email')->nullable();
            $table->string('report_url', 1000)->nullable();
            $table->text('report_message')->nullable();
            $table->string('report_violation_type', 64)->nullable();

            // Free-form extra context (provider error code, classifier scores, etc.).
            $table->jsonb('metadata')->nullable();

            // Review workflow.
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_taken', 64)->nullable();
            $table->text('action_notes')->nullable();

            $table->timestamps();

            // Common triage queries.
            $table->index(['workspace_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['reviewed_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_events');
    }
};
