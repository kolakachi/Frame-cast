<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable webhook idempotency.
 *
 * Kelviq event ids were previously deduped with a 24h cache key. That is fine
 * for the SET-semantics events, but `checkout.completed` grants top-up credits
 * additively — so a replay after the TTL expired (a manual rerun from the
 * Kelviq dashboard days later, say) would grant the pack a second time.
 *
 * A unique event id in the database makes the claim permanent instead of
 * expiring. Rows are cheap (a few per customer per month) and double as an
 * audit trail of what we actually processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhook_events', function (Blueprint $table): void {
            $table->id();
            // 'kelviq' today; keeps the table usable if another provider is added.
            $table->string('provider', 32);
            // The provider's event id (Kelviq/Svix resend the same id on retry).
            $table->string('event_id');
            $table->string('type')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The whole point: one row per provider event, forever.
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
    }
};
