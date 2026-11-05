<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_prechecks', function (Blueprint $table) {
            // One look before a paid clip (ClipPrecheck): what was found and what the prompt became.
            $table->id();
            $table->string('source', 32);           // create | ugc | editor
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->string('ref', 191)->nullable(); // plan item, project/segment or scene
            $table->string('verdict', 16);          // ok | rewritten | prompt | frames
            $table->json('issues_json')->nullable();
            $table->unsignedTinyInteger('images')->default(0);
            $table->text('prompt_before');
            $table->text('prompt_after')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_prechecks');
    }
};
