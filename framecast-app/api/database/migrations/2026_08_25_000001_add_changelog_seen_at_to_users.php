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
 * Existing users start at NULL, which reads as "has seen nothing" and would
 * badge them with the entire back catalogue. They're backfilled to now so the
 * badge means "new since you last looked" rather than "welcome".
 *
 * This does NOT suppress entries dated today. Entry dates carry no time
 * component, so an entry counts as unread when the END of its date is later
 * than seen_at — same-day publishes still badge everyone. That is deliberate:
 * a note shipped today is news to a user who signed up today just as much as
 * to one who hit the bug yesterday. Only entries dated before the backfill are
 * suppressed.
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
