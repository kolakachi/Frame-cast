<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a checkout session was created but never completed.
 *
 * A plan choice used to live only in the browser (localStorage, set at
 * registration and cleared the moment the Kelviq redirect fired), so someone
 * who abandoned payment left no trace anywhere — no banner on their next
 * visit, no way to follow up. localStorage also dies with a cleared cache, a
 * private window, or a switch of device, so it could never be the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            // The plan they were sent to pay for: a lifetime_* tier, a monthly
            // tier, or a topup pack key.
            $table->string('pending_checkout_plan', 64)->nullable()->after('plan_source');
            $table->timestamp('pending_checkout_at')->nullable()->after('pending_checkout_plan');
            // Set when the nudge goes out, so it is sent at most once per
            // abandoned attempt rather than every time the scanner runs.
            $table->timestamp('pending_checkout_reminded_at')->nullable()->after('pending_checkout_at');

            // The scanner filters on "pending and not yet reminded".
            $table->index(['pending_checkout_at', 'pending_checkout_reminded_at'], 'workspaces_pending_checkout_idx');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex('workspaces_pending_checkout_idx');
            $table->dropColumn(['pending_checkout_plan', 'pending_checkout_at', 'pending_checkout_reminded_at']);
        });
    }
};
