<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Connectors that registered themselves (RFC 7591). Public clients:
        // no secret, PKCE on every authorization.
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 120);
            $table->json('redirect_uris');
            $table->timestamps();
        });

        // One per user × workspace × connector. The hidden key is what the
        // connector actually acts as; revoking either ends access.
        Schema::create('oauth_grants', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 40)->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('api_key_id')->index();
            $table->json('scopes');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 40);
            $table->unsignedBigInteger('grant_id');
            $table->string('code_challenge', 128);
            $table->text('redirect_uri');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Access and refresh tokens are stored hashed, like keys. A refresh
        // token is single-use: rotated_at marks it spent.
        Schema::create('oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('grant_id')->index();
            $table->string('access_token_hash', 64)->unique();
            $table->string('refresh_token_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_tokens');
        Schema::dropIfExists('oauth_authorization_codes');
        Schema::dropIfExists('oauth_grants');
        Schema::dropIfExists('oauth_clients');
    }
};
