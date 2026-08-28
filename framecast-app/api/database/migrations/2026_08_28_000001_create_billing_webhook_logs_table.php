<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable record of every inbound billing webhook (Kelviq + AppSumo).
 *
 * These used to exist only in storage/logs, which is ephemeral — the container
 * filesystem is replaced on every deploy. That cost us real diagnostic ability:
 * when a Kelviq cancellation failed to record, the warning explaining WHY had
 * already been wiped by a later deploy, and Kelviq exposes no event-log
 * endpoint to replay from. This table is the answer.
 *
 * It stores the raw payload deliberately — a redacted log can't answer "what
 * exactly did they send us", which is the whole reason to keep it. Kelviq
 * payloads carry customer name, email and billing address, so rows are pruned
 * on a retention window rather than kept forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_logs', function (Blueprint $table): void {
            $table->id();

            // 'kelviq' | 'appsumo'
            $table->string('provider', 16);
            // Provider's event name (subscription.updated, purchase, …).
            $table->string('event')->nullable();
            // Provider's event id, where they send one. Kelviq does; AppSumo
            // doesn't, so this doubles as "can we correlate a retry?".
            $table->string('event_id')->nullable();

            // Did signature verification pass? The single most useful field —
            // every Kelviq delivery failed here for weeks and nobody could see it.
            $table->boolean('signature_valid')->default(false);

            // What we did: processed | duplicate | ignored | invalid_signature | error
            $table->string('outcome', 24);
            // HTTP status we returned to the provider.
            $table->unsignedSmallInteger('http_status')->default(200);
            // Failure detail or a short note about the outcome.
            $table->text('message')->nullable();

            // Resolved workspace, when the handler got that far.
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();

            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'created_at']);
            $table->index('outcome');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_logs');
    }
};
