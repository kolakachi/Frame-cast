<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Services\Analytics\Acquisition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** Where an account came from (owner, 2026-10-11, priorities #6): one channel from its first visit. */
class AcquisitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'acq_test', 'database.connections.acq_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('acq_test');
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'status', 'plan_tier', 'affiliate_code', 'referred_by_workspace_id', 'acquisition_channel', 'acquisition_json'] as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        Schema::create('appsumo_licenses', function (Blueprint $t) { $t->id(); $t->text('workspace_id')->nullable(); $t->timestamps(); });
    }

    private function ws(array $attrs = []): Workspace
    {
        return Workspace::query()->create(['name' => 'W', 'status' => 'active'] + $attrs)->refresh();
    }

    public function test_each_first_visit_reads_as_one_channel(): void
    {
        $cases = [
            'google_ads' => [['gclid' => 'Cj0KCQjw_abcdefghij'], ['gbraid' => '0AAAAA_abcdefghij'], ['utm_source' => 'google', 'utm_medium' => 'cpc']],
            'paid_social' => [['utm_source' => 'facebook', 'utm_medium' => 'paid_social'], ['utm_source' => 'tiktok', 'utm_medium' => 'cpc']],
            'email' => [['utm_source' => 'beehiiv', 'utm_medium' => 'email']],
            'ai' => [['ref_host' => 'chatgpt.com'], ['ref_host' => 'gemini.google.com'], ['utm_source' => 'chatgpt.com'], ['ref_host' => 'perplexity.ai']],
            'search' => [['ref_host' => 'google.com'], ['ref_host' => 'google.co.uk'], ['ref_host' => 'duckduckgo.com'], ['ref_host' => 'bing.com']],
            'social' => [['ref_host' => 'l.facebook.com'], ['ref_host' => 't.co'], ['utm_source' => 'linkedin'], ['ref_host' => 'youtube.com'], ['utm_source' => 'tiktok', 'utm_medium' => 'social'], ['ref_host' => 'reddit.com']],
            // The two-week plan's tests (cmo-plan.md): one-to-one messages, community answers, directory listings.
            'outreach' => [['utm_source' => 'outreach', 'utm_medium' => 'outreach', 'utm_content' => 'batch1']],
            'community' => [['utm_source' => 'reddit', 'utm_medium' => 'community'], ['utm_source' => 'discord', 'utm_medium' => 'community']],
            'listing' => [['ref_host' => 'producthunt.com'], ['ref_host' => 'g2.com'], ['utm_source' => 'saashub', 'utm_medium' => 'listing'], ['ref_host' => 'alternativeto.net']],
            'other_site' => [['ref_host' => 'somebodysblog.com'], ['utm_source' => 'launchpad']],
            'direct' => [['landing' => '/', 'at' => '2026-10-12T09:00:00Z']],
            'unknown' => [[]],
        ];
        foreach ($cases as $channel => $touches) {
            foreach ($touches as $t) $this->assertSame($channel, Acquisition::channel(Acquisition::clean($t)), json_encode($t));
        }
    }

    public function test_what_the_account_carries_wins_in_order(): void
    {
        $ad = ['gclid' => 'Cj0KCQjw_abcdefghij'];
        $sumo = $this->ws();
        DB::table('appsumo_licenses')->insert(['workspace_id' => $sumo->id]);
        $this->assertSame('appsumo', Acquisition::channel($ad, $sumo));
        $this->assertSame('affiliate', Acquisition::channel($ad, $this->ws(['affiliate_code' => 'desmond'])));
        $friend = $this->ws(['referred_by_workspace_id' => 1]);
        $this->assertSame('google_ads', Acquisition::channel($ad, $friend), 'an ad click beats a friend link: the campaign is what is judged');
        $this->assertSame('referral', Acquisition::channel(['ref_host' => 'google.com'], $friend));
    }

    public function test_it_is_kept_once_and_cleaned(): void
    {
        $w = $this->ws();
        $touch = Acquisition::clean(['gclid' => 'Cj0KCQjw_abcdefghij', 'utm_campaign' => '<b>spring</b>', 'ref_host' => 'app.wyvstudio.com', 'landing' => 'https://evil', 'evil' => 'x']);
        $this->assertSame(['gclid' => 'Cj0KCQjw_abcdefghij', 'utm_campaign' => 'spring'], $touch, 'our own hosts, a non-path landing and unknown keys are dropped');
        Acquisition::record($w, $touch);
        Acquisition::record($w->refresh(), ['ref_host' => 'chatgpt.com']);
        $this->assertSame(['google_ads', 'spring'], [$w->refresh()->acquisition_channel, $w->acquisition_json['utm_campaign']], 'a later visit never moves it');
        $this->assertSame([], Acquisition::clean(['gclid' => 'short', 'ref_host' => 'bad host!']));
    }

    public function test_the_shared_cookie_and_googles_click_cookie_are_read_when_nothing_was_sent(): void
    {
        $r = Request::create('/x', 'POST', [], ['wyv_src' => rawurlencode(json_encode(['utm_source' => 'newsletter', 'utm_medium' => 'email'])), '_gcl_aw' => 'GCL.1760000000.Cj0KCQjw_fromcookie1']);
        $this->assertSame(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'gclid' => 'Cj0KCQjw_fromcookie1'], Acquisition::fromRequest($r));
        $this->assertSame(['ref_host' => 'claude.ai', 'gclid' => 'Cj0KCQjw_fromcookie1'], Acquisition::fromRequest($r, ['ref_host' => 'claude.ai']), 'what the browser sent wins over the cookie');
    }

    public function test_the_scoreboard_counts_signups_by_source_first_day_own_videos_and_second_purchases(): void
    {
        Schema::table('appsumo_licenses', function (Blueprint $t) { $t->text('status')->nullable(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->text('role')->nullable(); $t->integer('workspace_id')->nullable(); });
        Schema::create('revenue_events', function (Blueprint $t) { $t->id(); $t->integer('workspace_id')->nullable(); $t->text('provider'); $t->decimal('refunded_usd')->default(0); $t->timestamp('occurred_at')->nullable(); $t->timestamps(); });
        Schema::create('projects', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->text('title')->nullable(); });
        Schema::create('export_jobs', function (Blueprint $t) { $t->id(); $t->integer('project_id'); $t->text('status'); $t->timestamp('completed_at')->nullable(); });
        Schema::create('composition_runs', function (Blueprint $t) { $t->id(); $t->integer('workspace_id'); $t->text('status'); $t->timestamp('updated_at')->nullable(); });
        DB::table('users')->insert(['role' => 'super_admin', 'workspace_id' => 1]);
        DB::table('workspaces')->insert([
            ['id' => 1, 'name' => 'Owner', 'acquisition_channel' => null, 'acquisition_json' => null, 'created_at' => '2026-10-20 09:00:00'],
            ['id' => 2, 'name' => 'Made one', 'acquisition_channel' => 'community', 'acquisition_json' => '{"utm_source":"reddit","utm_medium":"community"}', 'created_at' => '2026-10-20 09:00:00'],
            ['id' => 3, 'name' => 'Only the sample', 'acquisition_channel' => 'social', 'acquisition_json' => '{"utm_source":"tiktok"}', 'created_at' => '2026-10-21 09:00:00'],
        ]);
        DB::table('projects')->insert([['id' => 10, 'workspace_id' => 2, 'title' => 'My shop ad'], ['id' => 11, 'workspace_id' => 3, 'title' => 'Wyvstudio Ad']]);
        DB::table('export_jobs')->insert([['project_id' => 10, 'status' => 'completed', 'completed_at' => '2026-10-20 10:00:00'], ['project_id' => 11, 'status' => 'completed', 'completed_at' => '2026-10-21 09:30:00']]);
        DB::table('revenue_events')->insert([
            ['workspace_id' => 2, 'provider' => 'kelviq', 'occurred_at' => '2026-10-20 11:00:00'],
            ['workspace_id' => 2, 'provider' => 'kelviq', 'occurred_at' => '2026-10-22 11:00:00'],
            ['workspace_id' => 1, 'provider' => 'kelviq', 'occurred_at' => '2026-10-22 11:00:00'],
        ]);
        $b = \App\Services\Analytics\Scoreboard::weeks('2026-10-19');
        $w = $b['weeks'][0];
        $this->assertSame(['2026-10-19', 2, 1, 2, 1], [$w['week'], $w['signups'], $w['own_video_first_day'], $w['purchases'], $w['bought_again']], 'staff left out; the sample is not an own video');
        $this->assertSame(['community' => 1, 'social' => 1], $w['by_channel']);
        $this->assertSame(['reddit' => 1, 'tiktok' => 1], $w['by_source']);
        $this->assertSame(1, $b['bought_again_to_date']);
        $this->artisan('growth:scoreboard', ['--since' => '2026-10-19'])->expectsOutputToContain('Teams that bought again, to date (the goal): 1')->assertSuccessful();
    }
}
