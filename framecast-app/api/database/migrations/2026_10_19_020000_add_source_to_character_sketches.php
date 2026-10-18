<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin keeps the presenters' sheets (owner, 2026-10-19): whether one was drawn by the model or uploaded, and by whom.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('character_sketches', function (Blueprint $t) {
            $t->string('source', 16)->default('drawn');
            $t->unsignedBigInteger('made_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('character_sketches', fn (Blueprint $t) => $t->dropColumn(['source', 'made_by_user_id']));
    }
};
