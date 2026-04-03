<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_hook_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(1);
            $table->text('hook_text');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_hook_options');
    }
};
