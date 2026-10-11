<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money in, per sale (owner, 2026-10-11: cost tracking step 2). One row per Kelviq checkout or renewal and per AppSumo
 * licence, with what the buyer paid, what the platform kept, what was refunded, and what reached us, in US dollars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable()->index();
            $t->string('provider', 20);          // kelviq | appsumo
            $t->string('external_id', 120);      // the order, invoice or licence
            $t->string('kind', 30);              // test_pass | pack | topup | subscription | renewal | appsumo
            $t->string('plan', 80)->nullable();
            $t->string('channel', 20)->nullable(); // affiliate | appsumo | direct | google (later)
            $t->string('currency', 3)->default('USD');
            $t->decimal('gross', 12, 2)->default(0);     // charged, tax included
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('net', 12, 2)->default(0);       // after discounts, before tax
            $t->decimal('net_usd', 12, 2)->nullable();   // null when the currency is not USD and no rate is known
            $t->decimal('fee_usd', 12, 2)->default(0);   // the platform's cut (AppSumo) or the merchant of record's fee
            $t->decimal('refunded_usd', 12, 2)->default(0);
            $t->timestamp('occurred_at')->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_events');
    }
};
