<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('composition_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id');
            $t->foreign('run_id')->references('id')->on('composition_runs');
            $t->string('operation_id', 32)->index();
            $t->string('attempt_key', 100);
            $t->string('request_hash', 64);
            $t->string('kind', 16);
            $t->string('provider', 64);
            $t->string('model', 160);
            $t->string('status', 24)->default('started');
            $t->unsignedInteger('credit_limit');
            $t->unsignedBigInteger('cost_limit_microusd');
            $t->unsignedInteger('charged_credits')->default(0);
            $t->unsignedBigInteger('cost_microusd')->nullable();
            $t->string('prediction_id', 160)->nullable();
            $t->string('result_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'attempt_key']);
            $t->unique(['provider', 'prediction_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('composition_attempts'); }
};
