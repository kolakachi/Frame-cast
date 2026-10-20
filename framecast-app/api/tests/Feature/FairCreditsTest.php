<?php

namespace Tests\Feature;

use App\Services\Create\{CostEstimate, PlanMediaExecutor, RunService, TemplateCutout};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Tests\TestCase;

/**
 * Fair credits (owner, 2026-10-20): no charge for broken work (part 1), AI at cost (part 2), the combined sheet (part 3). Cases from the Beats audit and the prod check of
 * the cost audit: Sonnet plans priced at Opus rates, a gym scene "cut out" as the headphones, failed builds that kept
 * their credits.
 */
class FairCreditsTest extends TestCase
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

    public function test_the_builder_is_charged_at_cost_and_the_build_is_rounded_once(): void
    {
        config(['database.default' => 'fair_test2', 'database.connections.fair_test2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('fair_test2');
        Schema::create('composition_attempts', function (Blueprint $t) { $t->string('id')->primary(); $t->string('run_id'); $t->string('kind'); $t->string('attempt_key')->nullable();
            $t->string('result_hash')->nullable(); $t->bigInteger('cost_microusd')->nullable(); $t->integer('charged_credits')->default(0); });
        // The Beats build's nine builder calls ($0.8287): 73 credits in all at 11,500 µ$ a credit (per-call rounding made 77).
        $total = 0;
        foreach ([283561, 163503, 54049, 69849, 54963, 47953, 63277, 56501, 35028] as $k => $cost) {
            $charge = CostEstimate::buildCharge('run-b', 'a'.$k, $cost, 300);
            DB::table('composition_attempts')->insert(['id' => 'a'.$k, 'run_id' => 'run-b', 'kind' => 'agent', 'attempt_key' => 'agent-'.$k, 'result_hash' => 'h', 'cost_microusd' => $cost, 'charged_credits' => $charge]);
            $total += $charge;
        }
        $this->assertSame(73, $total);
        // A repair round is never counted: its cost is ours.
        DB::table('composition_attempts')->insert(['id' => 'r1', 'run_id' => 'run-b', 'kind' => 'agent', 'attempt_key' => 'repair-1', 'result_hash' => 'h', 'cost_microusd' => 500000, 'charged_credits' => 0]);
        $this->assertSame(1, CostEstimate::buildCharge('run-b', 'a9', 20000, 300), 'the next call pays only its own share; the repair is not counted');
    }

    public function test_the_combined_sheet_is_cut_along_its_gutters(): void
    {
        // A 3x3 sheet of 9:16 cells (90x160) with 8 px white gutters, as the model draws it.
        $dir = sys_get_temp_dir(); $sheet = $dir.'/grid-'.uniqid().'.png';
        $w = 3 * 90 + 2 * 8; $h = 3 * 160 + 2 * 8;
        // Darkish cells (the gutters are the only bright, even lines).
        $boxes = implode(',', array_map(fn ($k) => 'drawbox=x='.(($k % 3) * 98).':y='.(intdiv($k, 3) * 168).':w=90:h=160:color=0x'.sprintf('%02x%02x%02x', 40 + $k * 15, 120 - $k * 9, 60 + $k * 10).':t=fill', range(0, 8)));
        exec('ffmpeg -v error -y -f lavfi -i color=c=white:s='.$w.'x'.$h.' -vf '.escapeshellarg($boxes).' -frames:v 1 '.escapeshellarg($sheet));
        $this->assertSame([3, 3], \App\Services\Create\CombinedSheet::layout(6));
        $this->assertSame([2, 2], \App\Services\Create\CombinedSheet::layout(4));
        $cells = \App\Services\Create\CombinedSheet::cells($sheet, 3, 3);
        $this->assertNotNull($cells, 'the gutters are found');
        $this->assertCount(9, $cells);
        foreach ($cells as $k => $cell) {
            $this->assertEqualsWithDelta(($k % 3) * 98, $cell['x'], 4, "cell $k starts at its column");
            $this->assertEqualsWithDelta(intdiv($k, 3) * 168, $cell['y'], 4, "cell $k starts at its row");
        }
        $out = $dir.'/cell-'.uniqid().'.png';
        $this->assertTrue(\App\Services\Create\CombinedSheet::cut($sheet, $cells[4], '9:16', $out));
        [$cw, $ch] = getimagesize($out);
        $this->assertEqualsWithDelta(9 / 16, $cw / $ch, 0.02, 'a cell keeps the video\'s shape');
        // A picture with no gutters is not a grid.
        $plain = $dir.'/plain-'.uniqid().'.png';
        exec('ffmpeg -v error -y -f lavfi -i testsrc2=s=288x512 -frames:v 1 '.escapeshellarg($plain));
        $this->assertNull(\App\Services\Create\CombinedSheet::cells($plain, 3, 3));
        $this->assertCount(9, \App\Services\Create\CombinedSheet::evenCells($plain, 3, 3), 'it is then cut on the even grid');
        @unlink($sheet); @unlink($out); @unlink($plain);
    }

    public function test_the_combined_sheet_prompt_fixes_each_identity_and_names_who_is_in_each_frame(): void
    {
        config(['services.replicate.api_token' => 'test', 'database.default' => 'fair_test3', 'database.connections.fair_test3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('fair_test3');
        Http::fake(['api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/x']])]);
        $dir = sys_get_temp_dir().'/sheet-prompt-'.uniqid(); mkdir($dir);
        exec('ffmpeg -v error -y -f lavfi -i color=c=0x886644:s=64x64 -frames:v 1 '.escapeshellarg($dir.'/layla.jpg'));
        exec('ffmpeg -v error -y -f lavfi -i color=c=0x557755:s=64x64 -frames:v 1 '.escapeshellarg($dir.'/bottle.jpg'));
        $sent = null;
        $adapter = \Mockery::mock(\App\Services\Generation\Image\NanoBananaProImageAdapter::class);
        $adapter->shouldReceive('generate')->andReturnUsing(function ($prompt, $style, $aspect, $options) use (&$sent) { $sent = [$prompt, $options]; throw new \RuntimeException('stop here'); });
        $this->app->instance(\App\Services\Generation\Image\NanoBananaProImageAdapter::class, $adapter);
        $ctx = ['workspace_id' => 1, 'aspect_ratio' => '9:16', 'character_style' => 'photoreal', 'character_style_images' => [], 'source_images' => [$dir.'/layla.jpg', $dir.'/bottle.jpg'],
            'cutout_files' => [['name' => 'asset-1.jpg', 'title' => 'Stock actor — Layla', 'path' => $dir.'/layla.jpg'], ['name' => 'asset-2.jpg', 'title' => 'bottle.jpg', 'path' => $dir.'/bottle.jpg']],
            'shot' => ['subjects' => [['name' => 'Layla', 'kind' => 'character', 'looks' => 'late-twenties woman, long dark wavy hair', 'avatar' => true],
                ['name' => 'Fieldwell bottle', 'kind' => 'product', 'looks' => 'sage-green steel bottle', 'photo' => 'bottle.jpg']],
                'board_panels' => [['label' => 'Panel 1', 'description' => 'Layla sips on a gym bench', 'refs' => ['Layla', 'Fieldwell bottle']], ['label' => 'Panel 2', 'description' => 'The bottle by a stream', 'refs' => ['Fieldwell bottle']]]]];
        try { app(\App\Services\Create\PlanMediaExecutor::class)->produce('reference_sheet', 'real-life sport look', $ctx, $dir); } catch (\Throwable $e) { if (! $sent) fwrite(STDERR, get_class($e).': '.$e->getMessage()); }
        $this->assertNotNull($sent, 'the sheet was asked for');
        [$prompt, $options] = $sent;
        $this->assertSame('4K', $options['resolution']);
        $this->assertCount(2, $options['reference_image_urls'], 'her photo and the bottle\'s photo');
        $this->assertStringContainsString('Layla (cell 1, drawn from image 1) is one specific person', $prompt);
        $this->assertStringContainsString('Fieldwell bottle (cell 2, drawn from image 2) is one specific product', $prompt);
        $this->assertStringContainsString('In this cell: Layla exactly as in cell 1, Fieldwell bottle exactly as in cell 2.', $prompt);
        $this->assertStringContainsString('In this cell: Fieldwell bottle exactly as in cell 2.', $prompt);
        array_map('unlink', glob($dir.'/*')); @rmdir($dir);
    }
}
