<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Another concept from the same brief (owner, 2026-10-15; workspace 82 pasted one brief into eight conversations):
 * concept_of names the conversation whose brief this one reuses, so the concepts of one brief stay together and the
 * director knows what was already made. concept_key makes starting one replay-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('create_conversations', function (Blueprint $t) {
            $t->uuid('concept_of')->nullable()->index();
            $t->string('concept_key', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('create_conversations', fn (Blueprint $t) => $t->dropColumn(['concept_of', 'concept_key']));
    }
};
