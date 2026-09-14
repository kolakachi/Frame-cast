<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate what the customer paid from what the commission is calculated on.
 *
 * Kelviq's checkout.completed carries one figure — the gross total, tax
 * included and no breakdown. On a real order that was $202.98: $199.00 less a
 * $29.85 discount, plus $33.83 of tax. Paying a percentage of that pays the
 * affiliate a share of tax that is remitted to a government and of the fee the
 * merchant of record keeps, neither of which is revenue.
 *
 * Both numbers are now recorded, so a statement can show the sale and the
 * basis side by side and an affiliate can see how their figure was reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            // What the customer was charged, exactly as the provider reported.
            $table->decimal('gross_amount', 10, 2)->default(0)->after('order_amount');
            // What the percentage was applied to, after tax and platform fees
            // are taken out.
            $table->decimal('basis_amount', 10, 2)->default(0)->after('gross_amount');
            // How the basis was arrived at, so a figure can be explained and
            // later corrected if the assumption changes.
            $table->string('basis_method', 32)->default('gross')->after('basis_amount');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'basis_amount', 'basis_method']);
        });
    }
};
