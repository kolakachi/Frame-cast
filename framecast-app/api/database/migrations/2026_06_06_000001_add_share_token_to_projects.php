<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Public-share columns on projects. share_token is a URL-safe 32-char
     * random string used as the path component for /sample/<token>; nullable
     * because not every project is shared (we lazily generate on first share).
     * is_shared lets us disable a shared link without deleting the token,
     * preserving the URL for re-enable.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('share_token', 64)->nullable()->unique()->after('status');
            $table->boolean('is_shared')->default(false)->after('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'is_shared']);
        });
    }
};
