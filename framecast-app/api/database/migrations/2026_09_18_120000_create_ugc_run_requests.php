<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ugc_run_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('fingerprint', 64);
            $table->json('response');
            $table->timestamps();
            $table->unique(['workspace_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_run_requests');
    }
};
