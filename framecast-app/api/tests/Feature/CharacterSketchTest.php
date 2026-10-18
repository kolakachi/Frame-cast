<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Services\Generation\Image\CharacterImageAdapter;
use App\Services\Ugc\CharacterSketch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Mockery;
use Tests\TestCase;

/** A presenter's pencil sheet is drawn once from their photo and kept in its own table, never in the asset library. */
class CharacterSketchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sketch_test', 'database.connections.sketch_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sketch_test');
        Storage::fake('minio');
        Schema::create('assets', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); foreach (['asset_type', 'title', 'storage_url', 'mime_type', 'file_size_bytes', 'tags', 'status', 'created_by_user_id'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
        Schema::create('characters', function (Blueprint $t) { $t->id(); $t->integer('workspace_id')->nullable(); $t->text('name'); $t->integer('reference_asset_id')->nullable(); $t->timestamps(); });
        (require database_path('migrations/2026_10_19_010000_create_character_sketches.php'))->up();
        Storage::disk('minio')->put('stock/layla.jpg', 'layla-photo');
        DB::table('assets')->insert(['id' => 4105, 'workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'minio://stock/layla.jpg', 'mime_type' => 'image/jpeg']);
        DB::table('characters')->insert(['id' => 101, 'workspace_id' => null, 'name' => 'Layla', 'reference_asset_id' => 4105]);
    }

    public function test_a_stock_presenters_sheet_is_drawn_once_and_kept_out_of_the_library(): void
    {
        $images = Mockery::mock(CharacterImageAdapter::class);
        $images->shouldReceive('generate')->once()->withArgs(fn ($prompt, $style, $aspect, $options) => $aspect === '16:9'
            && $options['reference_image_url'] === 'data:image/jpeg;base64,'.base64_encode('layla-photo') && str_contains($prompt, 'graphite pencil'))
            ->andReturn(['image_b64' => base64_encode('sheet-png')]);
        $this->app->instance(CharacterImageAdapter::class, $images);

        $first = app(CharacterSketch::class)->uri(Character::find(101));
        $again = app(CharacterSketch::class)->uri(Character::find(101));
        $this->assertSame('data:image/png;base64,'.base64_encode('sheet-png'), $first);
        $this->assertSame($first, $again, 'drawn once');
        $this->assertSame(1, DB::table('character_sketches')->where('character_id', 101)->where('from_asset_id', 4105)->count());
        $this->assertSame(1, DB::table('assets')->count(), 'never an asset: the library shows only the photo');
    }

    public function test_a_new_photo_gets_a_new_sheet(): void
    {
        $images = Mockery::mock(CharacterImageAdapter::class);
        $images->shouldReceive('generate')->twice()->andReturn(['image_b64' => base64_encode('sheet-1')], ['image_b64' => base64_encode('sheet-2')]);
        $this->app->instance(CharacterImageAdapter::class, $images);
        app(CharacterSketch::class)->uri(Character::find(101));
        DB::table('assets')->insert(['id' => 5000, 'workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'minio://stock/layla.jpg', 'mime_type' => 'image/jpeg']);
        DB::table('characters')->where('id', 101)->update(['reference_asset_id' => 5000]);
        $this->assertSame('data:image/png;base64,'.base64_encode('sheet-2'), app(CharacterSketch::class)->uri(Character::find(101)));
    }
}
