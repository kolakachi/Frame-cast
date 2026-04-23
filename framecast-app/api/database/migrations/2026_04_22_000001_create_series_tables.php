<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('concept_text')->nullable();
            $table->text('audience_text')->nullable();
            $table->string('tone')->nullable();
            $table->text('episode_format_template')->nullable();
            $table->jsonb('always_include_tags')->nullable();
            $table->jsonb('never_include_tags')->nullable();
            $table->unsignedTinyInteger('memory_window')->default(3);
            $table->boolean('auto_summarise')->default(true);
            $table->string('status')->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('series_characters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->string('name');
            $table->text('visual_description')->nullable();
            $table->text('personality_notes')->nullable();
            $table->jsonb('appearance_json')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('series_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_characters');
        Schema::dropIfExists('series');
    }
};
