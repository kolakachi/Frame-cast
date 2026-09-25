<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWithJwt;
use App\Jobs\GenerateScriptJob;
use App\Models\ApiKey;
use App\Models\ApiQuote;
use App\Models\AuthSession;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\JwtService;
use App\Services\WorkspaceUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis, Schema};
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/**
 * The developer API, end to end over HTTP: an API key reaches exactly this
 * namespace and nothing else; a video is quoted, created against the quote,
 * polled and fetched; and every way a quote can be misused is refused
 * without spending anything.
 *
 * Deliberately uses a fresh in-memory DB, never the configured application database.
 */
class DeveloperApiTest extends TestCase
{
    use BuildsDeveloperSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'dev_api_test', 'database.connections.dev_api_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('dev_api_test');
        config(['cache.default' => 'array', 'app.frontend_url' => 'https://app.test', 'services.posthog.key' => '']);
        Bus::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);

        $this->buildDeveloperSchema();

        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('hasExceededApiBudget')->willReturn(false);
        $usage->method('exportsRemaining')->willReturn(10);
        $this->instance(WorkspaceUsageService::class, $usage);
    }

    /** @return array{0: Workspace, 1: User, 2: string} workspace, owner, plaintext key */
    private function tenant(string $tier = 'creator', int $credits = 500): array
    {
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => $tier, 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => $credits]);
        $u = User::query()->create(['email' => uniqid().'@acme.test', 'name' => 'Owner', 'role' => 'owner', 'status' => 'active']);
        $u->forceFill(['workspace_id' => $ws->getKey()])->save();
        DB::table('workspace_memberships')->insert(['workspace_id' => $ws->id, 'user_id' => $u->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        [, $plain] = ApiKey::issue((int) $ws->getKey(), (int) $u->getKey(), 'Test');

        return [$ws->fresh(), $u->fresh(), $plain];
    }

    private function sessionToken(User $u, Workspace $ws): string
    {
        $session = AuthSession::query()->create(['user_id' => $u->getKey(), 'workspace_id' => $ws->getKey()]);

        return app(JwtService::class)->issue($u, $ws, $session);
    }

    private function quote(string $key, array $overrides = []): TestResponse
    {
        return $this->withToken($key)->postJson('/api/developer/v1/quotes', $overrides + [
            'source_type' => 'prompt',
            'content' => 'Three reasons a standing desk pays for itself within a month, told as a story.',
            'visual_mode' => 'stock',
            'duration_seconds' => 30,
        ]);
    }

    private function create(string $key, string $quoteId, string $idem = 'req-1'): TestResponse
    {
        return $this->withToken($key)->postJson('/api/developer/v1/videos', ['quote_id' => $quoteId, 'idempotency_key' => $idem]);
    }

    /** A user whose home is elsewhere, holding a membership on $ws. Returns the user and a key they issued for $ws. */
    private function member(Workspace $ws, string $role): array
    {
        $home = Workspace::query()->create(['name' => 'Home', 'plan_tier' => 'free', 'status' => 'active']);
        $u = User::query()->create(['email' => uniqid().'@member.test', 'name' => 'Member', 'role' => 'owner', 'status' => 'active']);
        $u->forceFill(['workspace_id' => $home->getKey()])->save();
        DB::table('workspace_memberships')->insert(['workspace_id' => $ws->id, 'user_id' => $u->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        [, $plain] = ApiKey::issue((int) $ws->getKey(), (int) $u->getKey(), 'Member key');

        return [$u->fresh(), $plain];
    }

    public function test_a_revoked_membership_ends_the_key(): void
    {
        [$ws] = $this->tenant();
        [$u, $key] = $this->member($ws, 'admin');
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertOk()->assertJsonPath('data.plan', 'creator');

        DB::table('workspace_memberships')->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertStatus(401);
    }

    public function test_an_inactive_issuer_ends_the_key(): void
    {
        [, $u, $key] = $this->tenant();
        $u->forceFill(['status' => 'suspended'])->save();
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertStatus(401);
    }

    public function test_a_suspended_workspace_or_agency_blocks_the_key(): void
    {
        [$ws, , $key] = $this->tenant();
        $ws->forceFill(['status' => 'suspended'])->save();
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertStatus(403)->assertJsonPath('error.code', 'workspace_suspended');

        // A client workspace of a suspended agency is suspended with it.
        [$agency, $owner] = $this->tenant('agency');
        $client = Workspace::query()->create(['name' => 'Client', 'plan_tier' => 'agency', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $client->forceFill(['parent_workspace_id' => $agency->getKey()])->save();
        [, $clientKey] = ApiKey::issue((int) $client->getKey(), (int) $owner->getKey(), 'Client key');
        $this->withToken($clientKey)->getJson('/api/developer/v1/capabilities')->assertOk();
        $agency->forceFill(['status' => 'suspended'])->save();
        $this->withToken($clientKey)->getJson('/api/developer/v1/capabilities')->assertStatus(403)->assertJsonPath('error.code', 'workspace_suspended');
    }

    public function test_a_client_viewer_seat_can_read_but_not_spend(): void
    {
        [$ws] = $this->tenant();
        [, $key] = $this->member($ws, User::ROLE_CLIENT_VIEWER);
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertOk();
        $this->quote($key)->assertStatus(403)->assertJsonPath('error.code', 'client_seat_read_only');
        $this->assertSame(0, ApiQuote::query()->count());
    }

    public function test_key_management_is_owner_or_admin_only(): void
    {
        [$ws, $owner] = $this->tenant();
        [$editor] = $this->member($ws, 'editor');

        $asEditor = fn () => $this->withToken($this->sessionToken($editor, $ws));
        $asEditor()->getJson('/api/v1/api-keys')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $asEditor()->postJson('/api/v1/api-keys', ['name' => 'Sneaky'])->assertStatus(403);
        $existing = ApiKey::query()->where('workspace_id', $ws->id)->value('id');
        $asEditor()->deleteJson('/api/v1/api-keys/'.$existing)->assertStatus(403);
        $this->assertNull(ApiKey::query()->find($existing)->revoked_at);

        $asOwner = fn () => $this->withToken($this->sessionToken($owner, $ws));
        $asOwner()->getJson('/api/v1/api-keys')->assertOk();

        // A platform admin manages the keys of the workspace they are in.
        $owner->forceFill(['role' => 'super_admin'])->save();
        $asOwner()->getJson('/api/v1/api-keys')->assertOk();
        $owner->forceFill(['role' => 'owner'])->save();
        $created = $asOwner()->postJson('/api/v1/api-keys', ['name' => 'CI'])->assertStatus(201);
        $this->assertStringStartsWith('wyv_live_', $created->json('data.key'));
        $asOwner()->deleteJson('/api/v1/api-keys/'.$created->json('data.id'))->assertOk();
    }

    public function test_five_active_keys_is_the_limit(): void
    {
        [$ws, $owner] = $this->tenant(); // one key already issued
        $asOwner = fn () => $this->withToken($this->sessionToken($owner, $ws));
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $asOwner()->postJson('/api/v1/api-keys', ['name' => "Key $i"])->assertStatus(201)->json('data.id');
        }
        $asOwner()->postJson('/api/v1/api-keys', ['name' => 'Sixth'])->assertStatus(422)->assertJsonPath('error.code', 'too_many_keys');
        $asOwner()->deleteJson('/api/v1/api-keys/'.$ids[0])->assertOk();
        $asOwner()->postJson('/api/v1/api-keys', ['name' => 'Sixth'])->assertStatus(201);
    }

    public function test_reads_and_writes_are_limited_separately_per_key(): void
    {
        config(['developer.limits.reads_per_minute' => 3, 'developer.limits.writes_per_minute' => 2]);
        [, , $key] = $this->tenant();

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertOk()
                ->assertHeader('X-RateLimit-Limit', '3')->assertHeader('X-RateLimit-Remaining', (string) (2 - $i));
        }
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')->assertJsonPath('error.context.bucket', 'reads')
            ->assertHeader('Retry-After');

        // A polling limit never blocks a write, and vice versa.
        $this->quote($key)->assertStatus(201);
        $this->quote($key)->assertStatus(201);
        $this->quote($key)->assertStatus(429)->assertJsonPath('error.context.bucket', 'writes');
    }

    public function test_the_workspace_limit_is_shared_across_keys(): void
    {
        config(['developer.limits.reads_per_minute' => 10, 'developer.limits.workspace_reads_per_minute' => 3]);
        [$ws, $owner, $keyA] = $this->tenant();
        [, $keyB] = ApiKey::issue((int) $ws->getKey(), (int) $owner->getKey(), 'Second');

        $this->withToken($keyA)->getJson('/api/developer/v1/capabilities')->assertOk();
        $this->withToken($keyA)->getJson('/api/developer/v1/capabilities')->assertOk();
        $this->withToken($keyB)->getJson('/api/developer/v1/capabilities')->assertOk();
        $this->withToken($keyB)->getJson('/api/developer/v1/capabilities')->assertStatus(429);

        // Another workspace is unaffected.
        [, , $other] = $this->tenant();
        $this->withToken($other)->getJson('/api/developer/v1/capabilities')->assertOk();
    }

    public function test_in_flight_videos_are_capped_per_workspace(): void
    {
        config(['developer.limits.max_active_videos' => 2]);
        [, , $key] = $this->tenant();

        $first = $this->create($key, $this->quote($key)->json('data.quote_id'), 'a')->assertStatus(202)->json('data.video.id');
        $this->create($key, $this->quote($key)->json('data.quote_id'), 'b')->assertStatus(202);
        $quoteId = $this->quote($key)->json('data.quote_id');
        $this->create($key, $quoteId, 'c')->assertStatus(429)->assertJsonPath('error.code', 'too_many_active_videos');
        $this->assertNull(ApiQuote::query()->findOrFail($quoteId)->consumed_at, 'a refused create gives the quote back');

        Project::withoutEvents(fn () => Project::query()->whereKey($first)->update(['status' => 'ready_for_review']));
        $this->create($key, $quoteId, 'c')->assertStatus(202);
    }

    public function test_an_expired_key_is_refused_with_its_own_code(): void
    {
        [$ws, $owner] = $this->tenant();
        [$key, $plain] = ApiKey::issue((int) $ws->getKey(), (int) $owner->getKey(), 'Pilot', now()->addDay());
        $this->withToken($plain)->getJson('/api/developer/v1/capabilities')->assertOk()
            ->assertJsonPath('data.key.name', 'Pilot');
        $key->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->withToken($plain)->getJson('/api/developer/v1/capabilities')->assertStatus(401)->assertJsonPath('error.code', 'api_key_expired');
    }

    public function test_rotation_replaces_the_secret_and_keeps_the_limits(): void
    {
        [$ws, $owner] = $this->tenant();
        $asOwner = fn () => $this->withToken($this->sessionToken($owner, $ws));
        $created = $asOwner()->postJson('/api/v1/api-keys', ['name' => 'CI', 'expires_in_days' => 30, 'spend_cap_credits' => 200])->assertStatus(201);
        $oldPlain = $created->json('data.key');
        $this->withToken($oldPlain)->getJson('/api/developer/v1/capabilities')->assertOk()->assertJsonPath('data.key.spend_cap_credits', 200);

        $rotated = $asOwner()->postJson('/api/v1/api-keys/'.$created->json('data.id').'/rotate')->assertStatus(201);
        $this->assertNotSame($oldPlain, $rotated->json('data.key'));
        $this->assertSame($created->json('data.id'), $rotated->json('data.rotated_from_id'));
        $this->assertSame(200, $rotated->json('data.spend_cap_credits'));
        $this->assertNotNull($rotated->json('data.expires_at'));

        $this->withToken($oldPlain)->getJson('/api/developer/v1/capabilities')->assertStatus(401);
        $this->withToken($rotated->json('data.key'))->getJson('/api/developer/v1/capabilities')->assertOk()->assertJsonPath('data.key.name', 'CI');
        $this->assertSame(2, ApiKey::query()->where('workspace_id', $ws->id)->whereNull('revoked_at')->count(), 'the tenant key plus the rotated one');
        $asOwner()->postJson('/api/v1/api-keys/'.$created->json('data.id').'/rotate')->assertStatus(404);
    }

    public function test_a_keys_monthly_spend_cap_blocks_a_create_that_would_exceed_it(): void
    {
        [$ws, $owner] = $this->tenant();
        [, $plain] = ApiKey::issue((int) $ws->getKey(), (int) $owner->getKey(), 'Capped', null, 60);

        $first = $this->create($plain, $this->quote($plain)->json('data.quote_id'), 'a')->assertStatus(202)->json('data.video.id');
        DB::table('credit_ledger')->insert(['workspace_id' => $ws->id, 'project_id' => $first, 'operation' => 'tts', 'credits' => 45, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($plain)->getJson('/api/developer/v1/capabilities')->assertOk()->assertJsonPath('data.key.spent_this_month', 45);

        $quote = $this->quote($plain);
        $this->assertGreaterThan(15, (int) $quote->json('data.credits.max'), 'a quote that fits under the cap alone but not after 45 spent');
        $this->assertLessThanOrEqual(60, (int) $quote->json('data.credits.max'));
        $this->create($plain, $quote->json('data.quote_id'), 'b')->assertStatus(402)
            ->assertJsonPath('error.code', 'key_spend_cap_reached')
            ->assertJsonPath('error.context.spent_this_month', 45);
        $this->assertNull(ApiQuote::query()->findOrFail($quote->json('data.quote_id'))->consumed_at);

        // Last month's spend does not count.
        DB::table('credit_ledger')->where('project_id', $first)->update(['created_at' => now()->subMonth()->startOfMonth()]);
        $this->create($plain, $quote->json('data.quote_id'), 'b')->assertStatus(202);
    }

    public function test_voices_are_the_catalogue_plus_the_workspaces_own(): void
    {
        [$ws, , $key] = $this->tenant();
        [$other] = $this->tenant();
        DB::table('voice_profiles')->insert([
            ['workspace_id' => null, 'provider' => 'google', 'name' => 'Kore', 'language' => 'en', 'gender_label' => 'female', 'provider_voice_key' => 'Kore', 'status' => 'active', 'is_cloned' => false, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => null, 'provider' => 'openai', 'name' => 'Alloy', 'language' => 'en', 'gender_label' => 'neutral', 'provider_voice_key' => 'alloy', 'status' => 'active', 'is_cloned' => false, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws->id, 'provider' => 'chatterbox', 'name' => 'My clone', 'language' => 'en', 'gender_label' => 'male', 'provider_voice_key' => 'clone-abc', 'status' => 'active', 'is_cloned' => true, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws->id, 'provider' => 'chatterbox', 'name' => 'Old clone', 'language' => 'en', 'gender_label' => 'male', 'provider_voice_key' => 'clone-old', 'status' => 'deleted', 'is_cloned' => true, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $other->id, 'provider' => 'chatterbox', 'name' => 'Their clone', 'language' => 'en', 'gender_label' => 'male', 'provider_voice_key' => 'clone-theirs', 'status' => 'active', 'is_cloned' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $list = $this->withToken($key)->getJson('/api/developer/v1/voices')->assertOk()->assertJsonPath('data.default_voice_id', 'Kore');
        $ids = array_column($list->json('data.voices'), 'id');
        $this->assertSame(['alloy', 'Kore', 'clone-abc'], $ids, 'catalogue (by name) first, then own voices; no deleted, no foreign');
        $byId = collect($list->json('data.voices'))->keyBy('id');
        $this->assertSame(3, $byId['Kore']['cost_per_scene']);
        $this->assertSame(1, $byId['alloy']['cost_per_scene']);
        $this->assertSame(2, $byId['clone-abc']['cost_per_scene']);
        $this->assertTrue($byId['clone-abc']['is_workspace_voice']);

        // A quote with the clone prices narration at the clone's rate and freezes it.
        $gemini = (int) $this->quote($key)->json('data.credits.max');
        $clone = $this->quote($key, ['voice_id' => 'clone-abc'])->assertStatus(201)->assertJsonPath('data.voice.name', 'My clone');
        $this->assertLessThan($gemini, (int) $clone->json('data.credits.max'));
        $this->assertSame(2, $clone->json('data.credits.breakdown.voice_per_scene'));
        $id = $this->create($key, $clone->json('data.quote_id'), 'v1')->assertStatus(202)->json('data.video.id');
        $this->assertSame(['voice_id' => 'clone-abc'], Project::query()->findOrFail($id)->default_voice_settings_json);

        $this->quote($key, ['voice_id' => 'clone-theirs'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_voice');
        $this->quote($key, ['voice_id' => 'clone-old'])->assertStatus(422);
        $this->quote($key, ['voice_id' => 'nope'])->assertStatus(422);
    }

    public function test_lookups_are_workspace_scoped_and_a_quote_carries_every_setting(): void
    {
        [$ws, , $key] = $this->tenant();
        [$other] = $this->tenant();
        $kit = \App\Models\BrandKit::query()->create(['workspace_id' => $ws->id, 'name' => 'Acme kit', 'primary_color' => '#ff6b35']);
        \App\Models\BrandKit::query()->create(['workspace_id' => $other->id, 'name' => 'Their kit']);
        $chan = \App\Models\Channel::query()->create(['workspace_id' => $ws->id, 'name' => 'Shorts', 'status' => 'active', 'default_language' => 'en']);
        $niche = \App\Models\Niche::query()->create(['name' => 'Fitness', 'slug' => 'fitness', 'default_voice_tone' => 'energetic']);
        $char = \App\Models\Character::query()->create(['workspace_id' => $ws->id, 'name' => 'Maya', 'status' => 'active']);
        \App\Models\Character::query()->create(['workspace_id' => $ws->id, 'name' => 'Gone', 'status' => 'archived']);
        $music = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'music', 'title' => 'Upbeat', 'created_at' => now(), 'updated_at' => now()]);
        $img1 = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'image', 'title' => 'Shot 1', 'created_at' => now(), 'updated_at' => now()]);
        $img2 = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'image', 'title' => 'Shot 2', 'created_at' => now(), 'updated_at' => now()]);
        $theirImg = DB::table('assets')->insertGetId(['workspace_id' => $other->id, 'asset_type' => 'image', 'title' => 'Theirs', 'created_at' => now(), 'updated_at' => now()]);

        $get = fn (string $path) => $this->withToken($key)->getJson('/api/developer/v1'.$path)->assertOk();
        $this->assertSame(['Acme kit'], array_column($get('/brand-kits')->json('data.brand_kits'), 'name'));
        $this->assertSame(['Shorts'], array_column($get('/channels')->json('data.channels'), 'name'));
        $this->assertSame(['Fitness'], array_column($get('/niches')->json('data.niches'), 'name'));
        $this->assertSame(['Maya'], array_column($get('/characters')->json('data.characters'), 'name'), 'archived characters are hidden');
        $this->assertSame(['Upbeat'], array_column($get('/library?type=music')->json('data.assets'), 'title'));
        $this->assertSame(['Shot 2', 'Shot 1'], array_column($get('/library?type=image')->json('data.assets'), 'title'));
        $opts = $get('/options');
        $this->assertContains('cinematic', array_column($opts->json('data.visual_styles'), 'key'));
        $this->assertContains('youtube_shorts', $opts->json('data.platform_targets'));
        $this->withToken($key)->getJson('/api/developer/v1/library?type=secrets')->assertStatus(422);

        $quote = $this->quote($key, [
            'source_type' => 'images', 'image_asset_ids' => [$img1, $img2], 'visual_mode' => 'ai_images', 'visual_style' => 'cinematic',
            'custom_visual_style' => 'warm light', 'brand_kit_id' => $kit->id, 'channel_id' => $chan->id, 'niche_id' => $niche->id,
            'character_id' => $char->id, 'music_asset_id' => $music, 'languages' => ['en', 'es'], 'platform_target' => 'youtube_shorts',
            'allow_script_edit' => true, 'title' => 'Launch',
        ])->assertStatus(201);
        $chosen = $quote->json('data.chosen');
        $this->assertSame('Acme kit', $chosen['brand_kit']['name']);
        $this->assertSame('Shorts', $chosen['channel']['name']);
        $this->assertSame('Fitness', $chosen['niche']['name']);
        $this->assertSame('Maya', $chosen['character']['name']);
        $this->assertSame('Upbeat', $chosen['music']['name']);
        $this->assertSame(2, $chosen['images']['count']);

        $id = $this->create($key, $quote->json('data.quote_id'), 'full')->assertStatus(202)->json('data.video.id');
        $p = Project::query()->findOrFail($id);
        $this->assertSame([$img1, $img2], $p->source_image_asset_ids);
        $this->assertSame('cinematic', $p->default_visual_style);
        $this->assertSame('warm light', $p->custom_visual_style);
        $this->assertSame((int) $kit->id, (int) $p->brand_kit_id);
        $this->assertSame((int) $chan->id, (int) $p->channel_id);
        $this->assertSame((int) $niche->id, (int) $p->niche_id);
        $this->assertSame((int) $char->id, (int) $p->default_character_id);
        $this->assertSame($music, (int) $p->music_asset_id);
        $this->assertSame('es', $p->primary_language === 'en' ? 'es' : 'es'); // languages[0] is primary
        $this->assertSame('en', $p->primary_language);
        $this->assertSame('youtube_shorts', $p->platform_target);
        $this->assertTrue((bool) $p->allow_script_edit);
        $this->assertSame('energetic', $p->tone, 'niche default tone applied, as the dashboard does');

        // Ownership and mode rules.
        $this->quote($key, ['brand_kit_id' => 999])->assertStatus(422)->assertJsonPath('error.code', 'invalid_brand_kit');
        $this->quote($key, ['source_type' => 'images', 'image_asset_ids' => [$img1, $theirImg], 'visual_mode' => 'ai_images'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_images');
        $this->quote($key, ['visual_style' => 'cinematic'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed'); // stock mode
        $this->quote($key, ['visual_mode' => 'waveform', 'audiogram' => ['style' => 'bars', 'color' => '#fff']])->assertStatus(201);
        $this->quote($key, ['audiogram' => ['style' => 'bars']])->assertStatus(422);
        $this->quote($key, ['languages' => ['xx']])->assertStatus(422);
        $this->quote($key, ['source_type' => 'url', 'content' => 'https://example.com/a-long-article'])->assertStatus(201);
    }

    /** @return list<array<string, mixed>> a two-beat plan the way the planner returns it */
    private function ugcSegments(): array
    {
        // Direct-to-camera is one continuous talking take.
        return [
            ['kind' => 'on_camera', 'script_text' => 'This desk changed how I work. Three reasons it pays for itself within a month.', 'seconds' => 10, 'visual_brief' => 'Presenter at a standing desk, bright kitchen', 'source' => 'generate'],
        ];
    }

    public function test_ugc_plan_quote_and_create_go_through_the_dashboard_path(): void
    {
        [$ws, $owner, $key] = $this->tenant();
        $ref = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'image', 'title' => 'Maya ref', 'storage_url' => 'https://b2/maya.png', 'mime_type' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        $maya = \App\Models\Character::query()->create(['workspace_id' => $ws->id, 'name' => 'Maya', 'status' => 'active', 'reference_asset_id' => $ref, 'is_stock' => false, 'is_auto' => false]);

        $planner = $this->createMock(\App\Services\Ugc\UgcShotPlanner::class);
        $planner->method('plan')->willReturn(['format' => 'direct_camera', 'segments' => $this->ugcSegments(), 'product' => 'Standing desk']);
        $this->instance(\App\Services\Ugc\UgcShotPlanner::class, $planner);

        $plan = $this->withToken($key)->postJson('/api/developer/v1/ugc/plans', ['script' => 'This desk changed how I work. Three reasons it pays for itself.', 'format' => 'auto', 'duration_seconds' => 10])
            ->assertOk()->json('data.plan');
        $this->assertCount(1, $plan['segments']);

        $this->withToken($key)->getJson('/api/developer/v1/ugc/allowance')->assertOk()->assertJsonPath('data.used', 0)->assertJsonPath('data.cap', 60);

        // No consent, no quote.
        $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', ['mode' => 'composed', 'format' => 'direct_camera', 'segments' => $plan['segments'], 'character_ids' => [$maya->id], 'consent' => false])
            ->assertStatus(422)->assertJsonPath('error.code', 'consent_required');

        $quote = $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', ['mode' => 'composed', 'format' => 'direct_camera', 'segments' => $plan['segments'], 'character_ids' => [$maya->id], 'consent' => true, 'aspect_ratio' => '9:16', 'title' => 'Desk ad'])
            ->assertStatus(201)->assertJsonPath('data.takes', 1)->assertJsonPath('data.can_afford', true);
        $expected = \App\Services\Ugc\UgcPlan::quote(\App\Services\Ugc\UgcPlan::normalise($plan['segments'], 'direct_camera'));
        $this->assertSame($expected, $quote->json('data.credits.max'));
        $this->assertSame($expected, $quote->json('data.credits.credits_per_character'));

        // A UGC quote cannot be spent on the standard create, nor the reverse.
        $this->withToken($key)->postJson('/api/developer/v1/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'quote_kind_mismatch');
        $std = $this->quote($key)->json('data.quote_id');
        $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', ['quote_id' => $std, 'idempotency_key' => 'y'])->assertStatus(409)->assertJsonPath('error.code', 'quote_kind_mismatch');

        $created = $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'ugc-1'])->assertStatus(202);
        $videos = $created->json('data.videos');
        $this->assertCount(1, $videos);
        $this->assertNotNull($created->json('data.run_id'));
        $p = Project::query()->findOrFail($videos[0]['id']);
        $this->assertNotNull($p->api_key_id, 'UGC takes are attributed to the key');
        $this->assertSame('direct_camera', data_get($p->visual_brief, 'ugc_format'));
        $this->assertSame(1, DB::table('ugc_run_requests')->count(), 'the dashboard\'s own idempotency receipt was written');
        $this->assertSame(1, \App\Models\Scene::query()->where('project_id', $p->id)->count());

        // Replay returns the same videos; a second key on the used quote is refused.
        $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'ugc-1'])->assertOk()->assertJsonPath('data.videos.0.id', $videos[0]['id']);
        $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'ugc-2'])->assertStatus(409);
        $this->withToken($key)->getJson('/api/developer/v1/ugc/allowance')->assertOk()->assertJsonPath('data.used', 1);
        $this->withToken($key)->getJson('/api/developer/v1/videos/'.$videos[0]['id'])->assertOk()->assertJsonPath('data.video.status', 'generating');
    }

    public function test_ugc_one_shot_is_priced_like_the_dashboard_and_creates_one_take(): void
    {
        [$ws, , $key] = $this->tenant();
        $segments = $this->ugcSegments();
        $quote = $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', ['mode' => 'one_shot', 'format' => 'direct_camera', 'segments' => $segments, 'presenter_description' => 'A cheerful thirty-something in a home office', 'consent' => true, 'quality' => 'full'])
            ->assertStatus(201)->assertJsonPath('data.takes', 1);
        $engine = $quote->json('data.credits.engine');
        $seconds = $quote->json('data.credits.plan_seconds');
        $this->assertSame(10, $seconds);
        $this->assertSame($seconds * \App\Services\CreditService::VIDEO_ONESHOT_PER_SECOND[$engine], $quote->json('data.credits.max'));

        $draft = $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', ['mode' => 'one_shot', 'format' => 'direct_camera', 'segments' => $segments, 'consent' => true, 'quality' => 'draft'])->assertStatus(201);
        if ($draft->json('data.credits.engine') === 'seedance25') {
            $this->assertSame(10 * \App\Services\CreditService::VIDEO_ONESHOT_SEEDANCE_DRAFT, $draft->json('data.credits.max'));
        }

        $created = $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'os-1'])->assertStatus(202);
        Bus::assertDispatched(\App\Jobs\GenerateOneShotUgcJob::class);
        $p = Project::query()->findOrFail($created->json('data.videos.0.id'));
        $this->assertNotNull($p->api_key_id);
        $this->assertSame('one_shot', data_get($p->visual_brief, 'ugc_format'));
    }

    public function test_characters_are_created_updated_and_imaged_through_the_dashboard_path(): void
    {
        [$ws, , $key] = $this->tenant();
        [$other] = $this->tenant();
        $ref = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'image', 'title' => 'Ref', 'storage_url' => 'https://b2/r.png', 'mime_type' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        $theirs = DB::table('assets')->insertGetId(['workspace_id' => $other->id, 'asset_type' => 'image', 'title' => 'Theirs', 'created_at' => now(), 'updated_at' => now()]);

        // A reference needs consent; a foreign reference is refused; plain creation works.
        $this->withToken($key)->postJson('/api/developer/v1/characters', ['name' => 'Maya', 'reference_asset_ids' => [$ref]])->assertStatus(422)->assertJsonPath('error.code', 'consent_required');
        $this->withToken($key)->postJson('/api/developer/v1/characters', ['name' => 'Maya', 'reference_asset_ids' => [$theirs], 'consent' => true])->assertStatus(422)->assertJsonPath('error.code', 'invalid_asset');
        $maya = $this->withToken($key)->postJson('/api/developer/v1/characters', ['name' => 'Maya', 'description' => 'Warm, thirties, home office', 'reference_asset_ids' => [$ref], 'consent' => true])
            ->assertStatus(201)->assertJsonPath('data.character.name', 'Maya')->json('data.character.id');
        $this->assertSame($ref, (int) \App\Models\Character::query()->findOrFail($maya)->reference_asset_id);
        $this->withToken($key)->patchJson('/api/developer/v1/characters/'.$maya, ['description' => 'Warm, forties'])->assertOk()->assertJsonPath('data.character.description', 'Warm, forties');
        $this->assertContains('Maya', array_column($this->withToken($key)->getJson('/api/developer/v1/characters')->json('data.characters'), 'name'));

        // Plan limit: creator allows 10; fill it and the next is refused with the app's own code.
        for ($i = 0; $i < 9; $i++) {
            \App\Models\Character::query()->create(['workspace_id' => $ws->id, 'name' => "C$i", 'status' => 'active']);
        }
        $this->withToken($key)->postJson('/api/developer/v1/characters', ['name' => 'One too many'])->assertStatus(422)->assertJsonPath('error.code', 'plan_resource_cap');

        // Image: quote at the reference rate, create, poll.
        $quote = $this->withToken($key)->postJson("/api/developer/v1/characters/{$maya}/images/quotes", ['prompt' => 'Maya smiling at a standing desk', 'style' => 'photorealistic', 'aspect_ratio' => '9:16', 'set_as_reference' => true])
            ->assertStatus(201)->assertJsonPath('data.credits.with_reference', true);
        $this->assertSame(app(\App\Services\Generation\Image\ImageAdapterFactory::class)->referenceGenerationCost(null), $quote->json('data.credits.max'));
        $this->withToken($key)->postJson('/api/developer/v1/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'z'])->assertStatus(409)->assertJsonPath('error.code', 'quote_kind_mismatch');

        $created = $this->withToken($key)->postJson("/api/developer/v1/characters/{$maya}/images", ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'img-1'])->assertStatus(202)
            ->assertJsonPath('data.generation.status', 'generating')->assertJsonPath('data.generation.set_as_reference', true);
        Bus::assertDispatched(\App\Jobs\GenerateCharacterImageJob::class);
        $gid = $created->json('data.generation.id');
        $this->withToken($key)->postJson("/api/developer/v1/characters/{$maya}/images", ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'img-1'])->assertOk()->assertJsonPath('data.generation.id', $gid);
        $this->assertSame(1, \App\Models\CharacterImageGeneration::query()->count());

        \App\Models\CharacterImageGeneration::query()->whereKey($gid)->update(['status' => 'succeeded', 'result_asset_id' => $ref]);
        $this->withToken($key)->getJson("/api/developer/v1/characters/{$maya}/images/{$gid}")->assertOk()
            ->assertJsonPath('data.generation.status', 'completed')->assertJsonPath('data.generation.image.asset_id', $ref)->assertJsonPath('data.generation.retry_after_seconds', null);
        // Not yours: not found.
        [, , $keyB] = $this->tenant();
        $this->withToken($keyB)->postJson("/api/developer/v1/characters/{$maya}/images/quotes", ['prompt' => 'x'])->assertStatus(404);
    }

    public function test_a_key_reaches_the_developer_namespace_and_nothing_else(): void
    {
        [, , $key] = $this->tenant();

        foreach (['/api/v1/projects', '/api/v1/me', '/api/v1/billing/status', '/api/v1/api-keys', '/api/v1/social/accounts'] as $path) {
            $this->withToken($key)->getJson($path)->assertStatus(403)->assertJsonPath('error.code', 'api_key_forbidden_path');
        }
        foreach (['/api/v1/cruise/apply', '/api/v1/api-keys', '/api/v1/projects/1/share'] as $path) {
            $this->withToken($key)->postJson($path)->assertStatus(403)->assertJsonPath('error.code', 'api_key_forbidden_path');
        }
        $this->withToken($key)->deleteJson('/api/v1/me')->assertStatus(403);

        $this->withToken($key)->getJson('/api/developer/v1/capabilities')
            ->assertOk()
            ->assertJsonPath('data.plan', 'creator')
            ->assertJsonPath('data.credits.balance', 500)
            ->assertJsonPath('data.video.source_types', ['prompt', 'script', 'url', 'product_description', 'images'])
            ->assertJsonMissingPath('data.billing');
    }

    public function test_a_browser_session_can_call_the_developer_namespace(): void
    {
        [$ws, $u] = $this->tenant();
        $this->withToken($this->sessionToken($u, $ws))->getJson('/api/developer/v1/capabilities')->assertOk();
    }

    public function test_a_plan_without_api_access_is_refused_at_the_door(): void
    {
        [, , $key] = $this->tenant('free');
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertStatus(403)->assertJsonPath('error.code', 'api_access_not_on_plan');
    }

    public function test_quote_create_poll_and_fetch(): void
    {
        [$ws, , $key] = $this->tenant();

        $quote = $this->quote($key)->assertStatus(201)
            ->assertJsonPath('data.can_afford', true)
            ->assertJsonPath('data.request.visual_mode', 'stock');
        $quoteId = $quote->json('data.quote_id');
        $this->assertStringStartsWith('q_', $quoteId);
        $this->assertGreaterThan(0, $quote->json('data.credits.max'));
        $this->assertSame(0, Project::query()->count(), 'quoting must not create anything');

        $created = $this->create($key, $quoteId)->assertStatus(202)
            ->assertJsonPath('data.video.status', 'generating')
            ->assertJsonPath('data.video.quote_id', $quoteId)
            ->assertJsonPath('data.video.credits.authorized_max', $quote->json('data.credits.max'));
        $id = $created->json('data.video.id');
        $this->assertSame('https://app.test/projects/'.$id.'/editor', $created->json('data.video.project_url'));

        $project = Project::query()->findOrFail($id);
        $this->assertSame((int) $ws->getKey(), (int) $project->workspace_id);
        $this->assertSame('prompt', $project->source_type);
        $this->assertSame('stock', $project->visual_generation_mode);
        $this->assertSame(30, (int) $project->duration_target_seconds);
        $this->assertNotNull($project->api_key_id, 'the creating key is recorded on the project');
        Bus::assertDispatched(GenerateScriptJob::class);
        $this->assertNotNull(ApiQuote::query()->findOrFail($quoteId)->consumed_at);

        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}")->assertOk()
            ->assertJsonPath('data.video.status', 'generating')
            ->assertJsonPath('data.video.retry_after_seconds', 15)
            ->assertJsonPath('data.video.credits.spent', 0);
        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/result")->assertStatus(409)
            ->assertJsonPath('error.code', 'not_ready')->assertJsonPath('error.context.status', 'generating');

        // Generation finishes and the automatic export runs.
        Project::withoutEvents(fn () => $project->forceFill(['status' => 'ready_for_review'])->save());
        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}")->assertOk()->assertJsonPath('data.video.status', 'exporting');

        DB::table('credit_ledger')->insert([
            ['workspace_id' => $ws->id, 'project_id' => $id, 'operation' => 'tts', 'credits' => 9, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws->id, 'project_id' => $id, 'operation' => 'refund:tts', 'credits' => -3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $assetId = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://b2/x.mp4', 'duration_seconds' => 29.6, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('export_jobs')->insert(['workspace_id' => $ws->id, 'project_id' => $id, 'aspect_ratio' => '9:16', 'file_name' => 'video.mp4', 'status' => 'completed', 'output_asset_id' => $assetId, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}")->assertOk()
            ->assertJsonPath('data.video.status', 'completed')
            ->assertJsonPath('data.video.retry_after_seconds', null)
            ->assertJsonPath('data.video.credits.spent', 6);

        $result = $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/result")->assertOk()
            ->assertJsonPath('data.video.status', 'completed')
            ->assertJsonPath('data.video.duration_seconds', 29.6)
            ->assertJsonPath('data.video.credits.spent', 6);
        $url = $result->json('data.video.download_url');
        $this->assertStringContainsString("/media/assets/{$assetId}?download=1", $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertNull($project->fresh()->share_token, 'fetching the result never makes the project public');
    }

    public function test_replaying_the_same_idempotency_key_returns_the_same_video(): void
    {
        [, , $key] = $this->tenant();
        $quoteId = $this->quote($key)->json('data.quote_id');
        $first = $this->create($key, $quoteId, 'retry-me')->assertStatus(202)->json('data.video.id');
        $again = $this->create($key, $quoteId, 'retry-me')->assertStatus(200)->json('data.video.id');
        $this->assertSame($first, $again);
        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_consumed_quote_cannot_be_used_for_a_second_video(): void
    {
        [, , $key] = $this->tenant();
        $quoteId = $this->quote($key)->json('data.quote_id');
        $this->create($key, $quoteId, 'one')->assertStatus(202);
        $this->create($key, $quoteId, 'two')->assertStatus(409)->assertJsonPath('error.code', 'quote_consumed');
        $this->assertSame(1, Project::query()->count());
    }

    public function test_an_idempotency_key_cannot_be_reused_for_a_different_quote(): void
    {
        [, , $key] = $this->tenant();
        $this->create($key, $this->quote($key)->json('data.quote_id'), 'same')->assertStatus(202);
        $this->create($key, $this->quote($key)->json('data.quote_id'), 'same')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(1, Project::query()->count());
    }

    public function test_an_expired_quote_is_refused(): void
    {
        [, , $key] = $this->tenant();
        $quoteId = $this->quote($key)->json('data.quote_id');
        ApiQuote::query()->whereKey($quoteId)->update(['expires_at' => now()->subMinute()]);
        $this->create($key, $quoteId)->assertStatus(410)->assertJsonPath('error.code', 'quote_expired');
        $this->assertNull(ApiQuote::query()->findOrFail($quoteId)->consumed_at);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_a_quote_belongs_to_one_workspace(): void
    {
        [, , $keyA] = $this->tenant();
        [, , $keyB] = $this->tenant();
        $quoteId = $this->quote($keyA)->json('data.quote_id');
        $this->create($keyB, $quoteId)->assertStatus(404)->assertJsonPath('error.code', 'quote_not_found');
        $this->assertSame(0, Project::query()->count());
    }

    public function test_create_needs_the_balance_to_cover_the_quoted_maximum(): void
    {
        [$ws, , $key] = $this->tenant();
        $quote = $this->quote($key);
        $max = (int) $quote->json('data.credits.max');
        $ws->forceFill(['credits_monthly' => $max - 1])->save();

        $this->create($key, $quote->json('data.quote_id'))->assertStatus(402)
            ->assertJsonPath('error.code', 'insufficient_credits')
            ->assertJsonPath('error.context.shortage', 1);
        $this->assertNull(ApiQuote::query()->findOrFail($quote->json('data.quote_id'))->consumed_at, 'a refused create gives the quote back');
        $this->assertSame(0, Project::query()->count());
        Bus::assertNotDispatched(GenerateScriptJob::class);
    }

    public function test_a_video_belongs_to_one_workspace(): void
    {
        [, , $keyA] = $this->tenant();
        [, , $keyB] = $this->tenant();
        $id = $this->create($keyA, $this->quote($keyA)->json('data.quote_id'))->json('data.video.id');
        $this->withToken($keyB)->getJson("/api/developer/v1/videos/{$id}")->assertStatus(404);
        $this->withToken($keyB)->getJson("/api/developer/v1/videos/{$id}/result")->assertStatus(404);
    }

    public function test_the_plan_duration_cap_applies_when_quoting(): void
    {
        [, , $key] = $this->tenant('creator'); // caps at 300s
        $this->quote($key, ['duration_seconds' => 400])->assertStatus(422)
            ->assertJsonPath('error.code', 'plan_duration_exceeded')
            ->assertJsonPath('error.context.max_duration_seconds', 300);
        $this->assertSame(0, ApiQuote::query()->count());
    }

    public function test_quote_validation_uses_the_api_envelope(): void
    {
        [, , $key] = $this->tenant();
        $this->quote($key, ['visual_mode' => 'ai_video'])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['context' => ['errors' => ['animate_tier']]]]);
        $this->quote($key, ['visual_mode' => 'stock', 'animate_tier' => 'quick'])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
        $this->quote($key, ['content' => 'short'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_source_content');
        $this->create($key, 'q_nope')->assertStatus(404);
        $this->withToken($key)->postJson('/api/developer/v1/videos', ['quote_id' => 'q_x'])->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_an_ai_video_quote_prices_the_animation(): void
    {
        [, , $key] = $this->tenant();
        $stock = (int) $this->quote($key)->json('data.credits.max');
        $ai = (int) $this->quote($key, ['visual_mode' => 'ai_video', 'animate_tier' => 'quick'])->assertStatus(201)->json('data.credits.max');
        $this->assertGreaterThan($stock, $ai);
    }
}
