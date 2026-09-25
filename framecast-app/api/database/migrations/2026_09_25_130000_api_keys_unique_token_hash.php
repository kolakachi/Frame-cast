<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resolution is by full hash now, not by prefix; the hash must be
        // unique so a lookup can never pick the wrong key.
        Schema::table('api_keys', function (Blueprint $table) {
            $table->unique('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
        });
    }
};
