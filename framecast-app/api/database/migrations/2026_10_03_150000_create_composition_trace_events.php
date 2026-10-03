<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('composition_trace_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('run_id');
            $t->foreign('run_id')->references('id')->on('composition_runs')->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->json('event_json');
            $t->timestamp('created_at');
            $t->unique(['run_id', 'sequence']);
        });
    }
    public function down(): void { Schema::dropIfExists('composition_trace_events'); }
};
