<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('name', 80);
            // The visible half — shown in the dashboard so a key can be told
            // apart without ever storing the secret.
            $table->string('prefix', 20)->index();
            // SHA-256 of the whole token. Looked up by prefix, compared in
            // constant time; the plaintext exists only in the create response.
            $table->string('token_hash', 64);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
