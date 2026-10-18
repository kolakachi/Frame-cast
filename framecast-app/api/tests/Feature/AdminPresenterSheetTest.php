<?php

namespace Tests\Feature;

use App\Jobs\MakeCharacterSketch;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, DB, Schema, Storage, URL};
use Tests\TestCase;

/** Admin sees each stock presenter's photo beside their pencil sheet, uploads or redraws one (owner, 2026-10-19). */
class AdminPresenterSheetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'presenter_admin_test', 'database.connections.presenter_admin_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('presenter_admin_test');
        Bus::fake();
        Storage::fake('minio');
        Schema::create('assets', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); foreach (['asset_type', 'title', 'storage_url', 'mime_type'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
        Schema::create('characters', function (Blueprint $t) { $t->id(); $t->integer('workspace_id')->nullable(); $t->text('name'); $t->text('description')->nullable(); $t->text('gender')->nullable();
            $t->text('status')->default('active'); $t->boolean('is_stock')->default(false); $t->integer('reference_asset_id')->nullable(); $t->timestamps(); });
        (require database_path('migrations/2026_10_19_010000_create_character_sketches.php'))->up();
        (require database_path('migrations/2026_10_19_020000_add_source_to_character_sketches.php'))->up();
        DB::table('assets')->insert([['id' => 1, 'workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'minio://a.jpg', 'mime_type' => 'image/jpeg'],
            ['id' => 2, 'workspace_id' => 1, 'asset_type' => 'image', 'storage_url' => 'minio://b.jpg', 'mime_type' => 'image/jpeg']]);
        DB::table('characters')->insert([['id' => 101, 'name' => 'Layla', 'is_stock' => true, 'reference_asset_id' => 1],
            ['id' => 60, 'name' => 'Nadia', 'is_stock' => true, 'reference_asset_id' => 2],
            ['id' => 900, 'name' => 'A customer', 'is_stock' => false, 'reference_asset_id' => 1]]);
        DB::table('characters')->where('id', 900)->update(['workspace_id' => 7]);
        $this->withoutMiddleware();
        $this->actingAs((new User)->forceFill(['id' => 1, 'workspace_id' => 1, 'role' => 'super_admin']));
    }

    public function test_stock_presenters_are_listed_with_their_photo_and_sheet_and_customers_are_not(): void
    {
        $data = $this->getJson('/api/v1/admin/presenters')->assertOk()->json('data');
        $this->assertSame(['Layla', 'Nadia'], array_column($data['presenters'], 'name'));
        $this->assertSame(2, $data['missing']);
        $this->assertNull($data['presenters'][0]['sheet']);
        $this->assertStringContainsString('/media/assets/1', $data['presenters'][0]['photo_url']);
    }

    public function test_an_uploaded_sheet_replaces_the_drawn_one_and_never_becomes_an_asset(): void
    {
        $png = "\x89PNG\r\n\x1a\nsheet";
        $data = $this->post('/api/v1/admin/presenters/101/sheet', ['sheet' => UploadedFile::fake()->createWithContent('layla-sheet.png', $png)])->assertOk()->json('data');
        $layla = collect($data['presenters'])->firstWhere('id', 101);
        $this->assertSame('uploaded', $layla['sheet']['source']);
        $this->assertSame(1, $data['missing']);
        $this->assertSame(2, DB::table('assets')->count(), 'not in the library');
        $this->get($layla['sheet']['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($png, $this->get(URL::temporarySignedRoute('media.character-sketch', now()->addMinute(), ['characterId' => 101]))->getContent());
    }

    public function test_remake_and_make_all_missing_draw_in_the_background(): void
    {
        DB::table('character_sketches')->insert(['character_id' => 101, 'from_asset_id' => 1, 'storage_url' => 'minio://s.png', 'source' => 'drawn']);
        $this->postJson('/api/v1/admin/presenters/101/sheet/remake')->assertStatus(202);
        Bus::assertDispatched(MakeCharacterSketch::class, fn ($job) => $job->characterId === 101 && $job->remake && $job->userId === 1);
        $this->postJson('/api/v1/admin/presenters/sheets/missing')->assertStatus(202)->assertJsonPath('data.queued', 1);
        Bus::assertDispatched(MakeCharacterSketch::class, fn ($job) => $job->characterId === 60 && ! $job->remake);
        Bus::assertNotDispatched(MakeCharacterSketch::class, fn ($job) => $job->characterId === 900);
    }

    public function test_a_customers_character_has_no_sheet_page(): void
    {
        $this->postJson('/api/v1/admin/presenters/900/sheet/remake')->assertNotFound();
    }
}
