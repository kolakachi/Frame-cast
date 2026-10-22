<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_access_emails', function (Blueprint $table) {
            // One email per checkout receipt, even when its main product and bump arrive concurrently.
            $table->string('receipt')->primary();
            $table->timestamp('queued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_access_emails');
    }
};
