<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Onboarding\OnboardingController;
use App\Models\{User, Workspace};
use App\Services\Create\References\PageReferenceService;
use App\Services\Onboarding\BrandFromSite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Process, Schema, Storage};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/** The Weave onboarding: a website becomes the brand kit, the answers are kept, and the first brief is ready. */
class OnboardingTest extends TestCase
{
    use BuildsDeveloperSchema;
    private User $owner;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'onb_test', 'database.connections.onb_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'cache.default' => 'array', 'services.posthog.key' => '', 'create.mode' => 'agent', 'services.anthropic.key' => 'k']);
        DB::purge('onb_test');
        $this->buildDeveloperSchema();
        if (! Schema::hasColumn('users', 'preferences_json')) Schema::table('users', fn (Blueprint $t) => $t->json('preferences_json')->nullable());
        if (! Schema::hasTable('brand_kits')) Schema::create('brand_kits', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('name')->nullable();
            foreach (['primary_color', 'secondary_color', 'accent_color', 'font_primary', 'font_secondary', 'default_caption_style'] as $c) $t->string($c)->nullable();
            $t->unsignedBigInteger('logo_asset_id')->nullable(); $t->unsignedBigInteger('default_voice_profile_id')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_09_180000_add_onboarding_to_workspaces.php'))->up();
        Storage::fake('minio');
        $this->workspace = Workspace::create(['name' => 'New', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active']);
        $this->owner = User::create(['email' => 'new@example.test', 'name' => 'New', 'role' => 'owner', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $this->workspace->id])->save();
        PageReferenceService::$resolve = fn () => ['93.184.216.34'];
    }

    protected function tearDown(): void { PageReferenceService::$resolve = null; parent::tearDown(); }

    private function fakeSite(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAKElEQVR4nO3BAQ0AAADCoPdPbQ8HFAAAAAAAAAAAAAAAAAAAAAAA8GaAQAAB8qNG2AAAAABJRU5ErkJggg==').str_repeat("\0", 600); // a real header, padded past the size floor
        $this->app->instance(\App\Services\Generation\UrlContentExtractor::class, new class extends \App\Services\Generation\UrlContentExtractor {
            public function extract(string $url): string { return "Dewbloom\nGlow Serum with 15% vitamin C. Visible glow in 7 days. Free shipping over $40."; }
        });
        Process::fake(fn () => Process::result('', 'no browser', 1));
        Http::fake([
            'https://dewbloom.com/apple-touch-icon.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://dewbloom.com/*' => Http::response('<html><head><title>Dewbloom | Skincare</title><link rel="apple-touch-icon" href="/apple-touch-icon.png"></head><body>Dewbloom</body></html>'),
            'https://api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['name' => 'Dewbloom', 'palette' => ['#2F3A2C', '#F6EFE4', '#D9A35F', 'nope'],
                'products' => ['Glow Serum', 'Night Balm'], 'summary' => 'Vitamin C skincare for dull skin.', 'industry' => 'beauty',
                'facts' => [['text' => 'Visible glow in 7 days', 'quote' => 'Visible glow in 7 days'], ['text' => 'Loved by 1M customers', 'quote' => 'Loved by a million']]])]]]),
        ]);
    }

    public function test_a_website_becomes_the_brand_kit_with_logo_colours_products_and_quoted_facts(): void
    {
        $this->fakeSite();
        $r = app(BrandFromSite::class)->read($this->owner, 'dewbloom.com');
        $this->assertSame(['https://dewbloom.com/', 'Dewbloom', ['#2F3A2C', '#F6EFE4', '#D9A35F'], ['Glow Serum', 'Night Balm'], 'beauty'], [$r['url'], $r['name'], $r['palette'], $r['products'], $r['industry']]);
        $kit = DB::table('brand_kits')->where('workspace_id', $this->workspace->id)->first();
        $this->assertSame(['Dewbloom', '#2F3A2C', '#F6EFE4', '#D9A35F'], [$kit->name, $kit->primary_color, $kit->secondary_color, $kit->accent_color]);
        $logo = DB::table('assets')->where('id', $kit->logo_asset_id)->first();
        $this->assertSame('logo', json_decode($logo->metadata_json, true)['brand_role']);
        $this->assertSame(['beauty', 'site'], [DB::table('workspaces')->where('id', $this->workspace->id)->value('industry'), DB::table('workspaces')->where('id', $this->workspace->id)->value('industry_source')]);
        $saved = json_decode(DB::table('workspaces')->where('id', $this->workspace->id)->value('onboarding_json'), true);
        $this->assertSame(['Visible glow in 7 days'], $saved['site']['facts'], 'a fact the page does not state is dropped');
        // Another brand's kit is never overwritten: a second brand gets its own kit.
        DB::table('brand_kits')->insert(['workspace_id' => $this->workspace->id, 'name' => 'Northside Roasters', 'primary_color' => '#111111', 'created_at' => now(), 'updated_at' => now()]);
        app(BrandFromSite::class)->named($this->owner, 'Ember & Oak');
        $this->assertSame(['Dewbloom', 'Northside Roasters', 'Ember & Oak'], DB::table('brand_kits')->where('workspace_id', $this->workspace->id)->orderBy('id')->pluck('name')->all());
        app(BrandFromSite::class)->named($this->owner, 'dewbloom');
        $this->assertSame(3, DB::table('brand_kits')->where('workspace_id', $this->workspace->id)->count(), 'the same brand updates its own kit');
        // A social page is not the brand's own site.
        try { app(BrandFromSite::class)->read($this->owner, 'https://instagram.com/dewbloom'); $this->fail('a social page was read as the brand site'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    public function test_finishing_keeps_the_answers_marks_onboarded_and_returns_the_first_brief(): void
    {
        $this->fakeSite();
        app(BrandFromSite::class)->read($this->owner, 'dewbloom.com');
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $d = $this->actingAs($this->owner)->postJson('/api/v1/onboarding', ['goal' => 'ads', 'heard' => 'appsumo'])->assertOk()->json('data');
        $this->assertSame('A 15-second vertical product ad for Glow Serum by Dewbloom, using the facts on https://dewbloom.com/.', $d['brief']);
        $this->assertSame(['ads', null], [$d['sample_filter'], $d['style']]);
        $saved = json_decode(DB::table('workspaces')->where('id', $this->workspace->id)->value('onboarding_json'), true);
        $this->assertSame(['ads', 'appsumo'], [$saved['goal'], $saved['heard']]);
        $this->assertArrayHasKey('done_at', $saved);
        $this->assertTrue(json_decode(DB::table('users')->where('id', $this->owner->id)->value('preferences_json'), true)['onboarded']);
    }

    public function test_skipping_marks_onboarded_with_no_brief_and_briefs_fit_every_goal(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $d = $this->actingAs($this->owner)->postJson('/api/v1/onboarding', ['skipped' => true])->assertOk()->json('data');
        $this->assertSame([null, null, 'all'], [$d['brief'], $d['style'], $d['sample_filter']]);
        $this->assertArrayHasKey('skipped_at', json_decode(DB::table('workspaces')->where('id', $this->workspace->id)->value('onboarding_json'), true));
        // Without a site, the brief leaves the product in brackets for the user.
        $this->assertSame('A 15-second vertical product ad for [your product].', OnboardingController::brief('ads', []));
        $this->assertSame('A 15-second vertical product ad for my client [client name and website].', OnboardingController::brief('agency', []));
        $this->assertSame('A 15-second vertical launch teaser for Acme.', OnboardingController::brief('launch', ['name' => 'Acme']));
        $this->assertSame(['launch-reel', 'collage-zine', null], [OnboardingController::style('launch', null), OnboardingController::style('ads', 'fashion'), OnboardingController::style('explore', 'fashion')]);
        // Clients never answer for the agency's workspace.
        $client = User::create(['email' => 'c@example.test', 'name' => 'C', 'role' => 'client', 'status' => 'active']);
        $client->forceFill(['workspace_id' => $this->workspace->id])->save();
        $this->actingAs($client)->postJson('/api/v1/onboarding', ['goal' => 'ads'])->assertForbidden();
    }
}
