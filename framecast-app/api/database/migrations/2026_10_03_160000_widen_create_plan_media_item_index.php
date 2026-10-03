<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Approved character variants use a separate 1,000,000+ cache namespace;
        // dynamically requested media can follow those indexes too.
        Schema::table('create_plan_media', function (Blueprint $table) {
            $table->unsignedInteger('item_index')->change();
        });
    }

    public function down(): void
    {
        // Keep the compatible wider type: narrowing would destroy/reject cached
        // media already created in the variant namespace.
    }
};
