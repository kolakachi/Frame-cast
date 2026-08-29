<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the user agreed to pay for reading this PDF's scanned pages.
 *
 * The consent has to survive the hop from wizard to queued job, and it has to
 * be per-project rather than a workspace setting: it's a decision about one
 * document ("4 of these 12 pages are scans — read them?"), not a preference.
 *
 * Defaults to false so the expensive path is never taken by omission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('pdf_read_scanned')->default(false)->after('source_content_normalized');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('pdf_read_scanned');
        });
    }
};
