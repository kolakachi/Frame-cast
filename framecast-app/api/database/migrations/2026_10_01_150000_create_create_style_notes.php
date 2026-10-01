<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The user's verdicts on finished videos, kept per style (a built-in pack, a
// saved style or free design), so the next video in that style starts from
// what they liked and avoids what they did not.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_style_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('style_key', 80);
            $t->uuid('revision_id')->nullable();
            $t->string('note', 400);
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestamps();
            $t->index(['workspace_id', 'style_key']);
        });
    }
    public function down(): void { Schema::dropIfExists('create_style_notes'); }
};
