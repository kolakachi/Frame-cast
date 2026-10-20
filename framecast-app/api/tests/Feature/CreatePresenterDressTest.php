<?php

namespace Tests\Feature;

use App\Services\CreditService;
use App\Services\Create\{PlanMediaExecutor, ShotRoute};
use App\Services\Ugc\UgcLook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Tests\TestCase;

/**
 * A lip-synced presenter (the user's cloned voice) is dressed before the take (owner, 2026-10-20): a partner asked for
 * his presenter "in a casual shirt at his tech office desk, holding the Beats Solo", the plan agreed, and the take
 * animated his photo as it was, outdoors before a banner, twice.
 */
class CreatePresenterDressTest extends TestCase
{
    private array $ctx = ['has_avatar' => true, 'has_sheet' => false, 'aspect_ratio' => '9:16', 'language' => 'en'];

    public function test_the_planner_can_set_what_the_presenter_wears_where_they_are_and_what_they_hold(): void
    {
        $f = ShotRoute::plannerFields(['outfit' => 'a casual blue button-down shirt', 'setting' => 'a tech office desk', 'holding' => 'Beats Solo headphones']);
        $this->assertSame(['a casual blue button-down shirt', 'a tech office desk', 'Beats Solo headphones'], [$f['outfit'], $f['setting'], $f['holding']]);
        foreach (['outfit', 'setting', 'holding', 'dress_credits'] as $k) $this->assertContains($k, ShotRoute::ROUTE_KEYS, "$k reaches the take");
    }

    public function test_dressing_a_lip_synced_presenter_is_priced_as_its_own_line(): void
    {
        $plain = ShotRoute::take(['presenter' => 'avatar'], ['narration' => ['They are called Beats Solo.'], 'voice' => 'clone'] + $this->ctx);
        $dressed = ShotRoute::take(['presenter' => 'avatar', 'outfit' => 'a casual shirt', 'holding' => 'Beats Solo headphones'], ['narration' => ['They are called Beats Solo.'], 'voice' => 'clone'] + $this->ctx);
        $this->assertArrayNotHasKey('dress_credits', $plain, 'the photo as it is: nothing extra');
        $this->assertSame(CreditService::AI_CHARACTER, $dressed['dress_credits']);
        $this->assertSame($plain['credits'] + CreditService::AI_CHARACTER, $dressed['credits']);
    }

    public function test_the_dressing_photo_can_put_the_product_in_their_hand(): void
    {
        $this->assertStringContainsString('holding Beats Solo headphones naturally', UgcLook::prompt('a casual shirt', 'a tech office desk', false, 'Beats Solo headphones'));
        $this->assertStringContainsString('hands empty', UgcLook::prompt('a casual shirt', 'a kitchen', false), 'a UGC take keeps its hands empty, as before');
    }

    public function test_the_take_lip_syncs_the_dressed_photo_not_the_raw_one(): void
    {
        config(['database.default' => 'dress_test', 'database.connections.dress_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'services.replicate.api_token' => 'test']);
        DB::purge('dress_test');
        Schema::create('assets', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('workspace_id')->nullable(); $t->string('storage_url')->nullable(); $t->string('mime_type')->nullable(); $t->timestamps(); });
        Schema::create('create_plan_media', function (Blueprint $t) { $t->string('id')->primary(); $t->string('plan_id'); $t->string('kind'); $t->string('status'); $t->text('record_json')->nullable(); $t->timestamps(); });
        $voice = DB::table('assets')->insertGetId(['workspace_id' => 1, 'storage_url' => 'minio://p/voice.wav', 'mime_type' => 'audio/wav']);
        DB::table('create_plan_media')->insert(['id' => 'v1', 'plan_id' => 'plan-1', 'kind' => 'cloned_voiceover', 'status' => 'succeeded', 'record_json' => json_encode(['file' => ['asset_id' => $voice]])]);
        $storage = \Mockery::mock(\App\Services\Media\StorageService::class);
        $storage->shouldReceive('get')->with('minio://p/voice.wav')->andReturn('WAV-BYTES');
        $this->app->instance(\App\Services\Media\StorageService::class, $storage);
        $photo = tempnam(sys_get_temp_dir(), 'kenoye').'.png'; file_put_contents($photo, 'RAW-PHOTO-OUTDOORS');
        $look = \Mockery::mock(UgcLook::class);
        $look->shouldReceive('make')->once()->withArgs(fn ($presenter, $place, $wardrobe, $placeText, $aspect, $holding) => str_contains($presenter, base64_encode('RAW-PHOTO-OUTDOORS'))
            && $wardrobe === 'a casual blue button-down shirt' && $placeText === 'a tech office desk' && $holding === 'Beats Solo headphones')
            ->andReturn(['uri' => 'data:image/png;base64,'.base64_encode('DRESSED-AT-DESK'), 'score' => 91]);
        $this->app->instance(UgcLook::class, $look);
        $fabric = \Mockery::mock(\App\Services\Generation\Video\ReplicateFabricAdapter::class);
        $fabric->shouldReceive('start')->once()->andReturn('fabric-1');
        $this->app->instance(\App\Services\Generation\Video\ReplicateFabricAdapter::class, $fabric);
        Http::fake(['api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/x']])]);
        $ctx = ['plan_id' => 'plan-1', 'workspace_id' => 1, 'aspect_ratio' => '9:16', 'source_images' => [$photo],
            'shot' => ['speech_mode' => 'cloned_lipsync', 'engine' => 'lipsync', 'presenter' => 'avatar', 'outfit' => 'a casual blue button-down shirt', 'setting' => 'a tech office desk', 'holding' => 'Beats Solo headphones']];
        $this->assertSame('fabric-1', app(PlanMediaExecutor::class)->startJob('ugc_take', 'Kenoye at his desk', $ctx, 0));
        Http::assertSent(fn ($r) => str_contains((string) $r->body(), 'DRESSED-AT-DESK'));
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body(), 'RAW-PHOTO-OUTDOORS'));
        @unlink($photo);
    }

    public function test_a_generated_voice_take_is_told_the_clothes_place_and_prop(): void
    {
        config(['database.default' => 'dress_test', 'database.connections.dress_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'services.replicate.api_token' => 'test']);
        DB::purge('dress_test');
        $veo = \Mockery::mock(\App\Services\Generation\Video\ReplicateVeoAdapter::class);
        $veo->shouldReceive('start')->once()->withArgs(fn ($prompt) => str_contains($prompt, 'They wear a casual blue shirt.') && str_contains($prompt, 'They are at a tech office desk.')
            && str_contains($prompt, 'They hold Beats Solo headphones, clearly visible.'))->andReturn('omni-1');
        $this->app->instance(\App\Services\Generation\Video\ReplicateVeoAdapter::class, $veo);
        $ctx = ['plan_id' => 'plan-1', 'workspace_id' => 1, 'aspect_ratio' => '9:16', 'language' => 'en',
            'shot' => ['engine' => 'omni', 'presenter' => 'none', 'seconds' => 5, 'segments' => [['lines' => ['They are called Beats Solo.'], 'seconds' => 5]],
                'outfit' => 'a casual blue shirt', 'setting' => 'a tech office desk', 'holding' => 'Beats Solo headphones']];
        $this->assertSame('omni-1', app(PlanMediaExecutor::class)->startJob('ugc_take', 'A creator at his desk', $ctx, 0));
    }
}
