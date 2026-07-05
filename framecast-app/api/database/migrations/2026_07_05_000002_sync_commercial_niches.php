<?php

use App\Models\Niche;
use Database\Seeders\NicheSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Pivot the niche lineup from story/faceless to the commercial set (see
 * NicheSeeder + Niche::PLAYBOOK). Upserts the new niches, then removes the
 * retired ones. projects.niche_id is nullOnDelete, so any project still on a
 * removed niche falls back to null (Custom) automatically — no manual remap.
 */
return new class extends Migration
{
    private const KEEP = ['product', 'product-launch', 'ad-creative', 'explainer', 'brand-story', 'motivation'];

    public function up(): void
    {
        (new NicheSeeder)->run();

        Niche::query()->whereNotIn('slug', self::KEEP)->delete();
    }

    public function down(): void
    {
        // One-way data migration — retired niches (horror, finance, history,
        // science, true-crime, self-improvement) are not restored.
    }
};
