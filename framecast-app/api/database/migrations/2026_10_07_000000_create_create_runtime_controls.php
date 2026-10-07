<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_runtime_controls', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('draining')->default(false);
            $table->string('reason', 240)->nullable();
            $table->timestamps();
        });
        DB::table('create_runtime_controls')->insert(['id' => 1, 'draining' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void { Schema::dropIfExists('create_runtime_controls'); }
};
