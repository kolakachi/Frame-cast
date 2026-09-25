<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A quote is the approval token for spending through the developer
        // API: estimate first, then create with the quote's id. The payload
        // is frozen here so what gets built is exactly what was priced.
        Schema::create('api_quotes', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('api_key_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('payload_json');
            $table->unsignedInteger('credits_min');
            $table->unsignedInteger('credits_max');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamps();

            // Replaying a create with the same key returns the same video;
            // reusing a key for a different quote is refused.
            $table->unique(['workspace_id', 'idempotency_key']);
        });

        // Which key created a project. Spend attribution per key is derived
        // from the ledger's project_id, so the ledger itself is unchanged.
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('api_key_id')->nullable()->index()->after('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('api_key_id');
        });
        Schema::dropIfExists('api_quotes');
    }
};
