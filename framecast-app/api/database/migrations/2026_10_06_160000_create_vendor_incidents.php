<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Every classified vendor failure (refused, busy, our credit or key), for alerts and the daily summary.
    public function up(): void
    {
        Schema::create('vendor_incidents', function (Blueprint $t) {
            $t->id();
            $t->string('vendor', 32);
            $t->string('kind', 24);
            $t->text('message');
            $t->uuid('run_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->json('context_json')->nullable();
            $t->timestamp('created_at')->index();
            $t->index(['vendor', 'kind', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('vendor_incidents'); }
};
