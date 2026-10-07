<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_balances', function (Blueprint $t) {
            $t->id();
            $t->string('vendor', 32)->unique();
            $t->decimal('balance_usd', 10, 2);
            $t->timestamp('set_at');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('vendor_balances'); }
};
