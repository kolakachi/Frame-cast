<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->timestamp('subscription_ends_at')->nullable();
            $t->timestamp('billing_state_version')->nullable();
        });
        Schema::create('billing_credit_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('invoice_id')->unique();
            $t->string('subscription_id');
            $t->timestamp('period_end');
            $t->string('tier');
            $t->integer('allocation');
            $t->integer('credit_delta');
            $t->timestamps();
            $t->index(['workspace_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credit_allocations');
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn(['subscription_ends_at', 'billing_state_version']));
    }
};
