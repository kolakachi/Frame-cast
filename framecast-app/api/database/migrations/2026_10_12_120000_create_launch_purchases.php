<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Launchpad launch (owner, 2026-10-12): one row per product or bump a buyer owns, set only by a Launchpad sale (or an
 * admin grant while the IPN spec is pending). workspaces.plan_addons holds what the owned rows add up to, so a plan
 * lookup never queries this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_purchases', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->string('product', 40);              // LaunchCatalogue key: fe, fe_credits, pro, bundle, …
            $t->string('receipt', 120);             // the sale's transaction id (or admin:<n> for a grant)
            $t->string('source', 20)->default('launchpad'); // launchpad | admin
            $t->string('email', 190);
            $t->string('status', 20)->default('active');    // active | refunded | chargeback
            $t->unsignedInteger('credits_granted')->default(0);
            $t->unsignedInteger('credits_revoked')->default(0);
            $t->decimal('amount', 12, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['receipt', 'product']);
        });

        Schema::table('workspaces', function (Blueprint $t) {
            $t->json('plan_addons')->nullable()->after('plan_source');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('plan_addons'));
        Schema::dropIfExists('launch_purchases');
    }
};
