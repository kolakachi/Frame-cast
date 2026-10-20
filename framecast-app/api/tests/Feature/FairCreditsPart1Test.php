<?php

namespace Tests\Feature;

use App\Services\Create\{CostEstimate, PlanMediaExecutor, RunService, TemplateCutout};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/**
 * Fair credits, Part 1 (owner, 2026-10-20): no charge for broken work. Cases from the Beats audit and the prod check of
 * the cost audit: Sonnet plans priced at Opus rates, a gym scene "cut out" as the headphones, failed builds that kept
 * their credits.
 */
class FairCreditsPart1Test extends TestCase
{
    public function test_planning_is_priced_at_the_model_that_ran(): void
    {
        $call = ['input_tokens' => 20000, 'output_tokens' => 8000, 'cache_write_tokens' => 10000, 'cache_read_tokens' => 0];
        $opus = CostEstimate::callCredits($call);
        $sonnet = CostEstimate::callCredits($call, 'anthropic:claude-sonnet-5');
        // Opus 80,000 + 160,000 + 50,000 µ$ = 73 credits; Sonnet 60,000 + 120,000 + 37,500 µ$ = 55.
        $this->assertSame(73, $opus);
        $this->assertSame(55, $sonnet);
        $this->assertSame($opus, CostEstimate::callCredits($call, 'anthropic:claude-opus-5-5'), 'a model with no rates of its own uses the tariff');
    }

    public function test_the_cut_out_takes_the_file_its_description_names_even_with_spaces(): void
    {
        $files = [['name' => 'asset-5800-aa.jpg', 'title' => 'kenoye', 'path' => '/x/kenoye.jpg'],
            ['name' => 'asset-5928-bb.jpg', 'title' => 'Beats Solo 4 - Wireless Bluetooth On-Ear Headphones, Apple & Android Compatible.jpg', 'path' => '/x/beats.jpg']];
        $this->assertSame('/x/beats.jpg', PlanMediaExecutor::namedFile('Beats Solo 4 - Wireless Bluetooth On-Ear Headphones, Apple & Android Compatible.jpg cut out for the end-card hero', $files)['path']);
        $this->assertSame('/x/beats.jpg', PlanMediaExecutor::namedFile('asset-5928-bb.jpg: the headset', $files)['path'], 'the stored name still works');
        $this->assertNull(PlanMediaExecutor::namedFile('the generated mug, background removed', $files), 'names no file: the image bought just before it');
    }

    public function test_a_cut_out_that_removed_nothing_is_told_apart(): void
    {
        $dir = sys_get_temp_dir(); $whole = $dir.'/whole-'.uniqid().'.png'; $cut = $dir.'/cut-'.uniqid().'.png';
        exec('ffmpeg -v error -y -f lavfi -i color=c=gray:s=300x500,format=rgba -frames:v 1 '.escapeshellarg($whole));
        exec('ffmpeg -v error -y -f lavfi -i color=c=black@0.0:s=300x500,format=rgba -f lavfi -i color=c=white:s=120x160,format=rgba'
            .' -filter_complex "[0][1]overlay=90:170,format=rgba" -frames:v 1 '.escapeshellarg($cut));
        $this->assertTrue(TemplateCutout::removedNothing((string) file_get_contents($whole)), 'a scene that came back whole');
        $this->assertFalse(TemplateCutout::removedNothing((string) file_get_contents($cut)), 'a product on a clear background');
        @unlink($whole); @unlink($cut);
    }

    public function test_a_failed_build_gets_its_builder_credits_back_once(): void
    {
        config(['database.default' => 'fair_test', 'database.connections.fair_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('fair_test');
        Schema::create('composition_attempts', function (Blueprint $t) { $t->string('id')->primary(); $t->string('run_id'); $t->string('kind'); $t->integer('charged_credits')->default(0); });
        Schema::create('credit_ledger', function (Blueprint $t) { $t->id(); $t->string('operation'); });
        DB::table('composition_attempts')->insert([['id' => 'a1', 'run_id' => 'run-1', 'kind' => 'agent', 'charged_credits' => 71], ['id' => 'a2', 'run_id' => 'run-1', 'kind' => 'agent', 'charged_credits' => 41],
            ['id' => 'a3', 'run_id' => 'run-1', 'kind' => 'plan_media', 'charged_credits' => 88]]);
        $credits = \Mockery::mock(\App\Services\CreditService::class);
        $credits->shouldReceive('refund')->once()->with(79, 112, 'create-failed:run-1')->andReturnUsing(fn () => DB::table('credit_ledger')->insert(['operation' => 'refund:create-failed:run-1']));
        $this->app->instance(\App\Services\CreditService::class, $credits);
        $run = (object) ['id' => 'run-1', 'workspace_id' => 79];
        $this->assertSame(112, RunService::refundFailedBuild($run), 'the builder calls come back; the bought clip is kept for Retry');
        $this->assertSame(0, RunService::refundFailedBuild($run), 'never twice');
    }
}
