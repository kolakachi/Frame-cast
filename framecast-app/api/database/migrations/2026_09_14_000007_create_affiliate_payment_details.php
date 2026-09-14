<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an affiliate's money is sent.
 *
 * Its own table rather than columns on `affiliates` for two reasons: the row
 * is written by the affiliate while everything on `affiliates` is written by
 * us, and keeping it separate means a query that lists affiliates cannot
 * accidentally carry bank details into a response.
 *
 * The account number is encrypted at rest. It is not a secret in the way a
 * password is — it appears on every invoice its owner writes — but it is
 * exactly the field that makes a leaked database worth something, and we have
 * no reason to store it readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payment_details', function (Blueprint $table) {
            $table->id();
            // One set per affiliate; updating replaces rather than accumulates.
            $table->foreignId('affiliate_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('account_name');
            $table->string('bank_name');
            $table->text('account_number');               // encrypted
            // Kept in the clear so an operator can recognise the account at a
            // glance without decrypting, and so the affiliate can confirm they
            // entered the right one without us showing it back in full.
            $table->string('account_number_last4', 4)->nullable();
            $table->string('bank_code', 32)->nullable();  // sort code / routing / NUBAN bank code
            $table->string('country', 2)->default('NG');
            $table->string('payout_currency', 8)->default('NGN');

            // unverified → verified | rejected. A run refuses anyone not
            // verified, so this is the gate rather than a label.
            $table->string('status', 16)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->text('rejected_reason')->nullable();

            // Any edit drops verification: the point of checking was to check
            // these specific digits.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payment_details');
    }
};
