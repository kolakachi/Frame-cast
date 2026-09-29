<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('create_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('conversation_id')->index();
            $t->uuid('message_id');
            $t->unsignedInteger('brief_sequence');
            $t->string('idempotency_key', 128);
            $t->string('request_hash', 64);
            $t->string('provider', 120);
            $t->text('plan_json');
            $t->text('usage_json')->nullable();
            $t->string('status', 16)->default('proposed');
            $t->timestamps();
            $t->unique(['conversation_id', 'idempotency_key']);
        });
    }

    public function down(): void { Schema::dropIfExists('create_plans'); }
};
