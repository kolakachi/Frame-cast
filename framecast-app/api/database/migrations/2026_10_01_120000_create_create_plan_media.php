<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per paid or library item a plan asked for. A retried run for the
// same plan reuses finished items instead of buying them again.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_plan_media', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id')->index();
            $t->uuid('plan_id');
            $t->unsignedSmallInteger('item_index');
            $t->string('kind', 32);
            $t->string('description_hash', 64);
            $t->string('status', 16);
            $t->unsignedBigInteger('asset_id')->nullable();
            $t->json('record_json')->nullable();
            $t->uuid('run_id')->nullable();
            $t->unsignedInteger('charged_credits')->default(0);
            $t->string('error', 300)->nullable();
            $t->timestamps();
            $t->unique(['plan_id', 'item_index']);
        });
    }
    public function down(): void { Schema::dropIfExists('create_plan_media'); }
};
