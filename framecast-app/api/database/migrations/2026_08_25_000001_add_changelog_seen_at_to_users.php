<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "last looked at the changelog" marker.
 *
 * One nullable timestamp is all the unread state we need: an entry counts as
 * unread when its date is newer than this. That keeps release notes shared
 * (config/changelog.php) rather than fanned out into a row per workspace the
 * way WorkspaceNotification does.
 *
 * Existing users start at NULL, which reads as "has seen nothing". Rather than
 * greeting everyone with a full-history badge, they're backfilled to now — the
 * entries that exist today describe fixes they already lived through, and the
 * badge should mean "something new since you last looked", not "welcome".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('changelog_seen_at')->nullable()->after('last_seen_at');
        });

        // Only existing accounts — new signups legitimately start at NULL and
        // will see whatever is current on first load.
        \Illuminate\Support\Facades\DB::table('users')
            ->whereNull('changelog_seen_at')
            ->update(['changelog_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('changelog_seen_at');
        });
    }
};
