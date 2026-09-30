<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How the voice should say a written word (brand names): changes only what
// text-to-speech reads, never what appears on screen.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_pronunciations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->string('written', 60);
            $t->string('spoken', 80);
            $t->timestamps();
            $t->unique(['workspace_id', 'written']);
        });
    }
    public function down(): void { Schema::dropIfExists('create_pronunciations'); }
};
