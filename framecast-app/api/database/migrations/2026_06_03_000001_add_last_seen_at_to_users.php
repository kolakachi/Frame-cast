<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Bumped by AuthenticateWithJwt on every authenticated request,
            // throttled to once per 5 min via cache to avoid write thrash.
            // Replaces the bogus admin "last active" that used updated_at
            // (which moved on every user-row edit — credit grant, status
            // change — not actual activity).
            $table->timestamp('last_seen_at')->nullable()->after('onboarding_last_sent_at');
            $table->index('last_seen_at');
        });

        // Backfill: best-guess "last seen" so the admin dashboard has
        // something better than NULL for existing accounts. Take the latest
        // signal we have per user — most recent used magic link OR most
        // recent project edit in their workspace, whichever is later.
        DB::statement(<<<SQL
            UPDATE users u SET last_seen_at = sub.seen FROM (
                SELECT u2.id,
                       GREATEST(
                           COALESCE((SELECT MAX(used_at) FROM magic_link_tokens WHERE email = u2.email AND used_at IS NOT NULL), '1970-01-01'),
                           COALESCE((SELECT MAX(updated_at) FROM projects WHERE workspace_id = u2.workspace_id), '1970-01-01'),
                           u2.created_at
                       ) AS seen
                FROM users u2
            ) sub WHERE u.id = sub.id AND sub.seen > '1970-01-01';
        SQL);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
