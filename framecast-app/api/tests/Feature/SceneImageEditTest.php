<?php

namespace Tests\Feature;

use App\Jobs\EditSceneImageJob;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Generation\Image\ImageGenerationAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Event, Http, Redis, Schema, Storage};
use Tests\TestCase;

/**
 * Editing a scene image instead of rerolling it.
 *
 * A customer spent 96 credits on six rerolls of one ad before getting a frame
 * he could use, because regenerating discards the picture and rolls again.
 * These pin the properties that make an edit worth having: it costs less than
 * the reroll it replaces, it feeds the current image back to the model rather
 * than starting blank, a failure costs nothing, and the image it replaced
 * survives so an unwanted edit is not another purchase.
 */
class SceneImageEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['database.default' => 'edit_test', 'database.connections.edit_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('edit_test');
        Event::fake([\App\Events\GenerationProgressed::class]);
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex')->andReturn(true);

        foreach ([
            'workspaces' => ['name', 'plan_tier', 'status'],
            'projects'   => ['workspace_id', 'channel_id', 'created_by_user_id', 'aspect_ratio', 'title', 'status'],
            'scenes'     => ['project_id', 'scene_order', 'visual_asset_id', 'visual_style', 'image_generation_settings_json'],
            'assets'     => ['workspace_id', 'channel_id', 'asset_type', 'title', 'description', 'storage_url',
                             'thumbnail_url', 'duration_seconds', 'dimensions_json', 'mime_type', 'tags'],
            'users'      => ['workspace_id', 'email'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $c) { $t->text($c)->nullable(); }
                $t->timestamps();
            });
        }
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('operation');
            foreach (['spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
            }
            $t->decimal('upstream_cost_usd', 12, 6)->nullable();
            $t->integer('credits'); $t->integer('balance_after')->nullable();
            $t->json('metadata')->nullable(); $t->timestamps();
        });
        Schema::table('workspaces', fn (Blueprint $t) => $t->integer('credits_topup')->default(0));
        Schema::table('workspaces', fn (Blueprint $t) => $t->integer('credits_monthly')->default(0));
    }

    /** @return array{0:Scene,1:Asset,2:int} scene, its current image, workspace id */
    private function scene(int $credits = 1000): array
    {
        $ws = DB::table('workspaces')->insertGetId(['name' => 'W', 'plan_tier' => 'starter', 'status' => 'active', 'credits_topup' => $credits]);
        $project = Project::query()->create(['workspace_id' => $ws, 'aspect_ratio' => '9:16', 'title' => 'Ad', 'status' => 'ready_for_review']);
        $asset = Asset::query()->create([
            'workspace_id' => $ws, 'asset_type' => 'image', 'title' => 'original',
            'storage_url' => 'https://cdn.test/original.png', 'mime_type' => 'image/png',
        ]);
        $scene = Scene::query()->create([
            'project_id' => $project->getKey(), 'scene_order' => 1,
            'visual_asset_id' => $asset->getKey(), 'visual_style' => 'photorealistic',
        ]);

        return [$scene, $asset, $ws];
    }

    /** Swap in an adapter that records what it was asked to do. */
    private function captureAdapter(): object
    {
        $spy = new class implements ImageGenerationAdapter {
            public array $seen = [];
            public function generate(string $prompt, string $style, string $aspectRatio = '9:16', array $options = []): array
            {
                $this->seen = compact('prompt', 'style', 'aspectRatio', 'options');
                return ['provider_key' => 'nano-banana', 'image_url' => null,
                        'image_b64' => base64_encode('PNGDATA'), 'width' => 1080, 'height' => 1920,
                        'seed' => null, 'revised_prompt' => null];
            }
            public function providerKey(): string { return 'nano-banana'; }
        };

        $factory = \Mockery::mock(ImageAdapterFactory::class)->makePartial();
        $factory->shouldReceive('referenceAdapter')->andReturn($spy);
        $this->app->instance(ImageAdapterFactory::class, $factory);

        return $spy;
    }

    public function test_an_edit_costs_less_than_the_reroll_it_replaces(): void
    {
        $factory = app(ImageAdapterFactory::class);

        $edit   = $factory->referenceGenerationCost(EditSceneImageJob::EDIT_MODEL);
        $reroll = $factory->generationCost(null, false);

        $this->assertLessThan($reroll, $edit,
            "an edit that costs more than a reroll solves nothing (edit {$edit}cr vs reroll {$reroll}cr)");
    }

    public function test_the_current_image_is_sent_to_the_model_to_edit(): void
    {
        [$scene, $asset] = $this->scene();
        $spy = $this->captureAdapter();
        Storage::fake('s3');

        (new EditSceneImageJob($scene->getKey(), (int) $scene->project_id, 'Remove the text on the wall'))->handle();

        $this->assertSame('Remove the text on the wall', $spy->seen['prompt'],
            'the instruction is the prompt — not a rewritten scene description');
        $this->assertNotEmpty($spy->seen['options']['reference_image_urls'] ?? [],
            'without the current image this is just another reroll');
    }

    public function test_the_scene_points_at_the_edit_and_keeps_the_original(): void
    {
        [$scene, $original] = $this->scene();
        $this->captureAdapter();
        Storage::fake('s3');

        (new EditSceneImageJob($scene->getKey(), (int) $scene->project_id, 'Make it brighter'))->handle();

        $fresh = $scene->fresh();
        $this->assertNotSame((int) $original->getKey(), (int) $fresh->visual_asset_id, 'the scene moves to the edit');
        $this->assertNotNull(Asset::find($original->getKey()), 'the image it replaced must survive');
        $this->assertSame((int) $original->getKey(),
            (int) data_get($fresh->image_generation_settings_json, 'edited_from_asset_id'),
            'so undoing an edit is a pointer change, not another purchase');
    }

    public function test_it_is_metered_separately_from_a_reroll(): void
    {
        [$scene, , $ws] = $this->scene();
        $this->captureAdapter();
        Storage::fake('s3');

        (new EditSceneImageJob($scene->getKey(), (int) $scene->project_id, 'Wider shot'))->handle();

        $this->assertSame(1, DB::table('credit_ledger')->where('operation', 'ai_image:edit')->count(),
            'edits need their own operation or we can never tell if they replaced rerolls');
        $this->assertSame(0, DB::table('credit_ledger')->where('operation', 'ai_image:manual')->count());
    }

    public function test_a_failed_edit_costs_nothing(): void
    {
        [$scene, , $ws] = $this->scene(1000);
        $factory = \Mockery::mock(ImageAdapterFactory::class)->makePartial();
        $factory->shouldReceive('referenceAdapter')->andThrow(new \RuntimeException('provider down'));
        $this->app->instance(ImageAdapterFactory::class, $factory);

        try {
            (new EditSceneImageJob($scene->getKey(), (int) $scene->project_id, 'Wider shot'))->handle();
        } catch (\RuntimeException) { /* surfaced to the queue */ }

        $this->assertSame(1000, (int) app(CreditService::class)->balance($ws),
            'a provider failure must leave the balance untouched');
    }

    public function test_a_scene_with_no_image_is_refused_rather_than_generated(): void
    {
        [$scene, , $ws] = $this->scene();
        $scene->forceFill(['visual_asset_id' => null])->save();

        (new EditSceneImageJob($scene->getKey(), (int) $scene->project_id, 'Wider shot'))->handle();

        $this->assertSame(1000, (int) app(CreditService::class)->balance($ws),
            'editing nothing must not quietly become a paid generation');
        $this->assertStringContainsString('no image to edit',
            (string) data_get($scene->fresh()->image_generation_settings_json, 'last_error'));
    }
}
