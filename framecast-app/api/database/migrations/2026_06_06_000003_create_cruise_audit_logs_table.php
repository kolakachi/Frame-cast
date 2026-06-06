<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * cruise_audit_logs — every resolve + apply call from Cruise Control
     * lands here. Forensics for cost overruns / hallucinations / abuse,
     * AND the seed dataset for any future fine-tuning of the intent
     * parser (when we move off gpt-4o-mini).
     */
    public function up(): void
    {
        Schema::create('cruise_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('scene_id')->nullable();
            $table->string('phase', 16)->index();       // 'resolve' | 'apply'
            $table->text('intent_text')->nullable();    // user's prompt (resolve only)
            $table->string('resolved_tool', 64)->nullable();
            $table->json('resolved_params')->nullable();
            $table->boolean('applied')->default(false); // did this trip end in apply?
            $table->integer('credits_spent')->default(0);
            $table->string('outcome', 16)->default('ok');    // ok | error | rate_limited | unresolved
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cruise_audit_logs');
    }
};
