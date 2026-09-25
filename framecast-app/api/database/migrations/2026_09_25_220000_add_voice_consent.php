<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('voice_profiles', function (Blueprint $t) {
            $t->unsignedBigInteger('original_sample_asset_id')->nullable()->index();
            $t->unsignedBigInteger('consent_user_id')->nullable();
            $t->timestamp('consent_acknowledged_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('voice_profiles', fn (Blueprint $t) => $t->dropColumn(['original_sample_asset_id', 'consent_user_id', 'consent_acknowledged_at']));
    }
};
