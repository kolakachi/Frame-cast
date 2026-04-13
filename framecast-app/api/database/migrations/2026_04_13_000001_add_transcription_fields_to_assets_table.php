<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->longText('transcript_text')->nullable();
            $table->string('transcription_status')->default('not_requested');
            $table->text('transcription_error')->nullable();
            $table->jsonb('metadata_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'transcript_text',
                'transcription_status',
                'transcription_error',
                'metadata_json',
            ]);
        });
    }
};
