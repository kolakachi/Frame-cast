<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags characters auto-created by the Cruise "lock_subject" tool from a
 * project's own generated image. Auto-subjects are hidden from the
 * /characters library list (see CharacterController::index) so they don't
 * clutter it, but still work for generation. See spec/AUTO_SUBJECT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
    }
};
