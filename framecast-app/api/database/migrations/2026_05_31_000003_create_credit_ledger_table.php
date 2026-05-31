<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-deduction credit ledger. Lets us answer "where did this workspace's
 * credits actually go today?" without reconstructing from logs.
 *
 * Every call to CreditService::deduct() writes one row. credits is the
 * positive amount charged; balance_after is what the workspace had after
 * the decrement. operation is a short kebab-case key like 'ai_image:character',
 * 'animate:balanced', 'tts', 'export'. project_id + scene_id + user_id are
 * recorded where the caller knows them — many do.
 *
 * upstream_cost_usd is intentionally left nullable: it can be backfilled
 * later via api_usage_events join (same workspace, near-equal timestamp,
 * matching operation family). Not all deductions have a 1:1 upstream call
 * (export, breakdown), so a nullable field beats forcing zeroes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('scene_id')->nullable();

            // Short kebab-case operation key. Free-form so future ops slot in
            // without a migration; queryable via GROUP BY.
            $table->string('operation', 64);

            // What we charged the workspace. Always positive; refunds use a
            // separate operation key like 'refund:animate:balanced'.
            $table->unsignedInteger('credits');

            // What's left after this row's decrement landed. Useful for
            // sanity-checking and for showing "balance over time" without
            // having to sum the whole ledger every time.
            $table->integer('balance_after');

            // Filled later by a backfill job that joins api_usage_events.
            $table->decimal('upstream_cost_usd', 10, 6)->nullable();

            // Arbitrary debug context. Examples: { "model": "gpt-image-2",
            // "quality": "high", "tier": "balanced", "fallback": true }.
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'operation', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index(['scene_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
