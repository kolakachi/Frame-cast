<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cruise_action_runs', function (Blueprint $table): void {
            // Snapshot of the prior state the action overwrote, so it can be
            // undone (restore scene fields / delete an added scene / restore
            // scene orders / restore project music). Null = not undoable.
            $table->json('revert_json')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('cruise_action_runs', function (Blueprint $table): void {
            $table->dropColumn('revert_json');
        });
    }
};
