<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One definition of the affiliate tables for tests.
 *
 * Each test class used to declare its own, which meant every new column broke
 * every suite that did not know about it — three times before this trait
 * existed. The shape lives here so adding a column is one edit, and so a test
 * cannot quietly pass against a schema the application no longer has.
 */
trait BuildsAffiliateSchema
{
    /**
     * Verified bank details for an affiliate.
     *
     * A payout run refuses anyone without them, so any test that expects a
     * payment to go out needs this — the gate is the point, not an obstacle.
     */
    protected function seedPaymentDetails(int $affiliateId, string $status = 'verified'): void
    {
        \App\Models\AffiliatePaymentDetail::query()->updateOrCreate(
            ['affiliate_id' => $affiliateId],
            [
                'account_name' => 'Test Account', 'bank_name' => 'GTBank',
                'account_number' => '0123456789', 'account_number_last4' => '6789',
                'country' => 'NG', 'payout_currency' => 'NGN',
                'status' => $status, 'verified_at' => $status === 'verified' ? now() : null,
            ],
        );
    }

    protected function bootAffiliateSchema(string $connection): void
    {
        config([
            'database.default' => $connection,
            "database.connections.{$connection}" => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge($connection);

        Schema::create('affiliates', function (Blueprint $t) {
            $t->id(); $t->string('code'); $t->string('name'); $t->string('email')->nullable();
            $t->decimal('commission_percent', 5, 2)->default(20); $t->string('status')->default('active');
            $t->text('notes')->nullable(); $t->text('access_key')->nullable();
            $t->timestamp('last_login_at')->nullable(); $t->timestamps();
        });

        Schema::create('affiliate_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->string('token_hash');
            $t->timestamp('expires_at')->nullable(); $t->timestamp('last_seen_at')->nullable(); $t->timestamps();
        });

        Schema::create('affiliate_clicks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->timestamp('clicked_at')->nullable();
            $t->string('landing_path')->nullable(); $t->string('referer')->nullable();
            $t->string('visitor_hash')->nullable(); $t->uuid('event_id')->nullable();
            $t->unique(['affiliate_id', 'event_id']); $t->timestamps();
        });

        Schema::create('affiliate_conversions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('customer_email')->nullable(); $t->string('order_id')->nullable(); $t->string('plan')->nullable();
            $t->decimal('order_amount', 10, 2)->default(0); $t->string('currency')->default('USD');
            $t->decimal('gross_amount', 10, 2)->default(0); $t->decimal('basis_amount', 10, 2)->default(0);
            $t->string('basis_method')->default('gross');
            $t->decimal('commission_percent', 5, 2); $t->decimal('commission_amount', 10, 2);
            $t->string('attribution_source')->default('workspace'); $t->string('payout_status')->default('unpaid');
            $t->unsignedBigInteger('payout_id')->nullable();
            $t->timestamp('eligible_at')->nullable(); $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        Schema::create('affiliate_payouts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id'); $t->string('reference');
            $t->date('period_start')->nullable(); $t->date('period_end')->nullable();
            $t->unsignedInteger('sales_count')->default(0); $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('currency')->default('USD'); $t->string('payout_currency')->nullable();
            $t->decimal('payout_amount', 14, 2)->nullable(); $t->decimal('fx_rate', 18, 6)->nullable();
            $t->string('fx_source')->nullable(); $t->timestamp('fx_captured_at')->nullable();
            $t->string('status')->default('paid'); $t->string('method')->nullable();
            $t->string('payment_reference')->nullable(); $t->text('note')->nullable();
            $t->timestamp('paid_at')->nullable(); $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable(); $t->text('failure_reason')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable(); $t->timestamps();
        });

        Schema::create('affiliate_payment_details', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('affiliate_id');
            $t->string('account_name'); $t->string('bank_name'); $t->text('account_number');
            $t->string('account_number_last4', 4)->nullable(); $t->string('bank_code')->nullable();
            $t->string('country')->default('NG'); $t->string('payout_currency')->default('NGN');
            $t->string('status')->default('unverified'); $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('verified_by_user_id')->nullable(); $t->text('rejected_reason')->nullable();
            $t->timestamp('submitted_at')->nullable(); $t->timestamps();
        });

        if (! Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->string('affiliate_code')->nullable();
                $t->timestamp('affiliate_attributed_at')->nullable(); $t->timestamps();
            });
        }
    }
}
