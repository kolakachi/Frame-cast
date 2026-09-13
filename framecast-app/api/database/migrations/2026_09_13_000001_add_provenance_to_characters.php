<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record where a stock actor's likeness came from.
 *
 * Customers attest that they have the rights and consent to use a person's
 * likeness before a face is made to speak. For a stock actor, WyvStudio is the
 * one making that claim, so it has to be answerable: every stock actor must be
 * demonstrably model-generated and depict no real person.
 *
 * Written at creation, because it cannot be reconstructed honestly afterwards —
 * once a library has grown, nobody can say with confidence how any given row
 * was produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // 'ai_generated' — synthesised from a text prompt, no real person.
            // 'licensed'     — a real performer under a release covering
            //                  synthetic and AI reuse.
            // null           — a customer's own character, where the customer's
            //                  own attestation governs and this does not apply.
            $table->string('provenance', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('provenance');
        });
    }
};
