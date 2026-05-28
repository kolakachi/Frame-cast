<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            // Links a scene to a workspace character. Optional — most scenes won't use one.
            $table->foreignId('character_id')->nullable()->after('visual_asset_id')
                ->constrained('characters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('character_id');
        });
    }
};
