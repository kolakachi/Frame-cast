<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A template is free for a free account's first ad only after its render audit passed (create:template-audit).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('create_templates', fn (Blueprint $t) => $t->text('free_audit_json')->nullable());
        // The two that passed the hand audit of 2026-10-17 (docs/product/template-audit.md), before the command existed.
        DB::table('create_templates')->whereIn('id', ['tbdvabrrxr', 'lbaf5605f'])->update(['free_audit_json' => json_encode([
            'passed' => true, 'leftovers' => [], 'unseen' => [], 'overflow' => [], 'by' => 'marker render, contact sheets checked by hand', 'at' => '2026-10-17']),
        ]);
    }

    public function down(): void
    {
        Schema::table('create_templates', fn (Blueprint $t) => $t->dropColumn('free_audit_json'));
    }
};
