<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A vision-derived casting sheet for the character: age range, skin tone,
// hair, face, build, style — cached so the read runs once per reference
// image. It lets engines that refuse face IMAGES (Seedance) still render a
// close variant from text alone; no likeness ever leaves the workspace.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->json('appearance_json')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('appearance_json');
        });
    }
};
