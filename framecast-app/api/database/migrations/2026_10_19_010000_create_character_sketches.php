<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The pencil sheets get a table of their own (owner, 2026-10-19): internal, never in the asset library.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('character_sketches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('character_id')->index();
            // The photo it was drawn from: a new photo means a new sheet.
            $t->unsignedBigInteger('from_asset_id');
            $t->text('storage_url');
            $t->timestamps();
            $t->unique(['character_id', 'from_asset_id']);
        });
        if (Schema::hasColumn('characters', 'sketch_json')) Schema::table('characters', fn (Blueprint $t) => $t->dropColumn('sketch_json'));
    }

    public function down(): void
    {
        Schema::dropIfExists('character_sketches');
        Schema::table('characters', fn (Blueprint $t) => $t->json('sketch_json')->nullable());
    }
};
