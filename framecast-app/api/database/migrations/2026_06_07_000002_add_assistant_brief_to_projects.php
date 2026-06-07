<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // Cruise-facing creative brief synthesised from the scenes
            // (theme / topic / visual_style / tone / recurring_subject), so
            // the assistant defaults new work to the established direction.
            // Distinct from `visual_brief` (the image consistency card).
            $table->json('assistant_brief_json')->nullable()->after('generation_status_json');
            // When the user edits the brief we lock it so auto-refresh
            // never clobbers their wording.
            $table->boolean('assistant_brief_locked')->default(false)->after('assistant_brief_json');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['assistant_brief_json', 'assistant_brief_locked']);
        });
    }
};
