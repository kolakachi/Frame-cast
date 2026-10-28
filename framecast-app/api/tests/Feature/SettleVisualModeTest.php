<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CreditService;
use App\Services\Projects\ProjectCreationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** A project made without a visual mode is given one before its price is checked (fix A3, 2026-10-28). */
class SettleVisualModeTest extends TestCase
{
    private function service(int $balance): ProjectCreationService
    {
        $credits = Mockery::mock(CreditService::class)->makePartial();
        $credits->shouldReceive('balance')->andReturn($balance);
        $this->app->instance(CreditService::class, $credits);
        return app(ProjectCreationService::class);
    }

    public function test_ai_images_only_when_the_balance_covers_them_at_todays_price(): void
    {
        $user = (new User)->forceFill(['id' => 1, 'workspace_id' => 1]);
        $brief = ['source_type' => 'prompt', 'source_content_raw' => 'Facts about octopuses', 'duration_target_seconds' => 60];
        $aiMin = app(CreditService::class)->estimateProject('prompt', 'Facts about octopuses', 'ai_images', 'medium', 60)['credits_min'];
        $this->assertSame('ai_images', $this->service($aiMin)->settleVisualMode($user, $brief));
        $this->assertSame('stock', $this->service($aiMin - 1)->settleVisualMode($user, $brief));
        $this->assertSame('ai_images', $this->service(0)->settleVisualMode($user, ['source_type' => 'images'] + $brief), 'uploaded images are always AI');
    }

    public function test_a_series_episode_takes_its_series_mode(): void
    {
        config(['database.default' => 'settle_test', 'database.connections.settle_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::create('series', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('visual_mode')->nullable(); $t->integer('duration_target_seconds')->nullable(); $t->timestamps(); });
        DB::table('series')->insert([['id' => 1, 'workspace_id' => 1, 'visual_mode' => 'stock'], ['id' => 2, 'workspace_id' => 1, 'visual_mode' => 'ai'], ['id' => 3, 'workspace_id' => 2, 'visual_mode' => 'stock']]);
        $user = (new User)->forceFill(['id' => 1, 'workspace_id' => 1]);
        $svc = $this->service(1000000);
        $this->assertSame('stock', $svc->settleVisualMode($user, ['source_type' => 'prompt', 'series_id' => 1]), 'even with credits for AI');
        $this->assertSame('ai_images', $svc->settleVisualMode($user, ['source_type' => 'prompt', 'series_id' => 2]));
        $this->assertSame('ai_images', $svc->settleVisualMode($user, ['source_type' => 'prompt', 'series_id' => 3]), "another workspace's series is ignored");
    }
}
