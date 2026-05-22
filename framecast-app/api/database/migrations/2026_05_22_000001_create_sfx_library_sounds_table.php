<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sfx_library_sounds', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->string('storage_url');           // minio:// or b2:// path
            $table->float('duration_seconds')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('source')->nullable();    // e.g. "pixabay", "mixkit", "user_upload"
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('status')->default('active'); // active | archived
            $table->timestamps();

            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sfx_library_sounds');
    }
};
