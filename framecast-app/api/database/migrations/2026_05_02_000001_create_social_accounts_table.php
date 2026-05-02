<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32); // youtube | tiktok | instagram | facebook
            $table->string('platform_user_id', 255);
            $table->string('platform_username', 255)->nullable();
            $table->string('platform_display_name', 255)->nullable();
            $table->string('platform_avatar_url', 1024)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 32)->default('active'); // active | expired | revoked
            $table->jsonb('scopes')->nullable();
            $table->jsonb('platform_meta')->nullable(); // channel_id, channel_title, etc.
            $table->timestamps();

            $table->unique(['workspace_id', 'platform', 'platform_user_id']);
            $table->index(['workspace_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
