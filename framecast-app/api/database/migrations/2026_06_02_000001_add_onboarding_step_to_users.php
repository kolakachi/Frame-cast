<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tracks position in the onboarding email sequence.
            // 0 = signup not yet welcomed (transient, AuthController sets to 1 immediately).
            // 1 = day-0 welcome sent.
            // 2 = day-1 activation sent.
            // 3 = day-3 case-study sent.
            // 4 = day-7 upgrade nudge sent (or skipped because already paid).
            // 5 = day-14 win-back sent (or sequence terminated early for paid users).
            $table->unsignedTinyInteger('onboarding_step')->default(0)->after('preferences_json');
            $table->timestamp('onboarding_last_sent_at')->nullable()->after('onboarding_step');
        });

        // Existing users predate the sequence — mark them complete so the
        // hourly scanner never retroactively spams a 90-day-old account.
        DB::table('users')->update(['onboarding_step' => 5]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['onboarding_step', 'onboarding_last_sent_at']);
        });
    }
};
