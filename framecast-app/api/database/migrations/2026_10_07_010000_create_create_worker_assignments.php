<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_worker_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->unique();
            $table->string('worker_id', 80);
            $table->uuid('instance_id');
            $table->string('slot', 40);
            $table->string('lease_fingerprint', 64);
            $table->timestamp('claimed_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_source', 16)->nullable();
            $table->string('stop_evidence', 500)->nullable();
            $table->index(['worker_id', 'slot', 'stopped_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('create_worker_assignments'); }
};
