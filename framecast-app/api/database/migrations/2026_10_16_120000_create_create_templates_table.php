<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Templates (owner, 2026-10-16): finished videos anyone can start from, one library with Create's examples ("One library").
 * A template is a reference like any other: the user picks how closely to follow it (exactly, similar, inspired).
 * source "version": a Create version we have the code of, so "exactly" starts from that version; source "video": a video
 * only, studied like any reference. The page is staff-only until CREATE_TEMPLATES_PUBLIC; the examples already on
 * Create's empty screen (on_samples) stay there for everyone. position orders the library and the Launchpad vault.
 */
return new class extends Migration
{
    /** The examples' tabs on the Templates page, by id (their old filter kinds were launch, explainer, ugc, ads, footage). */
    private const CATEGORY = [
        'b01' => 'announce', 's100' => 'explainer', 'b05' => 'ugc', 'l30703d4a' => 'offer', 'l3461c7aa' => 'explainer', 'l0f70e0d0' => 'app',
        'le0c668f6' => 'offer', 'b10' => 'explainer', 'b06' => 'ugc', 'l540' => 'announce', 'b03' => 'offer', 'l21287e12' => 'explainer',
        'b07' => 'ugc', 'lf23a856a' => 'offer', 'lc809947b' => 'explainer', 'l36b53c6d' => 'ugc', 'led2' => 'offer', 'l292' => 'announce',
        'ugc-1' => 'ugc', 'l8623176a' => 'app', 'le55486c6' => 'offer', 'lbaf5605f' => 'announce', 'l60f51590' => 'ugc', 'stock-presenter' => 'ugc',
        'l1c4' => 'announce', 'any-scene' => 'ugc', 'l1317b615' => 'announce', 'lf7bd88af' => 'ugc', 'b08' => 'footage', 'b09' => 'footage',
        'b02' => 'app', 'app-demo' => 'ugc',
    ];

    public function up(): void
    {
        Schema::create('create_templates', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('title', 120);
            $table->string('category', 16)->index();          // offer | app | explainer | ugc | announce | footage
            $table->text('description')->nullable();
            $table->string('ask_hint', 300)->nullable();      // what the user is asked for (the "Your product" placeholder)
            $table->string('style', 60)->nullable();
            $table->string('shape', 8)->default('9:16');
            $table->unsignedSmallInteger('seconds')->default(15);
            $table->unsignedInteger('position')->default(0)->index();
            $table->string('source', 8)->default('video');    // version | video
            $table->string('sample_folder', 16)->nullable();  // the example's folder on the public bucket (weave, ugc)
            $table->string('conversation_id', 40)->nullable();
            $table->string('revision_id', 40)->nullable();
            $table->json('parts_json')->nullable();
            $table->boolean('on_samples')->default(false);    // shown on Create's empty screen before Templates is public
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        $position = 0;
        foreach (\App\Services\Create\SampleLibrary::SAMPLES as $id => [$folder, $title, $kind, $seconds, $shape]) {
            DB::table('create_templates')->insert([
                'id' => $id, 'title' => $title, 'category' => self::CATEGORY[$id] ?? ($kind === 'ads' ? 'offer' : ($kind === 'launch' ? 'announce' : $kind)),
                'shape' => ['portrait' => '9:16', 'square' => '1:1', 'landscape' => '16:9'][$shape] ?? '9:16', 'seconds' => $seconds,
                'position' => ($position += 10), 'source' => 'video', 'sample_folder' => $folder, 'on_samples' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('create_templates');
    }
};
