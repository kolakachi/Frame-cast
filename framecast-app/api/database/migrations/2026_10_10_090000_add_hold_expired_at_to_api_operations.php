<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When an operation's hold was released after 24 hours under review (CreditHolds). A late reconciliation of such an
// operation charges nothing: the user was told the credits came back.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_operations', function (Blueprint $t) {
            $t->timestamp('hold_expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_operations', fn (Blueprint $t) => $t->dropColumn('hold_expired_at'));
    }
};
