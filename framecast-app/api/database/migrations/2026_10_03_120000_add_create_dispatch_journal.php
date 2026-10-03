<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('composition_runs', fn (Blueprint $t) => $t->timestamp('worker_stopped_at')->nullable());
        Schema::table('composition_attempts', function (Blueprint $t) {
            $t->timestamp('dispatched_at')->nullable();
            $t->longText('provider_response_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('composition_attempts', fn (Blueprint $t) => $t->dropColumn(['dispatched_at', 'provider_response_json']));
        Schema::table('composition_runs', fn (Blueprint $t) => $t->dropColumn('worker_stopped_at'));
    }
};
