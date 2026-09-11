<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn `characters` into a browsable actor library (the Arcads model).
 *
 * Two things are missing for that. First, the filter dimensions people
 * actually shop by — gender, age bracket and the situation/setting the actor
 * appears in ("airport", "coffee shop", "gaming"). Second, stock actors: a
 * curated set every workspace can pick from, which means `workspace_id` has to
 * be nullable so a row can belong to no one in particular.
 *
 * Stock rows are deliberately excluded from the plan's max_characters cap —
 * that cap exists to meter what a workspace *generates*, and picking a stock
 * actor generates nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Filter dimensions. Free-text rather than enums: the useful values
            // are a content decision that will keep moving, and a migration per
            // new situation tag would be absurd.
            $table->string('gender', 32)->nullable();
            $table->string('age_group', 32)->nullable();
            $table->jsonb('situations')->nullable();

            // A looping preview clip is what makes an actor grid legible — a
            // still can't show how someone moves or sounds. Nullable because
            // every existing character has only a reference image.
            $table->unsignedBigInteger('preview_asset_id')->nullable();

            // Stock actors are global; workspace actors stay owned.
            $table->boolean('is_stock')->default(false);
        });

        // Postgres: workspace_id has to drop NOT NULL for global stock rows.
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable()->change();
        });

        Schema::table('characters', function (Blueprint $table) {
            // The actor grid always filters on these together.
            $table->index(['is_stock', 'status'], 'characters_stock_status_index');
            $table->index(['gender', 'age_group'], 'characters_gender_age_index');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex('characters_stock_status_index');
            $table->dropIndex('characters_gender_age_index');
            $table->dropColumn(['gender', 'age_group', 'situations', 'preview_asset_id', 'is_stock']);
        });

        // Left nullable on purpose: any stock row created while this shipped
        // would violate NOT NULL, and failing a rollback on real data is worse
        // than a looser column.
    }
};
