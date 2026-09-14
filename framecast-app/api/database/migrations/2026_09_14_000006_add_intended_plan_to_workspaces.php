<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plan someone picked on the pricing site before registering.
 *
 * Until now that choice lived only in the browser's localStorage, so it
 * survived exactly one device and one un-cleared cache. Anyone who registered,
 * left for their inbox, and came back later had it forgotten — and a follow-up
 * email could not offer to finish a purchase whose subject nobody knew.
 *
 * Deliberately separate from pending_checkout_plan. That column means "was
 * handed to Kelviq and did not come back", which is what the abandoned-checkout
 * banner and email key on; someone who never reached a payment page has not
 * abandoned anything and should not be chased as though they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('intended_plan', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('intended_plan');
        });
    }
};
