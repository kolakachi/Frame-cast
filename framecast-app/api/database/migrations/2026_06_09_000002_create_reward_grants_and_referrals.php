<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action rewards + referral attribution. See spec/ACTION_REWARDS.md.
 *
 * reward_grants is the idempotency ledger: one row per (action, recipient,
 * subject) so a reward can never be paid twice. subject_id is the triggering
 * entity — 0 for "self" actions (first_publish), the referred workspace id
 * for referral_converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');   // who receives the credits
            $table->string('action', 64);
            $table->unsignedBigInteger('subject_id')->default(0); // triggering entity (0 = self)
            $table->unsignedInteger('amount');
            $table->timestamps();
            $table->unique(['action', 'workspace_id', 'subject_id']);
            $table->index('workspace_id');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('referral_code', 32)->nullable()->unique()->after('cruise_visual_source');
            $table->unsignedBigInteger('referred_by_workspace_id')->nullable()->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referred_by_workspace_id']);
        });
        Schema::dropIfExists('reward_grants');
    }
};
