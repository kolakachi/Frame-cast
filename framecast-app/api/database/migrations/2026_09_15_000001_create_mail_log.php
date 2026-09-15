<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per email we send.
 *
 * Until now only admin-composed mail was recorded, in the audit log. Every
 * automated send — magic links, the onboarding sequence, abandoned checkout,
 * the welcome — left nothing behind but a side effect: a token row, a step
 * counter, a timestamp on a workspace. You could usually infer that something
 * went out, never what it said, and never whether it arrived.
 *
 * Which is the question that actually comes up. A prospect who never signed in
 * is a different problem depending on whether the link bounced, landed in
 * spam, or was simply ignored, and without delivery state all three look the
 * same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table) {
            $table->id();

            // Nullable: mail goes to people who are not users yet, and a user
            // can be deleted without erasing the record that we wrote to them.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('email');

            $table->string('mailable', 120)->nullable();   // class, for grouping
            $table->string('subject', 255)->nullable();

            // Resend's own id, learned from the first webhook that matches.
            // Sends go over SMTP, so it is not known at send time.
            $table->string('provider_message_id', 120)->nullable();
            $table->string('message_id', 255)->nullable();  // SMTP Message-ID header

            // queued → sent → delivered → opened, or bounced / complained / failed.
            // Deliberately not reset by a later event: an opened mail that
            // later bounces on a second recipient is not un-opened.
            $table->string('status', 16)->default('sent');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // The webhook matches on recipient, newest unmatched first.
            $table->index(['email', 'sent_at']);
            $table->index('provider_message_id');
            $table->index(['user_id', 'sent_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
