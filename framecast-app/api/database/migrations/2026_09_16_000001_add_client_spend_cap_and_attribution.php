<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two halves of the same question: which client spent this, and may they.
 *
 * The spender was already being recorded, but inside the ledger's json
 * metadata, where it cannot be indexed. That was tolerable for a report run
 * once a day and is not tolerable for a ceiling that has to be checked under
 * a row lock before every single generation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('spent_by_workspace_id')->nullable()->after('workspace_id');
            // Answers "what has this client spent since <date>" from the index
            // alone. Leading with the pool because every query is scoped to one
            // agency before it cares about the client.
            $table->index(['workspace_id', 'spent_by_workspace_id', 'created_at'], 'credit_ledger_pool_client_idx');
        });

        // Carry across what the json already knows, so existing spend is not
        // invisible the moment the column becomes the source of truth.
        if (DB::getDriverName() === 'pgsql') {
            // Deliberately not `metadata ? 'key'`: Postgres' jsonb existence
            // operator is a question mark, and PDO reads that as a parameter
            // placeholder and refuses the statement. ->> returns NULL for a
            // missing key, which asks the same question and survives binding.
            DB::statement(<<<'SQL'
                UPDATE credit_ledger
                   SET spent_by_workspace_id = NULLIF(metadata->>'spent_by_workspace_id', '')::bigint
                 WHERE metadata->>'spent_by_workspace_id' IS NOT NULL
                   AND spent_by_workspace_id IS NULL
            SQL);
        }

        Schema::table('workspaces', function (Blueprint $table) {
            // Null means no ceiling, which is what every existing client has
            // and must keep — a migration that quietly imposed a limit would
            // stop work that was running fine.
            $table->unsignedInteger('monthly_credit_cap')->nullable()->after('credits_topup');
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropIndex('credit_ledger_pool_client_idx');
            $table->dropColumn('spent_by_workspace_id');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('monthly_credit_cap');
        });
    }
};
