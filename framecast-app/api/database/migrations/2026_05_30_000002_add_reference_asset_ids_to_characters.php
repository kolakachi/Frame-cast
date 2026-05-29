<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            // Ordered list of every uploaded reference image. First entry = primary,
            // used by flux-pulid today; future LoRA training uses the full set.
            // reference_asset_id stays as the legacy/primary pointer for backward compat.
            $table->jsonb('reference_asset_ids')->nullable()->after('reference_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('reference_asset_ids');
        });
    }
};
