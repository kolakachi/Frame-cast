<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The welcome email moves from registration to payment, which means it now has
 * several possible triggers — a subscription, a lifetime purchase, an AppSumo
 * licence — and the subscription ones fire repeatedly: `subscription.updated`
 * arrives on any change, and a webhook can be redelivered at any time.
 *
 * So the send is claimed against this column rather than inferred from state.
 * Anything else sends the same welcome on every plan change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('welcome_email_sent_at')->nullable();
        });

        // Everyone who already has an account has already had this email under
        // the old behaviour. Backfilling stops the change from mailing the
        // entire existing customer base on their next plan event.
        \Illuminate\Support\Facades\DB::table('workspaces')
            ->whereNull('welcome_email_sent_at')
            ->update(['welcome_email_sent_at' => \Illuminate\Support\Facades\DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('welcome_email_sent_at');
        });
    }
};
