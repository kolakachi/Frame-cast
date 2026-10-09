<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Weave onboarding's answers (2026-10-09): what the workspace makes most, its website as read, how it heard of
// us, and when it finished or skipped. Kept on the workspace so suggestions and defaults can use them later.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'onboarding_json')) Schema::table('workspaces', fn (Blueprint $t) => $t->json('onboarding_json')->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'onboarding_json')) Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('onboarding_json'));
    }
};
