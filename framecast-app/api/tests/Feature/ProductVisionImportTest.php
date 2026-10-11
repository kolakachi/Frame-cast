<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Create\References\PageReferenceService;
use App\Services\Media\StorageService;
use App\Services\Ugc\{ProductImageFinder, ProductVision};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Process, Schema};
use Mockery;
use Tests\TestCase;

/** VISION (2026-10-11): each product photo is read once; a product page brings only its real product shots. */
class ProductVisionImportTest extends TestCase
{
    private array $stored = [];
    private string $photo = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pv_test', 'database.connections.pv_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'services.anthropic.key' => 'test-key']);
        DB::purge('pv_test');
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'asset_type', 'title', 'description', 'storage_url', 'mime_type', 'file_size_bytes', 'dimensions_json', 'metadata_json', 'tags', 'status', 'created_by_user_id', 'deleted_at'] as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        $tmp = tempnam(sys_get_temp_dir(), 'pvt').'.jpg';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=white:s=640x640', '-frames:v', '1', $tmp]);
        $this->photo = (string) file_get_contents($tmp);
        @unlink($tmp);
        $storage = Mockery::mock(StorageService::class);
        $storage->shouldReceive('put')->andReturnUsing(function ($path, $bytes) { $this->stored[$path] = $bytes; return 'b2://'.$path; });
        $storage->shouldReceive('get')->andReturnUsing(fn ($url) => $this->stored[substr($url, 5)] ?? $this->photo);
        $storage->shouldReceive('delete')->andReturnUsing(function ($url) { unset($this->stored[substr($url, 5)]); return true; });
        $this->app->instance(StorageService::class, $storage);
        PageReferenceService::$resolve = fn () => ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        PageReferenceService::$resolve = null;
        parent::tearDown();
    }

    private static function answer(string $kind, string $form, ?bool $match = null): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode(['kind' => $kind, 'what' => 'a face sunscreen', 'form' => $form, 'size' => 'hand length', 'look' => 'white, orange cap', 'label' => 'Sunly']
            + ($match === null ? [] : ['match' => $match]))]]];
    }

    public function test_a_photo_is_read_once_and_kept_on_the_asset(): void
    {
        DB::table('assets')->insert(['id' => 7, 'workspace_id' => 1, 'asset_type' => 'image', 'title' => 'IMG_2231.jpg', 'storage_url' => 'b2://x/7.jpg']);
        Http::fake(['api.anthropic.com/*' => Http::response(self::answer('product', 'squeeze tube'))]);
        $vision = app(ProductVision::class);
        $this->assertSame('a face sunscreen (squeeze tube); white, orange cap; hand length; the label is printed with "Sunly"', $vision->look([7], 1));
        $this->assertSame('squeeze tube', $vision->sheets([7], 1)[7]['form']);
        Http::assertSentCount(1);
        $this->assertSame([], $vision->sheets([7], 2), "another workspace's photo is never read");
    }

    public function test_a_product_page_brings_its_product_shots_and_drops_the_rest(): void
    {
        $html = '<html><head><meta property="og:image" content="https://cdn.sunly.com/banner.jpg">'
            .'<script type="application/ld+json">{"@type":"Product","image":["https://cdn.sunly.com/front.jpg","https://cdn.sunly.com/front_450x450.jpg","https://cdn.sunly.com/peptide.jpg"]}</script></head></html>';
        Http::fake([
            'sunly.com/products/spf50.json' => Http::response(['product' => ['images' => [['src' => 'https://cdn.sunly.com/back.jpg']]]], 200, ['Content-Type' => 'application/json']),
            'sunly.com/products/spf50' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'cdn.sunly.com/*' => Http::response($this->photo, 200, ['Content-Type' => 'image/jpeg']),
            // In the order saved: front, peptide (a sibling product from the same shop), banner, back. The 450 px copy of
            // the front is the same photo and never fetched.
            'api.anthropic.com/*' => Http::sequence()->push(self::answer('product', 'squeeze tube', true))->push(self::answer('product', 'pump bottle', false))
                ->push(self::answer('other', 'banner'))->push(self::answer('product', 'squeeze tube', true)),
        ]);
        $user = (new User)->forceFill(['id' => 1, 'workspace_id' => 1]);
        $ids = app(ProductImageFinder::class)->import($user, 'https://sunly.com/products/spf50', 'Sunly SPF 50 face sunscreen');

        $this->assertCount(2, $ids, 'front and back kept; the banner and the sibling product dropped');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains(json_encode($r->data()), 'Sunly SPF 50 face sunscreen'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'front_450x450'));
        $this->assertSame(['front.jpg', 'back.jpg'], DB::table('assets')->whereIn('id', $ids)->orderBy('id')->pluck('title')->all());
        $this->assertSame(2, DB::table('assets')->count(), 'the banner is not left in their library');
        $this->assertCount(2, $this->stored);
        $this->assertSame(['product_link'], json_decode(DB::table('assets')->where('id', $ids[0])->value('tags'), true));
    }

    public function test_a_page_on_a_private_address_is_never_fetched(): void
    {
        PageReferenceService::$resolve = fn () => ['10.0.0.5'];
        Http::fake();
        $this->assertSame([], app(ProductImageFinder::class)->import((new User)->forceFill(['id' => 1, 'workspace_id' => 1]), 'https://intranet.example.com/products/x'));
        Http::assertNothingSent();
    }
}
