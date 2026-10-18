<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The presenter's pencil character sheet (CharacterSketch, owner 2026-10-18): made once, the way their face reaches Seedance.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('characters', fn (Blueprint $t) => $t->json('sketch_json')->nullable());
    }

    public function down(): void
    {
        Schema::table('characters', fn (Blueprint $t) => $t->dropColumn('sketch_json'));
    }
};
