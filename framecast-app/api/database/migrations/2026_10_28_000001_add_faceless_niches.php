<?php

use Database\Seeders\NicheSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Faceless formats back in the niche list (2026-10-28): Facts & Lists, History, Science & Space, Stories, Scary &
 * Mystery, AI & Tech Tips, alongside the commercial ones. The seeder upserts by slug, so this is safe to re-run and
 * keeps existing niches (and the projects pointing at them) as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NicheSeeder)->run();
    }

    public function down(): void
    {
        // Data only: the niches stay; projects may point at them.
    }
};
