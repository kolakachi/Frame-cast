<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AppSumo lifetime-deal (LTD) support.
 *
 *  - workspaces.plan_source distinguishes how a workspace's plan was acquired
 *    ('kelviq' subscription vs 'appsumo' LTD) so gating/billing code can branch
 *    (AppSumo accounts never renew credits and use AppSumo-specific limits).
 *  - appsumo_licenses stores every AppSumo license key (AppSumo owns the keys
 *    and never gives us the email), searchable by support, with an idempotency
 *    flag so the one-time credit bucket is granted exactly once per license.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            // null = organic/subscription (default); 'appsumo' = LTD workspace.
            $table->string('plan_source')->nullable()->after('plan_tier');
        });

        Schema::create('appsumo_licenses', function (Blueprint $table): void {
            $table->id();
            // AppSumo's license UUID (regenerated on upgrade/downgrade — we
            // re-key the row and keep it unique).
            $table->uuid('license_key')->unique();
            // Linked once the buyer completes OAuth + account creation.
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            // Our internal tier key (appsumo_starter / _creator / _agency).
            $table->string('tier')->nullable();
            // AppSumo's raw tier number (1,2,3) from the webhook.
            $table->unsignedSmallInteger('appsumo_tier')->nullable();
            // inactive | active | deactivated (mirrors AppSumo license_status).
            $table->string('status')->default('inactive');
            // How many bucket credits we've granted for this license so far.
            // Idempotent: (re)provision grants only target − granted, so retries
            // never double-grant and an upgrade grants just the tier delta.
            $table->unsignedInteger('granted_credits')->default(0);
            // Last webhook payload, for support/audit ("why is this license…").
            $table->json('last_payload')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appsumo_licenses');
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('plan_source');
        });
    }
};
