<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Set when the user first approves sending this conversation's brief and
        // media to the provider; small jobs may auto-run only after it exists.
        Schema::table('create_conversations', fn (Blueprint $t) => $t->timestamp('provider_consent_at')->nullable());
    }

    public function down(): void { Schema::table('create_conversations', fn (Blueprint $t) => $t->dropColumn('provider_consent_at')); }
};
