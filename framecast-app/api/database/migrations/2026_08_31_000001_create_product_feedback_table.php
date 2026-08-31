<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();      // 1-5
            $table->json('options')->nullable();                    // picked chips
            $table->text('comment')->nullable();
            $table->string('trigger', 40)->default('export');       // what prompted it
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('export_job_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feedback');
    }
};
