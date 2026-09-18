<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('footage_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_asset_id');
            // What the user asked for, in their words, and what we may do with
            // the source. 'reference' rebuilds everything; 'reuse' also offers
            // the clip itself to the director as footage.
            $table->text('brief')->nullable();
            $table->string('rights', 16)->default('reference');
            $table->string('status', 24)->default('draft'); // draft|read|planned|producing
            $table->json('selection_json')->nullable();     // { start, end } seconds
            $table->json('read_json')->nullable();          // passages + speakers, as read
            $table->json('corrections_json')->nullable();   // the user's fixes, kept apart from the read
            $table->json('plan_json')->nullable();          // the target plan
            $table->json('consent_json')->nullable();       // reference + likeness confirmations
            $table->string('run_id', 64)->nullable();       // the produced run
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footage_sessions');
    }
};
