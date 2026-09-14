<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliates: individual marketers who send traffic and take a negotiated cut
 * of what it earns. They never use the product, so — unlike the existing
 * workspace-to-workspace referral — there is no account to hang this on.
 *
 * The design problem is not the split, it is not losing it. A click and the
 * purchase it eventually produces can be days apart, on a checkout hosted by
 * someone else, by a person who may never have registered first. So
 * attribution is recorded at the moment of sale onto a row of its own, with
 * the rate copied in, rather than being looked up later from state that may
 * have moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();           // what appears in ?ref=
            $table->string('name');
            $table->string('email')->nullable();            // where the statement goes
            // Negotiated individually, so it lives on the affiliate rather than
            // in config. Percent of order value.
            $table->decimal('commission_percent', 5, 2)->default(20.00);
            $table->string('status', 16)->default('active'); // active | paused
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Every arrival, whether or not it ever converts. This is the audit
        // trail an affiliate can be shown when they ask why a number is what
        // it is, and the fallback if a cookie is lost.
        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('landing_path', 255)->nullable();
            $table->string('referer', 255)->nullable();
            // Hashed, not stored raw: enough to spot obvious self-clicking
            // without keeping personal data we have no need for.
            $table->string('visitor_hash', 64)->nullable();
            $table->timestamp('clicked_at');

            $table->index(['affiliate_id', 'clicked_at']);
            $table->index('visitor_hash');
        });

        // One row per sale. Written by the billing webhook, where the order and
        // its amount are both known, and never recalculated afterwards.
        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('order_id', 128)->nullable();
            $table->string('plan', 64)->nullable();
            $table->decimal('order_amount', 10, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            // Snapshotted from the affiliate at the time of sale. Renegotiating
            // a rate must not silently restate what is owed on sales already
            // made.
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 10, 2);
            // How the sale was tied to the affiliate, so a disputed row can be
            // explained: checkout_metadata | workspace | cookie.
            $table->string('attribution_source', 32)->default('workspace');
            $table->string('payout_status', 16)->default('unpaid'); // unpaid | paid | void
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // One conversion per order, whatever a webhook retry does.
            $table->unique('order_id');
            $table->index(['affiliate_id', 'payout_status']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            // Kept as the code, not a foreign key: the affiliate that sent
            // someone is a historical fact and must survive the affiliate row
            // being removed.
            $table->string('affiliate_code', 32)->nullable();
            $table->timestamp('affiliate_attributed_at')->nullable();
            $table->index('affiliate_code');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['affiliate_code']);
            $table->dropColumn(['affiliate_code', 'affiliate_attributed_at']);
        });
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
    }
};
