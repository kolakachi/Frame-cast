<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            // subtle | balanced | strong | locked → maps to flux-pulid id_weight
            $table->string('identity_strength', 16)->default('balanced')->after('consistency_method');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('identity_strength');
        });
    }
};
