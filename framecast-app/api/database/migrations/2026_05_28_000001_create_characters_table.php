<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // Uploaded reference image (workspace asset). Used as the chip thumbnail today;
            // will become the IP-Adapter / LoRA training source when visual consistency lands.
            $table->foreignId('reference_asset_id')->nullable()
                ->constrained('assets')->nullOnDelete();
            // 'quick' = description-injected into prompt (today)
            // 'lora'  = trained LoRA reference (future)
            $table->string('consistency_method', 16)->default('quick');
            $table->string('status', 16)->default('active'); // active | archived
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
