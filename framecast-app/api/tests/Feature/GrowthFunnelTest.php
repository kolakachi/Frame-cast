<?php

namespace Tests\Feature;

use App\Services\Analytics\Funnel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** FUNNEL: each new workspace's first session, step by step, and where people stop (CMO plan, owner 2026-10-19). */
class GrowthFunnelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'funnel_test', 'database.connections.funnel_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('funnel_test');
        Schema::create('workspaces', function (Blueprint $t) { $t->id(); $t->text('acquisition_channel')->nullable(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->text('role')->nullable(); $t->integer('workspace_id')->nullable(); });
        Schema::create('projects', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->text('title')->nullable(); $t->text('visual_brief')->nullable(); $t->timestamps(); });
        Schema::create('export_jobs', function (Blueprint $t) { $t->id(); $t->integer('project_id'); $t->text('status'); $t->timestamp('completed_at')->nullable(); });
        Schema::create('composition_runs', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->text('status'); $t->timestamps(); });
        Schema::create('create_conversations', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->timestamps(); });
        Schema::create('composition_revisions', function (Blueprint $t) { $t->id(); $t->integer('conversation_id'); $t->boolean('share_enabled')->default(false); $t->timestamps(); });
        Schema::create('ugc_chats', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->timestamps(); });
        Schema::create('credit_ledger', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->text('operation'); $t->integer('credits'); $t->timestamps(); });
        foreach (['video_downloads', 'share_links', 'scheduled_posts'] as $table) Schema::create($table, function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->timestamps(); });
        DB::table('users')->insert(['role' => 'super_admin', 'workspace_id' => 1]);
    }

    public function test_each_workspace_counts_the_steps_it_reached_in_its_first_day_and_the_biggest_drop_is_named(): void
    {
        $t = '2026-10-19 10:00:00';
        DB::table('workspaces')->insert([
            ['id' => 1, 'acquisition_channel' => 'direct', 'created_at' => $t],      // staff: left out
            ['id' => 2, 'acquisition_channel' => 'google', 'created_at' => $t],      // signed up only
            ['id' => 3, 'acquisition_channel' => 'google', 'created_at' => $t],      // started a Create conversation, no build
            ['id' => 4, 'acquisition_channel' => 'reddit', 'created_at' => $t],      // free first ad built and downloaded
            ['id' => 5, 'acquisition_channel' => 'reddit', 'created_at' => $t],      // UGC take started, delivered two days later
        ]);
        DB::table('create_conversations')->insert([['id' => 30, 'workspace_id' => 3, 'created_at' => '2026-10-19 10:05:00'], ['id' => 40, 'workspace_id' => 4, 'created_at' => '2026-10-19 10:05:00']]);
        DB::table('composition_runs')->insert(['workspace_id' => 4, 'status' => 'preview_ready', 'created_at' => '2026-10-19 10:10:00', 'updated_at' => '2026-10-19 10:30:00']);
        DB::table('video_downloads')->insert(['workspace_id' => 4, 'created_at' => '2026-10-19 10:40:00']);
        // The sample video every workspace gets is not the customer's own start.
        DB::table('projects')->insert([['id' => 1, 'workspace_id' => 2, 'title' => 'Wyvstudio Ad', 'visual_brief' => null, 'created_at' => $t],
            ['id' => 5, 'workspace_id' => 5, 'title' => 'Serum ad', 'visual_brief' => '{"ugc_format":"one_shot"}', 'created_at' => '2026-10-19 11:00:00']]);
        DB::table('export_jobs')->insert(['project_id' => 5, 'status' => 'completed', 'completed_at' => '2026-10-21 09:00:00']);

        $this->assertSame(['signed_up', 'started', 'approved', 'delivered', 'downloaded_or_shared'], Funnel::reached(4, $t));
        $this->assertSame(['signed_up', 'started', 'approved'], Funnel::reached(5, $t), 'delivered after the first day');
        $this->assertSame(['signed_up', 'started', 'approved', 'delivered'], Funnel::reached(5, $t, 72));

        $f = Funnel::weeks('2026-10-19');
        $this->assertSame(['signed_up' => 4, 'started' => 3, 'approved' => 2, 'delivered' => 1, 'downloaded_or_shared' => 1], $f['total']);
        $this->assertSame('2026-10-19', $f['weeks'][0]['week']);
        $this->assertSame(['google', 'reddit'], array_keys($f['by_channel']));
        $this->assertSame(2, $f['by_channel']['reddit']['approved']);
        $this->assertSame(['from' => 'signed_up', 'to' => 'started', 'lost' => 1], $f['biggest_drop'], 'ties go to the earliest step');
        $this->artisan('growth:funnel', ['--since' => '2026-10-19'])->expectsOutputToContain('Biggest drop')->assertSuccessful();
    }
}
