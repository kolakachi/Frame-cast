<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only portal access for affiliates.
 *
 * The referral code cannot double as the credential. It is the most public
 * string we produce — it sits in URLs the affiliate posts deliberately, and
 * travels in the referrer header to anywhere they link from. Anyone who saw
 * one link could then read that affiliate's earnings and payment history.
 *
 * So the code stays the username and a separate secret is the password. The
 * key is encrypted rather than hashed because an operator has to be able to
 * re-read it to send it to the affiliate, and regenerating it would lock out
 * someone who had simply misplaced theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->text('access_key')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        // Sessions are rows rather than self-contained tokens so that access
        // can actually be withdrawn — an affiliate relationship can end, and
        // a stateless token would outlive it.
        Schema::create('affiliate_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_sessions');
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['access_key', 'last_login_at']);
        });
    }
};
