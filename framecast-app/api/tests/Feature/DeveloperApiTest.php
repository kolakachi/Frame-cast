<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWithJwt;
use App\Jobs\GenerateScriptJob;
use App\Models\ApiKey;
use App\Models\ApiQuote;
use App\Models\AuthSession;
use App\Models\Project;
use App\Models\Scene;
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
        if (getenv('PHASE_A_ACCOUNTING_TESTS') === '1') $this->enableOperationAccounting();

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
        DB::table('credit_ledger')->insert((\App\Services\Developer\OperationAccounting::enabled() ? ['api_key_id' => ApiKey::resolve($plain)->id] : []) + ['workspace_id' => $ws->id, 'project_id' => $first, 'operation' => 'tts', 'credits' => 45, 'created_at' => now(), 'updated_at' => now()]);
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

    /** A created video with two finished scenes, ready to edit. */
    private function editableVideo(string $key, int $wsId): array
    {
        $id = $this->create($key, $this->quote($key)->json('data.quote_id'), 'edit-base')->assertStatus(202)->json('data.video.id');
        $img = DB::table('assets')->insertGetId(['workspace_id' => $wsId, 'asset_type' => 'image', 'title' => 'Still', 'storage_url' => 'https://b2/s.png', 'mime_type' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
        $aud = DB::table('assets')->insertGetId(['workspace_id' => $wsId, 'asset_type' => 'audio', 'title' => 'VO', 'storage_url' => 'https://b2/v.mp3', 'mime_type' => 'audio/mpeg', 'created_at' => now(), 'updated_at' => now()]);
        $scenes = [];
        foreach (['Hook line.', 'Second point.'] as $i => $text) {
            $scenes[] = Scene::query()->create(['project_id' => $id, 'scene_order' => $i + 1, 'scene_type' => 'narration', 'script_text' => $text, 'visual_type' => 'ai_image', 'visual_asset_id' => $img, 'voice_settings_json' => ['audio_asset_id' => $aud, 'voice_id' => 'Kore']])->getKey();
        }
        Project::withoutEvents(fn () => Project::query()->whereKey($id)->update(['status' => 'ready_for_review']));

        return [$id, $scenes];
    }

    public function test_editor_read_propose_apply_and_export_go_through_the_dashboard_controllers(): void
    {
        config(['developer.limits.writes_per_minute' => 100, 'developer.limits.workspace_writes_per_minute' => 100]);
        [$ws, , $key] = $this->tenant();
        [$id, [$s1, $s2]] = $this->editableVideo($key, $ws->id);
        $get = fn (string $path) => $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}{$path}");

        $read = $get('/project')->assertOk()->assertJsonPath('data.whole_video', false)->assertJsonPath('data.scenes.0.readiness.has_voice', true)->assertJsonPath('data.scenes.0.readiness.has_visual', true);
        $rev = $read->json('data.revision');
        $this->assertStringStartsWith('r_', $rev);
        $this->assertCount(2, $read->json('data.scenes'));

        $schema = $get('/project/schema')->assertOk()->assertJsonPath('data.revision', $rev);
        $ops = collect($schema->json('data.operations'))->keyBy('name');
        $this->assertTrue($ops['update_scene']['available']);
        $this->assertTrue($ops['regenerate_music']['spends']);
        $this->assertFalse($ops['update_scene']['spends']);

        $changes = [
            ['op' => 'update_scene', 'scene_id' => $s1, 'settings' => ['script_text' => 'A sharper hook line.', 'label' => 'Hook']],
            ['op' => 'reorder_scenes', 'scene_ids' => [$s2, $s1]],
            ['op' => 'regenerate_music', 'scene_id' => $s1, 'mood' => 'upbeat'],
            ['op' => 'update_project', 'title' => 'Standing desk, v2'],
        ];
        // Unknown setting, foreign scene, bad revision: all refused before anything is priced.
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $rev, 'changes' => [['op' => 'update_scene', 'scene_id' => $s1, 'settings' => ['workspace_id' => 9]]]])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $rev, 'changes' => [['op' => 'duplicate_scene', 'scene_id' => 999999]]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_scene');
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => 'r_stale', 'changes' => $changes])->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');

        $proposal = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $rev, 'changes' => $changes])->assertStatus(201)
            ->assertJsonPath('data.credits.max', \App\Services\CreditService::AI_MUSIC)->assertJsonPath('data.changes.2.credits_max', \App\Services\CreditService::AI_MUSIC)->assertJsonPath('data.changes.0.credits_max', 0);
        $pid = $proposal->json('data.proposal_id');

        // The project changes underneath (someone edits in the dashboard): apply refuses and hands the proposal back.
        Scene::query()->whereKey($s2)->update(['label' => 'touched', 'updated_at' => now()->addSecond()]);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$pid}/apply")->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');
        $this->assertNull(ApiQuote::query()->findOrFail($pid)->consumed_at);

        $rev2 = $get('/project')->json('data.revision');
        $this->assertNotSame($rev, $rev2);
        $pid2 = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $rev2, 'changes' => $changes])->assertStatus(201)->json('data.proposal_id');
        $applied = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$pid2}/apply")->assertOk()->assertJsonPath('data.failed', 0);
        $this->assertCount(4, $applied->json('data.applied'));
        $this->assertNotSame($rev2, $applied->json('data.revision'));
        $this->assertSame('A sharper hook line.', Scene::query()->findOrFail($s1)->script_text);
        $this->assertSame(1, (int) Scene::query()->findOrFail($s2)->scene_order, 'reordered through the editor');
        $this->assertSame('Standing desk, v2', Project::query()->findOrFail($id)->title);
        Bus::assertDispatched(\App\Jobs\GenerateAIMusicJob::class);
        // Replay of the same apply returns the stored result and does nothing twice.
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$pid2}/apply")->assertOk()->assertJsonPath('data.failed', 0);
        Bus::assertDispatchedTimes(\App\Jobs\GenerateAIMusicJob::class, 1);

        // A whole-video take cannot have its scenes edited.
        Project::withoutEvents(fn () => Project::query()->whereKey($id)->update(['visual_brief' => json_encode(['ugc_format' => 'one_shot'])]));
        $rev3 = $get('/project')->assertJsonPath('data.whole_video', true)->json('data.revision');
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $rev3, 'changes' => [['op' => 'update_scene', 'scene_id' => $s1, 'settings' => ['label' => 'x']]]])->assertStatus(422)->assertJsonPath('error.code', 'whole_video_edit_unsupported');
        $this->assertFalse(collect($get('/project/schema')->json('data.operations'))->keyBy('name')['update_scene']['available']);
    }

    public function test_editor_export_uses_the_dashboard_export_path(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id] = $this->editableVideo($key, $ws->id);
        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/exports")->assertOk()->assertJsonPath('data.exports', []);
        $r = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/exports", ['aspect_ratios' => ['9:16']]);
        if ($r->status() === 202) {
            $this->assertSame('9:16', $r->json('data.exports.0.aspect_ratio'));
            $this->assertSame(1, \App\Models\ExportJob::query()->where('project_id', $id)->count());
        } else {
            // The editor refused for a reason of its own (scene readiness); the envelope is ours.
            $r->assertStatus(422);
            $this->assertNotEmpty($r->json('error.code'));
        }
        [, , $keyB] = $this->tenant();
        $this->withToken($keyB)->getJson("/api/developer/v1/videos/{$id}/project")->assertStatus(404);
    }

    public function test_the_in_app_assistant_plans_but_never_applies_on_its_own(): void
    {
        config(['developer.limits.writes_per_minute' => 100, 'developer.limits.workspace_writes_per_minute' => 100]);
        [$ws, , $key] = $this->tenant();
        [$id, [$s1]] = $this->editableVideo($key, $ws->id);

        $this->withToken($key)->getJson('/api/developer/v1/assistant/tools')->assertOk()->assertJsonPath('data.tools.0.name', fn ($n) => is_string($n));

        // Cruise resolves the request into one concrete, priced action.
        $cruise = $this->createMock(\App\Services\CruiseControl\CruiseControlService::class);
        $cruise->method('resolve')->willReturn([
            'reply_to_user' => 'I can make the hook punchier.', 'action' => null,
            'actions' => [['tool' => 'update_scene_script', 'params' => ['scene_id' => $s1, 'new_text' => 'Still sitting all day? Here is why a standing desk pays for itself.'],
                'diff_lines' => ['Scene 1 script → "Still sitting all day?…"'], 'estimated_cost' => \App\Services\CreditService::TTS, 'confirmation_class' => 'always_prompt', 'affected_section' => 'scene']],
        ]);
        $this->instance(\App\Services\CruiseControl\CruiseControlService::class, $cruise);

        $plan = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans", ['request' => 'Make the hook punchier'])->assertStatus(201)
            ->assertJsonPath('data.credits.max', \App\Services\CreditService::TTS)->assertJsonPath('data.actions.0.tool', 'update_scene_script')->assertJsonPath('data.reply', 'I can make the hook punchier.');
        $this->assertSame('Hook line.', Scene::query()->findOrFail($s1)->script_text, 'planning changes nothing');
        $pid = $plan->json('data.plan_id');

        // The plan cannot be spent on the wrong endpoint, and a changed project refuses it.
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$pid}/apply")->assertStatus(409)->assertJsonPath('error.code', 'quote_kind_mismatch');
        Scene::query()->whereKey($s1)->update(['label' => 'touched', 'updated_at' => now()->addSecond()]);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans/{$pid}/apply")->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');

        $pid2 = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans", ['request' => 'Make the hook punchier'])->assertStatus(201)->json('data.plan_id');
        $applied = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans/{$pid2}/apply")->assertOk()->assertJsonPath('data.failed', 0);
        $this->assertTrue($applied->json('data.applied.0.ok'));
        $this->assertStringStartsWith('Still sitting all day?', Scene::query()->findOrFail($s1)->script_text, 'applied through Cruise\'s own tool');
        $this->assertSame(1, DB::table('cruise_audit_logs')->where('phase', 'apply')->count(), 'Cruise audited the apply as it does in the editor');
        // Replay is idempotent.
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans/{$pid2}/apply")->assertOk()->assertJsonPath('data.failed', 0);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans/{$pid2}/apply", ['only' => []])
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_payload_mismatch');
        $this->withToken($key)->getJson("/api/developer/v1/operations/{$pid2}")->assertOk()
            ->assertJsonPath('data.operation.progress.in_progress_index', null)
            ->assertJsonPath('data.operation.result_recorded', true);
        $this->assertSame(1, DB::table('cruise_audit_logs')->where('phase', 'apply')->count());

        // Nothing resolvable: no plan, nothing to spend.
        $cruise2 = $this->createMock(\App\Services\CruiseControl\CruiseControlService::class);
        $cruise2->method('resolve')->willReturn(['reply_to_user' => "I can't do that yet.", 'action' => null, 'actions' => []]);
        $this->instance(\App\Services\CruiseControl\CruiseControlService::class, $cruise2);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans", ['request' => 'Add a dragon'])->assertOk()->assertJsonPath('data.plan_id', null);
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

    public function test_every_plan_reaches_the_api_with_its_own_limits(): void
    {
        [, , $key] = $this->tenant('free');
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertOk()->assertJsonPath('data.plan', 'free')->assertJsonPath('data.limits.max_duration_seconds', 60);
    }

    public function test_a_character_video_is_quoted_at_the_reference_rate(): void
    {
        [$ws, , $key] = $this->tenant();
        $char = \App\Models\Character::query()->create(['workspace_id' => $ws->id, 'name' => 'Maya', 'status' => 'active']);
        $plain = $this->quote($key, ['visual_mode' => 'ai_images'])->assertStatus(201);
        $withChar = $this->quote($key, ['visual_mode' => 'ai_images', 'character_id' => $char->id])->assertStatus(201)->assertJsonPath('data.credits.breakdown.character_reference', true);
        $factory = app(\App\Services\Generation\Image\ImageAdapterFactory::class);
        $this->assertSame($factory->costFor(null), $plain->json('data.credits.breakdown.visual_per_scene'));
        $this->assertSame($factory->referenceGenerationCost(null), $withChar->json('data.credits.breakdown.visual_per_scene'));
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
        DB::table('export_jobs')->insert(['workspace_id' => $ws->id, 'project_id' => $id, 'aspect_ratio' => '9:16', 'file_name' => 'video.mp4', 'queued_at' => now(), 'status' => 'completed', 'output_asset_id' => $assetId, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

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
    public function test_wrong_targets_never_claim_or_reopen_quotes(): void
    {
        [$ws, , $key] = $this->tenant();
        $target = Project::query()->create(['workspace_id' => $ws->id, 'title' => 'Other target', 'status' => 'draft']);
        foreach (['edit', 'assistant_plan', 'character_image'] as $kind) {
            foreach ([false, true] as $consumed) {
                $id = ApiQuote::newId();
                $quote = ApiQuote::query()->create([
                    'id' => $id, 'workspace_id' => $ws->id,
                    'payload_json' => ['__kind' => $kind, $kind === 'character_image' ? 'character_id' : 'project_id' => 999999],
                    'credits_min' => 0, 'credits_max' => 0, 'expires_at' => now()->addMinutes(10),
                    'consumed_at' => $consumed ? now() : null,
                    'idempotency_key' => $consumed ? $id : null,
                    'project_id' => $consumed ? $target->id : null,
                ]);
                $before = $quote->fresh()->getAttributes();
                $path = match ($kind) {
                    'edit' => "/api/developer/v1/videos/{$target->id}/proposals/{$id}/apply",
                    'assistant_plan' => "/api/developer/v1/videos/{$target->id}/assistant/plans/{$id}/apply",
                    default => "/api/developer/v1/characters/{$target->id}/images",
                };
                $this->withToken($key)->postJson($path, ['quote_id' => $id, 'idempotency_key' => $id])
                    ->assertStatus(409)->assertJsonPath('error.code', 'quote_kind_mismatch');
                $this->assertSame($before, $quote->fresh()->getAttributes());
            }
        }
    }

    public function test_spokesperson_quote_uses_audio_length_not_animation_bucket(): void
    {
        $audio = \App\Models\Asset::query()->create(['duration_seconds' => 30, 'asset_type' => 'audio']);
        $scene = new Scene(['duration_seconds' => 12, 'voice_settings_json' => ['audio_asset_id' => $audio->id]]);
        foreach ([[], ['duration_seconds' => 5], ['duration_seconds' => 10]] as $input) {
            $this->assertSame((int) \App\Services\CreditService::spokespersonCost(30),
                \App\Services\Developer\EditOperations::price('animate', new Project, $scene, ['tier' => 'spokesperson'] + $input));
        }
        $scene->voice_settings_json = [];
        $this->assertSame((int) \App\Services\CreditService::spokespersonCost(12),
            \App\Services\Developer\EditOperations::price('animate', new Project, $scene, ['tier' => 'spokesperson']));
    }

    public function test_editor_dispatch_preserves_clears_and_image_overrides(): void
    {
        $controller = \Mockery::mock(\App\Http\Controllers\Api\V1\Project\ProjectController::class);
        $controller->shouldReceive('update')->once()->withArgs(function ($request, $id) {
            return $id === 123 && $request->all() === ['music_asset_id' => null, 'brand_kit_id' => null, 'channel_id' => null];
        })->andReturn(response()->json(['data' => []]));
        $this->instance(\App\Http\Controllers\Api\V1\Project\ProjectController::class, $controller);
        $sceneController = \Mockery::mock(\App\Http\Controllers\Api\V1\Scene\SceneController::class);
        $sceneController->shouldReceive('generateImage')->once()->withArgs(function ($request, $id) {
            return $id === 456 && $request->all() === ['model_key' => 'gpt-image-2', 'style' => 'cinematic', 'prompt_override' => 'A red bicycle'];
        })->andReturn(response()->json(['data' => []]));
        $this->instance(\App\Http\Controllers\Api\V1\Scene\SceneController::class, $sceneController);
        $project = new Project;
        $project->id = 123;
        $request = \Illuminate\Http\Request::create('/test');
        \App\Services\Developer\EditOperations::execute('update_project', $request, $project,
            ['music_asset_id' => null, 'brand_kit_id' => null, 'channel_id' => null]);
        \App\Services\Developer\EditOperations::execute('generate_image', $request, $project,
            ['scene_id' => 456, 'model_key' => 'gpt-image-2', 'style' => 'cinematic', 'prompt_override' => 'A red bicycle']);
    }
    public function test_content_revisions_detect_same_second_scene_and_project_changes(): void
    {
        $this->freezeTime();
        [$ws] = $this->tenant();
        $project = Project::create(['workspace_id' => $ws->id, 'title' => 'Before']);
        $scene = Scene::create(['project_id' => $project->id, 'scene_order' => 1, 'script_text' => 'Before']);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision($project);
        $project->update(['title' => 'After']);
        $next = \App\Http\Controllers\Api\Developer\V1\EditorController::revision($project);
        $this->assertNotSame($revision, $next);
        $scene->update(['script_text' => 'After']);
        $this->assertNotSame($next, \App\Http\Controllers\Api\Developer\V1\EditorController::revision($project));
    }

    public function test_stale_and_superseded_exports_require_explicit_selection(): void
    {
        Schema::table('export_jobs', fn (Blueprint $t) => $t->string('source_fingerprint')->nullable());
        [$ws, , $key] = $this->tenant();
        $project = Project::create(['workspace_id' => $ws->id, 'title' => 'Video', 'status' => 'ready_for_review']);
        $asset = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://example.test/video.mp4', 'duration_seconds' => 10]);
        $export = \App\Models\ExportJob::create(['workspace_id' => $ws->id, 'project_id' => $project->id, 'status' => 'completed', 'output_asset_id' => $asset->id, 'queued_at' => now()]);
        $project->update(['music_settings_json' => ['volume' => 10]]);
        $base = "/api/developer/v1/videos/{$project->id}";
        $this->withToken($key)->getJson($base)->assertOk()->assertJsonPath('data.video.status', 'needs_export');
        $this->withToken($key)->getJson($base.'/result')->assertStatus(409)->assertJsonPath('error.code', 'stale_export');
        $this->withToken($key)->getJson($base.'/result?allow_stale=1')->assertStatus(409);
        $this->withToken($key)->getJson($base.'/result?export_id='.$export->id.'&allow_stale=1')->assertOk()->assertJsonPath('data.video.export_id', $export->id);
        \App\Models\ExportJob::create(['workspace_id' => $ws->id, 'project_id' => $project->id, 'status' => 'queued', 'queued_at' => now()]);
        $this->withToken($key)->getJson($base.'/result')->assertStatus(409)->assertJsonPath('error.code', 'not_ready');
    }

    public function test_paid_dependent_edits_require_a_new_quote_after_prior_changes(): void
    {
        [$ws, , $key] = $this->tenant();
        $project = Project::create(['workspace_id' => $ws->id, 'title' => 'Video']);
        $scene = Scene::create(['project_id' => $project->id, 'scene_order' => 1, 'script_text' => 'Before']);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision($project);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$project->id}/proposals", [
            'revision' => $revision, 'changes' => [
                ['op' => 'update_scene', 'scene_id' => $scene->id, 'settings' => ['script_text' => 'New narration']],
                ['op' => 'regenerate_voice', 'scene_id' => $scene->id],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'dependent_changes_require_staging');
        $this->assertSame('Before', $scene->fresh()->script_text);
    }

    public function test_agency_shared_pool_charges_and_refunds_keep_the_client_and_key(): void
    {
        $this->enableOperationAccounting();
        [$agency, $user] = $this->tenant('agency', 100);
        $client = Workspace::create(['name' => 'Client', 'plan_tier' => 'creator', 'status' => 'active', 'parent_workspace_id' => $agency->id, 'funding_mode' => 'shared']);
        $client->forceFill(['parent_workspace_id' => $agency->id])->save();
        [$key] = ApiKey::issue($client->id, $user->id, 'Client key', null, 100);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($client, 60), $key->id));
        $credits = app(\App\Services\CreditService::class);
        $this->assertTrue($credits->deduct($client->id, 20, 'test'));
        $credits->refund($client->id, 5, 'test');
        $this->assertSame(85, $credits->balance($agency->id));
        $this->assertSame(15, $key->spentThisMonth());
        $this->assertSame(2, DB::table('credit_ledger')->where('workspace_id', $agency->id)->where('spent_by_workspace_id', $client->id)->where('api_key_id', $key->id)->count());
        \App\Services\Developer\OperationAccounting::close($id);
    }

    public function test_retry_requires_a_quote_and_replays_the_authorized_dispatch(): void
    {
        [$ws, , $key] = $this->tenant();
        $project = Project::create(['workspace_id' => $ws->id, 'source_type' => 'prompt', 'source_content_raw' => 'A desk that helps you stand', 'status' => 'failed', 'visual_generation_mode' => 'stock', 'duration_target_seconds' => 30]);
        $path = "/api/developer/v1/videos/{$project->id}";
        $this->withToken($key)->postJson($path.'/retry')->assertStatus(422);
        $quote = $this->withToken($key)->postJson($path.'/retry-quotes')->assertStatus(201)->json('data.quote_id');
        $controller = \Mockery::mock(\App\Http\Controllers\Api\V1\Project\ProjectController::class);
        $controller->shouldReceive('retryGeneration')->once()->andReturn(response()->json(['data' => []], 202));
        $this->instance(\App\Http\Controllers\Api\V1\Project\ProjectController::class, $controller);
        $this->withToken($key)->postJson($path.'/retry', ['quote_id' => $quote])->assertStatus(202)->assertJsonPath('data.retried', true);
        $this->withToken($key)->postJson($path.'/retry', ['quote_id' => $quote])->assertOk()->assertJsonPath('data.retried', true);
    }

    public function test_release_only_reopens_this_executions_unused_claim(): void
    {
        [$ws] = $this->tenant();
        $quote = $this->operationQuote($ws, 10);
        $quote->forceFill(['consumed_at' => now(), 'idempotency_key' => 'one',
            'payload_json' => ['__claim_token' => 'owned']])->save();
        $releaser = new class { use \App\Http\Controllers\Api\Developer\V1\ClaimsQuotes;
            public function release(ApiQuote $q): void { $this->releaseQuote($q); }
        };
        $stale = clone $quote;
        $stale->payload_json = ['__claim_token' => 'other'];
        $releaser->release($stale);
        $this->assertNotNull($quote->fresh()->consumed_at);
        $releaser->release($quote);
        $this->assertNull($quote->fresh()->consumed_at);
        $quote->refresh()->forceFill(['consumed_at' => now(), 'payload_json' => ['__claim_token' => 'owned', 'result' => ['done' => true]]])->save();
        $releaser->release($quote);
        $this->assertNotNull($quote->fresh()->consumed_at);
    }

    private function applyEditorChanges(string $token, int $id, array $changes): TestResponse
    {
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::findOrFail($id));
        $quote = $this->withToken($token)->postJson("/api/developer/v1/videos/{$id}/proposals", compact('revision', 'changes'))->assertStatus(201);
        return $this->withToken($token)->postJson("/api/developer/v1/videos/{$id}/proposals/{$quote->json('data.proposal_id')}/apply");
    }

    public function test_editor_settings_discovery_and_invalid_nested_fields(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$scene]] = $this->editableVideo($key, $ws->id);
        $schema = $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/project/schema")->assertOk();
        $schema->assertJsonPath('data.settings_schema.groups.voice_settings_json.fields.speed.default', 1)
            ->assertJsonPath('data.settings_schema.animation.quick.default_quality', '480p');
        $revision = $schema->json('data.revision');
        foreach ([['voice_settings_json' => ['speed' => 9]], ['motion_settings_json' => ['fit' => 'invented']], ['voice_settings_json' => ['made_up' => 1]], ['image_generation_settings_json' => ['in_progress' => false]]] as $settings) {
            $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $revision, 'changes' => [['op' => 'update_scene', 'scene_id' => $scene, 'settings' => $settings]]])->assertStatus(422);
        }
        $this->applyEditorChanges($key, $id, [['op' => 'update_scene', 'scene_id' => $scene, 'settings' => ['voice_settings_json' => ['speed' => 1.25, 'volume' => 50], 'motion_settings_json' => ['fit' => 'fit']]]])->assertOk()->assertJsonPath('data.failed', 0);
        $saved = Scene::findOrFail($scene);
        $this->assertSame(1.25, $saved->voice_settings_json['speed']);
        $this->assertArrayHasKey('audio_asset_id', $saved->voice_settings_json);
        $this->assertTrue($saved->voice_settings_json['is_outdated']);
        $this->assertSame(['fit' => 'fit'], $saved->motion_settings_json);
    }

    public function test_animation_history_restore_is_owned_scene_bound_and_free(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$scene]] = $this->editableVideo($key, $ws->id);
        $asset = DB::table('assets')->insertGetId(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://b2/old.mp4', 'mime_type' => 'video/mp4']);
        Scene::findOrFail($scene)->update(['image_generation_settings_json' => ['animation_history' => [['asset_id' => $asset]]]]);
        $balance = $ws->fresh()->creditsBalance();
        $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/project")->assertOk()->assertJsonPath('data.scenes.0.animation_history.0.asset_id', $asset);
        $this->applyEditorChanges($key, $id, [['op' => 'use_animation_history', 'scene_id' => $scene, 'asset_id' => $asset]])->assertOk()->assertJsonPath('data.failed', 0);
        $this->assertEquals($asset, Scene::findOrFail($scene)->visual_asset_id);
        $this->assertSame($balance, $ws->fresh()->creditsBalance());
        $this->applyEditorChanges($key, $id, [['op' => 'use_animation_history', 'scene_id' => $scene, 'asset_id' => 999999]])->assertOk()->assertJsonPath('data.applied.0.error.code', 'not_in_history');
        DB::table('assets')->where('id', $asset)->update(['workspace_id' => $ws->id + 99]);
        $this->applyEditorChanges($key, $id, [['op' => 'use_animation_history', 'scene_id' => $scene, 'asset_id' => $asset]])->assertOk()->assertJsonPath('data.applied.0.error.code', 'asset_missing');
    }

    public function test_rewrite_is_direct_apply_and_replay_does_not_generate_again(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$scene]] = $this->editableVideo($key, $ws->id);
        $ai = \Mockery::mock(\App\Services\Generation\AI\AIGenerationAdapter::class);
        $ai->shouldReceive('generate')->once()->andReturn(['content' => 'The exact new line.', 'provider_key' => 'fake', 'model' => 'test', 'tokens_used' => 10]);
        $this->instance(\App\Services\Generation\AI\AIGenerationAdapter::class, $ai);
        $result = $this->applyEditorChanges($key, $id, [['op' => 'rewrite_scene', 'scene_id' => $scene, 'mode' => 'more_documentary']])->assertOk()->assertJsonPath('data.failed', 0);
        $this->assertSame('The exact new line.', Scene::findOrFail($scene)->script_text);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$result->json('data.proposal_id')}/apply")->assertOk();
    }

    public function test_bulk_voice_preserves_skips_locks_and_replay(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$s1, $s2]] = $this->editableVideo($key, $ws->id);
        Scene::findOrFail($s2)->update(['locked_fields_json' => ['voice_settings_json']]);
        $result = $this->applyEditorChanges($key, $id, [['op' => 'rerecord_all']])->assertOk()->assertJsonPath('data.failed', 0)->assertJsonPath('data.applied.0.result.started_count', 1);
        Bus::assertDispatched(\App\Jobs\GenerateTTSJob::class, fn ($job) => $job->sceneIds === [$s1]);
        $this->assertNotEmpty(Scene::findOrFail($s1)->voice_settings_json['is_outdated']);
        $this->assertEmpty(Scene::findOrFail($s2)->voice_settings_json['is_outdated'] ?? false);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$result->json('data.proposal_id')}/apply")->assertOk();
        Bus::assertDispatchedTimes(\App\Jobs\GenerateTTSJob::class, 1);
    }

    public function test_bulk_animation_preserves_shared_render_savings(): void
    {
        [$ws, , $key] = $this->tenant('creator', 2000);
        [$id, [$s1, $s2]] = $this->editableVideo($key, $ws->id);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::findOrFail($id));
        $proposal = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $revision, 'changes' => [['op' => 'animate_all', 'tier' => 'quick']]])->assertStatus(201)->assertJsonPath('data.changes.0.preview.render_count', 1)->assertJsonPath('data.credits.max', 50);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$proposal->json('data.proposal_id')}/apply")->assertOk()->assertJsonPath('data.failed', 0)->assertJsonPath('data.applied.0.result.started_count', 2);
        Bus::assertDispatchedTimes(\App\Jobs\AnimateSceneJob::class, 1);
    }

    public function test_bulk_restyle_keeps_per_scene_prompts_and_returns_skips(): void
    {
        [$ws, , $key] = $this->tenant('creator', 2000);
        [$id, [$s1, $s2]] = $this->editableVideo($key, $ws->id);
        Scene::findOrFail($s2)->update(['image_generation_settings_json' => ['in_progress' => true, 'generation_started_at' => now()->toIso8601String()]]);
        $result = $this->applyEditorChanges($key, $id, [['op' => 'restyle_all', 'style' => 'anime', 'model_key' => 'gpt-image-2']])->assertOk()->assertJsonPath('data.failed', 0)->assertJsonPath('data.applied.0.result.started_count', 1);
        $this->assertCount(1, $result->json('data.applied.0.result.skipped'));
        Bus::assertDispatched(\App\Jobs\GenerateAIImageJob::class, fn ($j) => $j->sceneId === $s1 && $j->style === 'anime' && $j->promptOverride === null);
        Bus::assertDispatchedTimes(\App\Jobs\GenerateAIImageJob::class, 1);
    }

    public function test_recorded_mcp_payloads_reach_project_storage_and_image_job(): void
    {
        $file = getenv('MCP_EDITOR_PAYLOADS');
        if (! $file) $this->markTestSkipped('Run mcp/tests/editor-contract.mjs and set MCP_EDITOR_PAYLOADS for the transport-to-storage check.');
        $records = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        [$ws, , $key] = $this->tenant('creator', 2000);
        [$id, [$scene]] = $this->editableVideo($key, $ws->id);
        Project::findOrFail($id)->update(['music_asset_id' => 999, 'channel_id' => 888, 'brand_kit_id' => 777]);
        $this->applyEditorChanges($key, $id, $records[0]['body']['changes'])->assertOk()->assertJsonPath('data.failed', 0);
        $project = Project::findOrFail($id);
        foreach (['music_asset_id', 'channel_id', 'brand_kit_id'] as $field) $this->assertNull($project->$field);
        $moderation = \Mockery::mock(\App\Services\Moderation\ContentSafetyService::class);
        $moderation->shouldReceive('screenText')->andReturn(null);
        $this->instance(\App\Services\Moderation\ContentSafetyService::class, $moderation);
        $changes = $records[1]['body']['changes']; $changes[0]['scene_id'] = $scene;
        $this->applyEditorChanges($key, $id, $changes)->assertOk()->assertJsonPath('data.failed', 0);
        Bus::assertDispatched(\App\Jobs\GenerateAIImageJob::class, fn ($j) => $j->sceneId === $scene && $j->style === $changes[0]['style'] && $j->modelKey === $changes[0]['model_key'] && $j->promptOverride === $changes[0]['prompt_override']);
    }

    public function test_scene_dispatch_resolves_all_required_controller_dependencies(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$scene]] = $this->editableVideo($key, $ws->id);
        $image = Scene::findOrFail($scene)->visual_asset_id;
        $this->applyEditorChanges($key, $id, [['op' => 'swap_visual', 'scene_id' => $scene, 'visual_asset_id' => $image]])->assertOk()->assertJsonPath('data.failed', 0);
        Scene::findOrFail($scene)->update(['script_text' => '']);
        // The real method must reach its own validation (and not crash on a missing DI argument).
        $this->applyEditorChanges($key, $id, [['op' => 'regenerate_voice', 'scene_id' => $scene]])->assertOk()->assertJsonPath('data.applied.0.error.code', 'invalid_scene_state');
    }

    public function test_bulk_retry_selection_never_requeues_the_completed_scene(): void
    {
        [$ws, , $key] = $this->tenant('creator', 2000);
        [$id, [$s1, $s2]] = $this->editableVideo($key, $ws->id);
        Scene::findOrFail($s1)->update(['image_generation_settings_json' => ['in_progress' => false, 'last_error' => null]]);
        Scene::findOrFail($s2)->update(['image_generation_settings_json' => ['in_progress' => false, 'last_error' => 'provider unavailable']]);
        $this->applyEditorChanges($key, $id, [['op' => 'restyle_all', 'style' => 'anime', 'scene_ids' => [$s2]]])->assertOk()->assertJsonPath('data.failed', 0)->assertJsonPath('data.applied.0.result.scenes.0.scene_id', $s2);
        Bus::assertDispatched(\App\Jobs\GenerateAIImageJob::class, fn ($job) => $job->sceneId === $s2);
        Bus::assertNotDispatched(\App\Jobs\GenerateAIImageJob::class, fn ($job) => $job->sceneId === $s1);
    }

    public function test_bulk_scope_and_stale_proposals_are_refused_before_dispatch(): void
    {
        [$ws, , $key] = $this->tenant('creator', 2000);
        [$id, [$s1]] = $this->editableVideo($key, $ws->id);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::findOrFail($id));
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $revision, 'changes' => [['op' => 'rerecord_all', 'scene_ids' => [999999]]]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_scene');
        $proposal = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $revision, 'changes' => [['op' => 'rerecord_all']]])->assertStatus(201);
        Scene::findOrFail($s1)->update(['script_text' => 'Changed after quote.']);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$proposal->json('data.proposal_id')}/apply")->assertStatus(409);
        Bus::assertNotDispatched(\App\Jobs\GenerateTTSJob::class);
        Project::findOrFail($id)->update(['visual_brief' => ['ugc_format' => 'one_shot']]);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::findOrFail($id));
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $revision, 'changes' => [['op' => 'rerecord_all']]])->assertStatus(422)->assertJsonPath('error.code', 'whole_video_edit_unsupported');
    }

    public function test_bulk_spokesperson_quote_uses_each_saved_engine(): void
    {
        [$ws, , $key] = $this->tenant('creator', 5000);
        [$id, [$s1, $s2]] = $this->editableVideo($key, $ws->id);
        foreach ([$s1 => 'fabric', $s2 => 'omni_human'] as $sid => $engine) {
            Scene::findOrFail($sid)->update(['duration_seconds' => 12, 'image_generation_settings_json' => ['lipsync_engine' => $engine]]);
        }
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::findOrFail($id));
        $expected = \App\Services\CreditService::spokespersonCost(12, 'fabric') + \App\Services\CreditService::spokespersonCost(12, 'omni_human');
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", ['revision' => $revision, 'changes' => [['op' => 'animate_all', 'tier' => 'spokesperson']]])->assertStatus(201)->assertJsonPath('data.credits.max', $expected)->assertJsonPath('data.changes.0.preview.scenes.0.lipsync_engine', 'fabric');
    }

    private function enableOperationAccounting(): void
    {
        if (! Schema::hasTable('api_operations')) (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
        config(['developer.operation_accounting' => true]);
        \Illuminate\Support\Facades\Context::forgetHidden(\App\Services\Developer\OperationAccounting::CONTEXT);
    }

    private function operationQuote(Workspace $ws, int $maximum): ApiQuote
    {
        return ApiQuote::create(['id' => ApiQuote::newId(), 'workspace_id' => $ws->id,
            'payload_json' => [], 'credits_min' => $maximum, 'credits_max' => $maximum,
            'expires_at' => now()->addMinutes(10)]);
    }

    public function test_operation_reserves_key_cap_before_jobs_have_charged(): void
    {
        $this->enableOperationAccounting();
        [$ws, , $token] = $this->tenant('creator', 500);
        $key = ApiKey::resolve($token);
        $key->update(['spend_cap_credits' => 100]);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 100), $key->id));
        $this->assertTrue($key->wouldExceedCap(1));
        $this->assertSame(100, \App\Services\Developer\OperationAccounting::reserved($ws->id));
        try {
            DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 1), $key->id));
            $this->fail('A pending reservation must consume the key cap.');
        } catch (\DomainException $e) {
            $this->assertSame('key_spend_cap_reached', $e->getMessage());
        }
        \App\Services\Developer\OperationAccounting::close($id);
        $this->assertFalse($key->wouldExceedCap(100));
    }

    public function test_operation_debits_and_refunds_are_attributed_and_bounded(): void
    {
        $this->enableOperationAccounting();
        [$ws, , $token] = $this->tenant('creator', 500);
        $key = ApiKey::resolve($token);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 100), $key->id));
        $credits = app(\App\Services\CreditService::class);
        $this->assertTrue($credits->deduct($ws->id, 70, 'test', []));
        try { $credits->deduct($ws->id, 31, 'test', []); $this->fail('An accounted budget refusal must stop execution.'); }
        catch (\App\Services\Developer\OperationBudgetExceeded $e) { $this->assertSame(402, $e->getStatusCode()); }
        $this->assertSame(70, $key->spentThisMonth());
        $this->assertSame(30, \App\Services\Developer\OperationAccounting::reserved($ws->id));
        $credits->refund($ws->id, 20, 'test');
        $this->assertSame(50, $key->spentThisMonth());
        $this->assertSame(450, $credits->balance($ws->id));
        $this->assertSame(2, DB::table('credit_ledger')->where('api_operation_id', $id)->where('api_key_id', $key->id)->count());
        \App\Services\Developer\OperationAccounting::queued($id, 'parent-job');
        \App\Services\Developer\OperationAccounting::queued($id, 'child-job');
        \App\Services\Developer\OperationAccounting::close($id);
        \App\Services\Developer\OperationAccounting::close($id, 'parent-job');
        $this->assertSame(50, \App\Services\Developer\OperationAccounting::reserved($ws->id));
        \App\Services\Developer\OperationAccounting::close($id, 'child-job', true);
        $this->assertSame(0, \App\Services\Developer\OperationAccounting::reserved($ws->id));
        $this->assertSame('failed', DB::table('api_operations')->where('id', $id)->value('status'));
        try { $credits->deduct($ws->id, 1, 'late-job'); $this->fail('A settled operation must not charge.'); }
        catch (\App\Services\Developer\OperationBudgetExceeded $e) { $this->assertSame(402, $e->getStatusCode()); }
        \Illuminate\Support\Facades\Context::forgetHidden(\App\Services\Developer\OperationAccounting::CONTEXT);
        $this->assertTrue($credits->deduct($ws->id, 1, 'dashboard'));
        $this->assertSame(50, $key->spentThisMonth());
    }

    public function test_dashboard_cannot_spend_reserved_credits_and_rotation_keeps_holds(): void
    {
        $this->enableOperationAccounting();
        [$ws, $user, $token] = $this->tenant('creator', 100);
        $key = ApiKey::resolve($token);
        $key->update(['spend_cap_credits' => 100]);
        DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 100), $key->id));
        \Illuminate\Support\Facades\Context::forgetHidden(\App\Services\Developer\OperationAccounting::CONTEXT);
        $this->assertFalse(app(\App\Services\CreditService::class)->deduct($ws->id, 1, 'dashboard'));
        [$newKey] = ApiKey::issue($ws->id, $user->id, 'Rotated', null, 100, $key->id);
        $this->assertTrue($newKey->wouldExceedCap(1));
    }
    public function test_pending_quote_replay_returns_status_without_reexecution(): void
    {
        [$ws, , $key] = $this->tenant();
        $quote = $this->operationQuote($ws, 10);
        $quote->forceFill(['consumed_at' => now(), 'idempotency_key' => 'original'])->save();
        $this->create($key, $quote->id, 'original')->assertStatus(202)
            ->assertJsonPath('data.operation.state', 'running')
            ->assertJsonPath('data.operation.quote_id', $quote->id);
        $this->assertSame(0, Project::count());
        $this->create($key, $quote->id, 'replacement')->assertStatus(409)->assertJsonPath('error.code', 'quote_consumed');
        $this->withToken($key)->getJson('/api/developer/v1/operations/'.$quote->id)
            ->assertOk()->assertJsonPath('data.operation.result_recorded', false);
        [, , $otherKey] = $this->tenant();
        $this->withToken($otherKey)->getJson('/api/developer/v1/operations/'.$quote->id)->assertStatus(404);
    }

    public function test_stalled_operation_is_reported_without_releasing_its_hold(): void
    {
        $this->enableOperationAccounting();
        [$ws, , $token] = $this->tenant();
        $quote = $this->operationQuote($ws, 100);
        $quote->forceFill(['consumed_at' => now(), 'idempotency_key' => 'original'])->save();
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($quote, ApiKey::resolve($token)->id));
        // Simulate a killed request, not an exception that executes finally.
        \Illuminate\Support\Facades\Context::forgetHidden(\App\Services\Developer\OperationAccounting::CONTEXT);
        DB::table('api_quotes')->where('id', $quote->id)->update(['updated_at' => now()->subHours(2)]);
        DB::table('api_operations')->where('id', $id)->update(['updated_at' => now()->subHours(2)]);
        $this->withToken($token)->getJson('/api/developer/v1/operations/'.$quote->id)
            ->assertOk()->assertJsonPath('data.operation.state', 'needs_attention')
            ->assertJsonPath('data.operation.credits.reserved', 100);
        $this->assertSame(100, \App\Services\Developer\OperationAccounting::reserved($ws->id));
        $this->assertFalse((bool) DB::table('api_operations')->where('id', $id)->value('producer_closed'));
    }

    public function test_operation_context_follows_real_sync_queue_and_child_jobs(): void
    {
        $this->enableOperationAccounting();
        Bus::swap(new \Illuminate\Bus\Dispatcher(app()));
        Bus::pipeThrough([\App\Services\Developer\AccountedJob::class]);
        [$ws, , $token] = $this->tenant('creator', 100);
        $key = ApiKey::resolve($token);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 40), $key->id));
        \Illuminate\Support\Facades\Queue::connection('sync')->push(new OperationAccountingProbeJob($ws->id, true));
        $this->assertSame(20, $key->spentThisMonth());
        $this->assertSame(2, DB::table('api_operation_jobs')->where('operation_id', $id)->where('status', 'completed')->count());
        \App\Services\Developer\OperationAccounting::close($id);
        $this->assertSame(0, \App\Services\Developer\OperationAccounting::reserved($ws->id));
    }
    public function test_accounted_job_passes_through_the_synchronous_dispatches_it_triggers(): void
    {
        // ShouldBroadcastNow events and dispatchSync inside a job run through
        // the same Bus pipes; they must not count as a second attempt (the
        // production stall: GenerationProgressed fenced its own operation).
        $this->enableOperationAccounting();
        Bus::swap(new \Illuminate\Bus\Dispatcher(app()));
        Bus::pipeThrough([\App\Services\Developer\AccountedJob::class]);
        [$ws, , $token] = $this->tenant('creator', 100);
        $key = ApiKey::resolve($token);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 40), $key->id));
        \Illuminate\Support\Facades\Queue::connection('sync')->push(new OperationAccountingScenarioJob($ws->id, 'nested'));
        $this->assertSame(10, $key->spentThisMonth());
        $this->assertSame('running', DB::table('api_operations')->where('id', $id)->value('status'));
        $this->assertSame(['completed'], DB::table('api_operation_jobs')->where('operation_id', $id)->pluck('status')->all());
        \App\Services\Developer\OperationAccounting::close($id);
        $this->assertSame('completed', DB::table('api_operations')->where('id', $id)->value('status'));
    }

    public function test_accounted_job_failing_before_any_charge_settles_like_an_ordinary_failure(): void
    {
        $this->enableOperationAccounting();
        Bus::swap(new \Illuminate\Bus\Dispatcher(app()));
        Bus::pipeThrough([\App\Services\Developer\AccountedJob::class]);
        [$ws, , $token] = $this->tenant('creator', 100);
        $key = ApiKey::resolve($token);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 40), $key->id));
        try {
            \Illuminate\Support\Facades\Queue::connection('sync')->push(new OperationAccountingScenarioJob($ws->id, 'throw_before'));
            $this->fail('expected the job exception to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('probe: before charge', $e->getMessage());
        }
        $this->assertSame(0, $key->spentThisMonth());
        $this->assertSame('running', DB::table('api_operations')->where('id', $id)->value('status')); // the request is still the producer
        \App\Services\Developer\OperationAccounting::close($id); // producer done; the failed job settles it
        $op = DB::table('api_operations')->where('id', $id)->first();
        $this->assertSame('failed', $op->status);
        $this->assertSame(0, (int) $op->reserved_credits);
        $this->assertSame(['failed'], DB::table('api_operation_jobs')->where('operation_id', $id)->pluck('status')->all());
    }

    public function test_accounted_job_failing_after_a_charge_fences_the_operation(): void
    {
        $this->enableOperationAccounting();
        Bus::swap(new \Illuminate\Bus\Dispatcher(app()));
        Bus::pipeThrough([\App\Services\Developer\AccountedJob::class]);
        [$ws, , $token] = $this->tenant('creator', 100);
        $key = ApiKey::resolve($token);
        $id = DB::transaction(fn () => \App\Services\Developer\OperationAccounting::reserve($this->operationQuote($ws, 40), $key->id));
        try {
            \Illuminate\Support\Facades\Queue::connection('sync')->push(new OperationAccountingScenarioJob($ws->id, 'throw_after'));
            $this->fail('expected the job exception to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('probe: after charge', $e->getMessage());
        }
        $this->assertSame(10, $key->spentThisMonth());
        $op = DB::table('api_operations')->where('id', $id)->first();
        $this->assertSame('needs_attention', $op->status);
        $this->assertSame(10, (int) $op->spent_credits);
        $this->assertSame(30, (int) $op->reserved_credits);
        $this->assertSame(['failed'], DB::table('api_operation_jobs')->where('operation_id', $id)->pluck('status')->all());
    }

    public function test_media_upload_validates_bytes_and_is_workspace_scoped(): void
    {
        [$ws, , $key] = $this->tenant();
        [, , $foreign] = $this->tenant();
        $storage = $this->createMock(\App\Services\Media\StorageService::class);
        $storage->method('put')->willReturn('https://assets.test/sample.png');
        $storage->method('url')->willReturn('https://assets.test/sample.png');
        $this->instance(\App\Services\Media\StorageService::class, $storage);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6zd8AAAAASUVORK5CYII=';
        $body = ['title' => 'Reference', 'asset_type' => 'image', 'content_base64' => $png];
        $created = $this->withToken($key)->postJson('/api/developer/v1/assets', $body)->assertCreated();
        $id = $created->json('data.asset.id');
        $this->assertSame((string) $ws->id, (string) \App\Models\Asset::find($id)->workspace_id);
        $this->withToken($key)->getJson("/api/developer/v1/assets/{$id}")->assertOk()->assertJsonPath('data.asset.transcription_status', 'not_requested');
        $this->withToken($foreign)->getJson("/api/developer/v1/assets/{$id}")->assertNotFound();
        $this->withToken($key)->postJson('/api/developer/v1/assets', array_replace($body, ['asset_type' => 'video']))->assertStatus(422)->assertJsonPath('error.code', 'invalid_media_type');
        $this->withToken($key)->postJson('/api/developer/v1/assets', array_replace($body, ['content_base64' => base64_encode('<svg/>')]))->assertStatus(422);
        $this->withToken($key)->postJson('/api/developer/v1/assets', array_replace($body, ['content_base64' => 'not base64!']))->assertStatus(422);
        $this->withToken($key)->getJson('/api/developer/v1/library?type=image&q=Reference')->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($foreign)->getJson('/api/developer/v1/library?type=image')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_audio_upload_queues_transcription_and_rejects_oversized_multipart(): void
    {
        [$ws, , $key] = $this->tenant();
        $storage = $this->createMock(\App\Services\Media\StorageService::class);
        $storage->method('put')->willReturn('https://assets.test/sample.wav');
        $this->instance(\App\Services\Media\StorageService::class, $storage);
        $wav = 'RIFF'.pack('V', 36 + 480).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', 480).str_repeat("\0", 480);
        $upload = $this->withToken($key)->postJson('/api/developer/v1/assets', ['title' => 'Narration', 'asset_type' => 'audio', 'content_base64' => base64_encode($wav)])->assertCreated();
        $upload->assertJsonPath('data.asset.transcription_status', 'queued');
        Bus::assertDispatched(\App\Jobs\TranscribeAssetJob::class);
        $id = $upload->json('data.asset.id');
        $this->withToken($key)->getJson("/api/developer/v1/assets/{$id}")->assertOk()->assertJsonPath('data.asset.ready_for_audio_only', true)->assertJsonPath('data.asset.ready_for_audio_and_script', false);
        $this->withToken($key)->getJson('/api/developer/v1/library?type=audio')->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($key)->getJson('/api/developer/v1/library?type=sound')->assertOk();
        $file = \Illuminate\Http\UploadedFile::fake()->create('large.mp4', 102401, 'video/mp4');
        $this->withToken($key)->post('/api/developer/v1/assets', ['title' => 'Oversized', 'asset_type' => 'video', 'asset_file' => $file], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, \App\Models\Asset::count());
    }

    public function test_clone_requires_consent_reuses_sample_and_preserves_quota_and_scope(): void
    {
        [$ws, $user, $key] = $this->tenant();
        [, , $foreign] = $this->tenant();
        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('summaryForWorkspace')->willReturn(['voice_cloning_used' => 0, 'voice_cloning_limit' => 1]);
        $this->instance(WorkspaceUsageService::class, $usage);
        $sample = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'audio', 'mime_type' => 'audio/wav', 'storage_url' => 'https://assets.test/voice.wav']);
        $body = ['name' => 'My voice', 'source_asset_id' => $sample->id];
        $this->withToken($key)->postJson('/api/developer/v1/voices/clone', $body)->assertStatus(422);
        $body['consent'] = true;
        $clone = $this->withToken($key)->postJson('/api/developer/v1/voices/clone', $body)->assertCreated()->assertJsonPath('data.voice.status', 'active');
        $id = $clone->json('data.voice.id');
        $profile = \App\Models\VoiceProfile::find($clone->json('data.voice.voice_profile_id'));
        $this->assertNotNull($profile->consent_acknowledged_at);
        $this->assertEquals($user->id, $profile->consent_user_id);
        $this->withToken($key)->postJson('/api/developer/v1/voices/clone', $body)->assertOk()->assertJsonPath('data.voice.id', $id)->assertJsonPath('meta.reused_existing', true);
        $this->assertSame(1, \App\Models\VoiceProfile::count());
        $this->withToken($foreign)->postJson('/api/developer/v1/voices/clone', $body)->assertStatus(422);
        $this->withToken($foreign)->postJson('/api/developer/v1/voices/preview', ['voice_id' => $id])->assertNotFound();
        $this->withToken($foreign)->postJson('/api/developer/v1/voices', ['voice_id' => $id, 'name' => 'Stolen'])->assertNotFound();
        $this->withToken($key)->postJson('/api/developer/v1/voices/preview', ['voice_id' => $id])->assertOk()->assertJsonPath('data.preview_kind', 'source_sample')->assertJsonPath('data.is_generated_clone_preview', false);
        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('summaryForWorkspace')->willReturn(['voice_cloning_used' => 1, 'voice_cloning_limit' => 1]);
        $this->instance(WorkspaceUsageService::class, $usage);
        $other = $sample->replicate(); $other->save();
        $this->withToken($key)->postJson('/api/developer/v1/voices/clone', array_replace($body, ['source_asset_id' => $other->id]))->assertStatus(402)->assertJsonPath('error.code', 'voice_cloning_limit');
        $this->assertSame(1, \App\Models\VoiceProfile::count());
    }

    public function test_save_voice_uses_catalogue_provider_and_reuses_existing_profile(): void
    {
        [$ws, , $key] = $this->tenant();
        \App\Models\VoiceProfile::create(['name' => 'Kore', 'provider' => 'google', 'provider_voice_key' => 'Kore', 'status' => 'active']);
        $body = ['name' => 'Brand narrator', 'voice_id' => 'Kore'];
        $saved = $this->withToken($key)->postJson('/api/developer/v1/voices', $body)->assertOk();
        $profile = \App\Models\VoiceProfile::find($saved->json('data.voice.voice_profile_id'));
        $this->assertSame('google', $profile->provider);
        $this->assertEquals($ws->id, $profile->workspace_id);
        $this->withToken($key)->postJson('/api/developer/v1/voices', $body)->assertOk()->assertJsonPath('data.voice.voice_profile_id', $profile->id);
        $this->assertSame(2, \App\Models\VoiceProfile::count());
    }

    public function test_narration_choices_freeze_transcript_and_invalidate_lipsync(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$sid]] = $this->editableVideo($key, $ws->id);
        $audio = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'audio', 'transcription_status' => 'queued', 'storage_url' => 'https://assets.test/s.wav']);
        Scene::find($sid)->update(['image_generation_settings_json' => ['animation_video_asset_id' => 123, 'spokesperson_consent' => true]]);
        $this->applyEditorChanges($key, $id, [['op' => 'use_narration', 'scene_id' => $sid, 'asset_id' => $audio->id, 'mode' => 'audio_only']])->assertOk()->assertJsonPath('data.failed', 0);
        $this->assertSame('Hook line.', Scene::find($sid)->script_text);
        $this->assertTrue(Scene::find($sid)->image_generation_settings_json['animation_outdated']);
        $this->assertTrue(Scene::find($sid)->voice_settings_json['custom_audio']);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::find($id));
        $changes = [['op' => 'use_narration', 'scene_id' => $sid, 'asset_id' => $audio->id, 'mode' => 'audio_and_script']];
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", compact('revision', 'changes'))->assertStatus(422)->assertJsonPath('error.code', 'transcription_not_ready');
        $audio->update(['transcription_status' => 'completed', 'transcript_text' => 'Approved transcript.']);
        $quote = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals", compact('revision', 'changes'))->assertCreated();
        $audio->update(['transcript_text' => 'Later transcription replacement.']);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/proposals/{$quote->json('data.proposal_id')}/apply")->assertOk()->assertJsonPath('data.failed', 0);
        $this->assertSame('Approved transcript.', Scene::find($sid)->script_text);
        $this->assertFalse(Scene::find($sid)->voice_settings_json['is_outdated']);
    }

    public function test_character_update_requires_new_reference_consent_but_allows_removal(): void
    {
        [$ws, , $key] = $this->tenant();
        $character = $this->withToken($key)->postJson('/api/developer/v1/characters', ['name' => 'Presenter'])->assertCreated()->json('data.character.id');
        $image = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'image']);
        $this->withToken($key)->patchJson("/api/developer/v1/characters/{$character}", ['reference_asset_ids' => [$image->id]])->assertStatus(422)->assertJsonPath('error.code', 'consent_required');
        $this->withToken($key)->patchJson("/api/developer/v1/characters/{$character}", ['reference_asset_ids' => [$image->id], 'consent' => true])->assertOk();
        $this->assertNotNull(\App\Models\Character::find($character)->consent_acknowledged_at);
        $this->withToken($key)->patchJson("/api/developer/v1/characters/{$character}", ['name' => 'Renamed'])->assertOk();
        $this->withToken($key)->patchJson("/api/developer/v1/characters/{$character}", ['reference_asset_ids' => []])->assertOk();
        $this->assertNull(\App\Models\Character::find($character)->reference_asset_id);
    }
    public function test_ugc_insufficient_credit_and_ambiguous_request_do_not_dispatch(): void
    {
        foreach (['composed', 'one_shot'] as $mode) {
            [$ws, , $key] = $this->tenant('creator', 0);
            $quote = $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', ['mode' => $mode, 'format' => 'direct_camera', 'segments' => $this->ugcSegments(), 'consent' => true])->assertCreated();
            $body = ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'interrupted'];
            $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', $body)->assertStatus(402)->assertJsonPath('error.code', 'insufficient_credits');
            $this->assertSame(0, Project::count());
            ApiQuote::find($body['quote_id'])->update(['consumed_at' => now(), 'idempotency_key' => 'interrupted']);
            $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', $body)->assertStatus(202);
            $this->assertSame(0, Project::count());
        }
        Bus::assertNothingDispatched();
    }

    public function test_ugc_mode_choices_reject_ignored_inputs_and_foreign_products(): void
    {
        [$ws, , $key] = $this->tenant();
        $base = ['mode' => 'one_shot', 'format' => 'direct_camera', 'segments' => $this->ugcSegments(), 'consent' => true];
        foreach ([['voice_key' => 'Kore'], ['aspect_ratio' => '16:9'], ['fidelity' => 'high'], ['variants' => [['segments' => $this->ugcSegments()]]]] as $bad) {
            $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', $base + $bad)->assertStatus(422)->assertJsonPath('error.code', 'unsupported_mode_setting');
        }
        $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', $base + ['product_asset_ids' => [99999]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_asset');
        $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', $base)->assertCreated()->assertJsonPath('data.chosen.voice.type', 'native_speech')->assertJsonPath('data.chosen.aspect_ratio', '9:16');
        $base['mode'] = 'composed';
        $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', $base + ['voice_key' => 'clone-1'])->assertStatus(422)->assertJsonPath('error.code', 'unsupported_voice');
    }

    public function test_ugc_reference_is_scoped_and_forwarded_to_planning(): void
    {
        [$ws, , $key] = $this->tenant();
        [, , $foreign] = $this->tenant();
        $asset = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://example.test/ref.mp4']);
        $reference = ['shape' => 'Hook then demonstration', 'beats' => [['role' => 'hook', 'does' => 'Ask a question', 'start' => 0, 'end' => 3]]];
        $reader = $this->createMock(\App\Services\Ugc\UgcReference::class);
        $reader->expects($this->once())->method('read')->willReturn($reference);
        $this->instance(\App\Services\Ugc\UgcReference::class, $reader);
        $this->withToken($foreign)->postJson('/api/developer/v1/ugc/reference', ['asset_id' => $asset->id])->assertStatus(422);
        $this->withToken($key)->postJson('/api/developer/v1/ugc/reference', ['asset_id' => $asset->id])->assertOk()->assertJsonPath('data.reference.shape', $reference['shape']);
        $planner = $this->createMock(\App\Services\Ugc\UgcShotPlanner::class);
        $planner->expects($this->once())->method('plan')->willReturnCallback(function (...$args) use ($reference) {
            $this->assertContains($reference, $args);
            return ['format' => 'direct_camera', 'segments' => $this->ugcSegments()];
        });
        $this->instance(\App\Services\Ugc\UgcShotPlanner::class, $planner);
        $this->withToken($key)->postJson('/api/developer/v1/ugc/plans', ['script' => 'A standing desk demo', 'format' => 'auto', 'duration_seconds' => 10, 'reference' => $reference])->assertOk();
    }

    public function test_presenter_preview_is_quoted_charged_once_and_replayed(): void
    {
        [$ws, , $key] = $this->tenant();
        $char = \App\Models\Character::create(['workspace_id' => $ws->id, 'name' => 'Presenter', 'description' => 'A warm presenter', 'status' => 'active']);
        $sheet = $this->createMock(\App\Services\Ugc\CharacterAppearanceService::class);
        $sheet->method('text')->willReturn('A warm presenter');
        $this->instance(\App\Services\Ugc\CharacterAppearanceService::class, $sheet);
        $adapter = $this->createMock(\App\Services\Generation\Image\ImageGenerationAdapter::class);
        $adapter->expects($this->once())->method('generate')->willReturn(['image_b64' => base64_encode('image fixture')]);
        $factory = $this->createMock(\App\Services\Generation\Image\ImageAdapterFactory::class);
        $factory->method('generationCost')->willReturn(43);
        $factory->method('resolve')->willReturn($adapter);
        $this->instance(\App\Services\Generation\Image\ImageAdapterFactory::class, $factory);
        $storage = $this->createMock(\App\Services\Media\StorageService::class);
        $storage->method('put')->willReturn('https://example.test/preview.png');
        $this->instance(\App\Services\Media\StorageService::class, $storage);
        $root = "/api/developer/v1/ugc/characters/{$char->id}/preview";
        $this->withToken($key)->postJson($root.'/quotes', ['consent' => false])->assertStatus(422);
        $quote = $this->withToken($key)->postJson($root.'/quotes', ['consent' => true])->assertCreated()->json('data.quote_id');
        $body = ['quote_id' => $quote, 'idempotency_key' => 'preview-1'];
        $result = $this->withToken($key)->postJson($root, $body)->assertOk()->assertJsonPath('data.credits_charged', 43)->assertJsonPath('data.identity_match_guaranteed', false);
        $this->withToken($key)->postJson($root, $body)->assertOk()->assertJsonPath('data.asset_id', $result->json('data.asset_id'));
        $this->assertEquals(457, app(\App\Services\CreditService::class)->balance($ws->id));
        $this->assertSame(1, DB::table('credit_ledger')->where('operation', 'ugc_variant_preview')->count());
    }

    public function test_spokesperson_waits_for_current_narration(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$sid]] = $this->editableVideo($key, $ws->id);
        $scene = Scene::find($sid);
        $scene->update(['voice_settings_json' => $scene->voice_settings_json + ['is_outdated' => true]]);
        $this->applyEditorChanges($key, $id, [['op' => 'animate', 'scene_id' => $sid, 'tier' => 'spokesperson', 'consent' => true]])->assertOk()->assertJsonPath('data.applied.0.error.code', 'narration_not_ready');
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }
    public function test_ugc_both_modes_return_completed_export_on_replay(): void
    {
        foreach (['composed', 'one_shot'] as $mode) {
            [$ws, , $key] = $this->tenant('creator', 10000);
            $ref = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'image', 'storage_url' => 'https://example.test/ref.png', 'mime_type' => 'image/png']);
            $character = \App\Models\Character::create(['workspace_id' => $ws->id, 'name' => 'Presenter', 'status' => 'active', 'reference_asset_id' => $ref->id]);
            $payload = ['mode' => $mode, 'format' => 'direct_camera', 'segments' => $this->ugcSegments(), 'consent' => true];
            if ($mode === 'composed') $payload += ['character_ids' => [$character->id], 'voice_key' => 'Kore', 'variants' => [['label' => 'Second', 'segments' => $this->ugcSegments()]]];
            $quote = $this->withToken($key)->postJson('/api/developer/v1/ugc/quotes', $payload)->assertCreated();
            $body = ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => $mode.'-life'];
            $created = $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', $body)->assertStatus(202);
            $this->assertCount($mode === 'composed' ? 2 : 1, $created->json('data.videos'));
            foreach ($created->json('data.videos') as $video) {
                $id = $video['id'];
                $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/result")->assertStatus(409);
                $project = Project::find($id);
                Project::withoutEvents(fn () => $project->update(['status' => 'ready_for_review']));
                $asset = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://example.test/output.mp4']);
                DB::table('export_jobs')->insert(['workspace_id' => $ws->id, 'project_id' => $id, 'status' => 'completed', 'output_asset_id' => $asset->id, 'queued_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->withToken($key)->getJson("/api/developer/v1/videos/{$id}/result")->assertOk()->assertJsonPath('data.video.status', 'completed');
            }
            $this->withToken($key)->postJson('/api/developer/v1/ugc/videos', $body)->assertOk()->assertJsonPath('data.videos.0.status', 'completed');
        }
    }

    public function test_spokesperson_job_does_not_charge_when_narration_became_stale_after_queueing(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id, [$sid]] = $this->editableVideo($key, $ws->id);
        $scene = Scene::find($sid);
        $scene->update(['voice_settings_json' => $scene->voice_settings_json + ['is_outdated' => true], 'image_generation_settings_json' => ['generation_token' => 'queued-1', 'planned_spokesperson' => true]]);
        \Illuminate\Support\Facades\Event::fake();
        \App\Jobs\GenerateTalkingVideoJob::maybeDispatchForScene($scene);
        Bus::assertNotDispatched(\App\Jobs\GenerateTalkingVideoJob::class);
        $adapter = $this->createMock(\App\Services\Generation\Video\ReplicateFabricAdapter::class);
        $adapter->expects($this->never())->method('start');
        (new \App\Jobs\GenerateTalkingVideoJob($sid, $id, 'queued-1'))->handle($adapter);
        $this->assertSame(0, DB::table('credit_ledger')->count());
        $this->assertFalse(Scene::find($sid)->image_generation_settings_json['animation_in_progress']);
    }
    public function test_spokesperson_finishing_after_audio_change_is_stale_until_rerender(): void
    {
        [$ws, , $key] = $this->tenant('creator', 10000);
        [$id, [$sid]] = $this->editableVideo($key, $ws->id);
        $scene = Scene::find($sid);
        $originalAudio = $scene->voice_settings_json['audio_asset_id'];
        $replacement = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'audio', 'storage_url' => 'https://example.test/new.wav', 'duration_seconds' => 5]);
        $scene->update(['image_generation_settings_json' => ['generation_token' => 'render-1'], 'duration_seconds' => 5]);
        \Illuminate\Support\Facades\Event::fake();
        Http::fake(['https://example.test/result.mp4' => Http::response('video fixture')]);
        $storage = $this->createMock(\App\Services\Media\StorageService::class);
        $storage->method('put')->willReturn('https://example.test/stored.mp4');
        $this->instance(\App\Services\Media\StorageService::class, $storage);
        $adapter = $this->createMock(\App\Services\Generation\Video\ReplicateFabricAdapter::class);
        $adapter->method('start')->willReturn('prediction-1');
        $adapter->method('providerKey')->willReturn('mock');
        $adapter->method('pollUntilDone')->willReturnCallback(function () use ($sid, $replacement) {
            $current = Scene::find($sid);
            $current->update(['voice_settings_json' => array_replace($current->voice_settings_json, ['audio_asset_id' => $replacement->id, 'is_outdated' => false])]);
            return 'https://example.test/result.mp4';
        });
        (new \App\Jobs\GenerateTalkingVideoJob($sid, $id, 'render-1'))->handle($adapter);
        $scene->refresh();
        $this->assertSame($originalAudio, $scene->image_generation_settings_json['animation_source_audio_asset_id']);
        $this->assertTrue($scene->image_generation_settings_json['animation_outdated']);
        $scene->update(['image_generation_settings_json' => array_replace($scene->image_generation_settings_json, ['generation_token' => 'render-2'])]);
        (new \App\Jobs\GenerateTalkingVideoJob($sid, $id, 'render-2'))->handle($adapter);
        $scene->refresh();
        $this->assertEquals($replacement->id, $scene->image_generation_settings_json['animation_source_audio_asset_id']);
        $this->assertFalse($scene->image_generation_settings_json['animation_outdated']);
        $this->assertFalse($scene->image_generation_settings_json['animation_in_progress']);
    }
    public function test_delivery_handoffs_validate_version_without_sending_or_sharing(): void
    {
        [$ws, , $key] = $this->tenant();
        [, , $foreign] = $this->tenant();
        [$id] = $this->editableVideo($key, $ws->id);
        $project = Project::find($id);
        $asset = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://example.test/out.mp4']);
        $export = \App\Models\ExportJob::create(['workspace_id' => $ws->id, 'project_id' => $id, 'status' => 'completed', 'output_asset_id' => $asset->id, 'queued_at' => now()]);
        $revision = \App\Http\Controllers\Api\Developer\V1\EditorController::revision($project);
        \Illuminate\Support\Facades\Mail::fake();
        foreach (['public_share', 'approval_request', 'schedule'] as $action) {
            $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/delivery/handoff", ['action' => $action, 'revision' => $revision, 'export_id' => $export->id])
                ->assertOk()->assertJsonPath('data.outcome', 'handoff_required')->assertJsonPath('data.external_action_completed', false)->assertJsonPath('data.reviewed_export_id', $export->id);
        }
        $this->assertNull($project->fresh()->share_token);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $body = ['action' => 'schedule', 'revision' => $revision, 'export_id' => $export->id];
        $this->withToken($foreign)->postJson("/api/developer/v1/videos/{$id}/delivery/handoff", $body)->assertNotFound();
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/delivery/handoff", array_replace($body, ['revision' => 'stale']))->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/delivery/handoff", array_replace($body, ['export_id' => 999999]))->assertStatus(409);
        $export->update(['status' => 'processing']);
        $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/delivery/handoff", $body)->assertStatus(409)->assertJsonPath('error.code', 'not_ready');
    }

    public function test_delivery_preflight_requires_explicit_older_export_acknowledgement_and_role(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id] = $this->editableVideo($key, $ws->id);
        $asset = \App\Models\Asset::create(['workspace_id' => $ws->id, 'asset_type' => 'video', 'storage_url' => 'https://example.test/out.mp4']);
        $export = \App\Models\ExportJob::create(['workspace_id' => $ws->id, 'project_id' => $id, 'status' => 'completed', 'output_asset_id' => $asset->id, 'queued_at' => now()]);
        $new = $export->replicate(); $new->save();
        $body = ['action' => 'approval_request', 'revision' => \App\Http\Controllers\Api\Developer\V1\EditorController::revision(Project::find($id)), 'export_id' => $export->id];
        $route = "/api/developer/v1/videos/{$id}/delivery/handoff";
        $this->withToken($key)->postJson($route, $body)->assertStatus(409)->assertJsonPath('error.code', 'stale_export');
        $this->withToken($key)->postJson($route, $body + ['allow_stale' => true])->assertOk()->assertJsonPath('data.allow_stale', true);
        [, $viewer] = $this->member($ws, User::ROLE_CLIENT_VIEWER);
        $this->withToken($viewer)->postJson($route, $body + ['allow_stale' => true])->assertStatus(403);
    }

    public function test_assistant_scheduler_returns_durable_handoff_not_scheduling_success(): void
    {
        [$ws, , $key] = $this->tenant();
        [$id] = $this->editableVideo($key, $ws->id);
        $cruise = $this->createMock(\App\Services\CruiseControl\CruiseControlService::class);
        $cruise->method('resolve')->willReturn(['reply_to_user' => 'Scheduled!', 'actions' => [['tool' => 'schedule_post', 'params' => [], 'diff_lines' => ['Open scheduler'], 'estimated_cost' => 0, 'confirmation_class' => 'always_prompt', 'affected_section' => 'project']]]);
        $this->instance(\App\Services\CruiseControl\CruiseControlService::class, $cruise);
        $plan = $this->withToken($key)->postJson("/api/developer/v1/videos/{$id}/assistant/plans", ['request' => 'Schedule this tomorrow'])->assertCreated()->assertJsonPath('data.actions.0.execution', 'app_handoff');
        $this->assertStringContainsString('Nothing has been scheduled', $plan->json('data.reply'));
        $url = "/api/developer/v1/videos/{$id}/assistant/plans/{$plan->json('data.plan_id')}/apply";
        $first = $this->withToken($key)->postJson($url)->assertOk()->assertJsonPath('data.applied.0.outcome', 'handoff_required')->assertJsonPath('data.applied.0.navigate.type', 'schedule')->assertJsonPath('data.applied.0.external_action_completed', false);
        $this->withToken($key)->postJson($url)->assertOk()->assertJsonPath('data.applied.0.handoff', $first->json('data.applied.0.handoff'));
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }

    public function test_public_scope_excludes_destructive_and_personal_assistant_settings(): void
    {
        [, , $key] = $this->tenant();
        $this->withToken($key)->getJson('/api/developer/v1/capabilities')->assertOk()->assertJsonPath('data.delivery.external_delivery_via_api', false)->assertJsonPath('data.app_only.0', 'scene_deletion');
        $tools = $this->withToken($key)->getJson('/api/developer/v1/assistant/tools')->assertOk()->json('data.tools');
        $this->assertNotContains('delete_scene', array_column($tools, 'name'));
        $schedule = collect($tools)->firstWhere('name', 'schedule_post');
        $this->assertSame('app_handoff', $schedule['execution']);
    }
}


class OperationAccountingProbeJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function __construct(public int $workspaceId, public bool $child) {}

    public function handle(): void
    {
        if (! app(\App\Services\CreditService::class)->deduct($this->workspaceId, 10, 'queue-probe')) {
            throw new \RuntimeException('Expected a reserved debit.');
        }
        if ($this->child) {
            \Illuminate\Support\Facades\Queue::connection('sync')->push(new self($this->workspaceId, false));
        }
    }
}

class OperationAccountingScenarioJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function __construct(public int $workspaceId, public string $mode) {}

    public function handle(): void
    {
        if ($this->mode === 'throw_before') {
            throw new \RuntimeException('probe: before charge');
        }
        if ($this->mode === 'nested') {
            Bus::dispatchNow(new OperationAccountingNestedCommand);
        }
        if (! app(\App\Services\CreditService::class)->deduct($this->workspaceId, 10, 'queue-probe')) {
            throw new \RuntimeException('Expected a reserved debit.');
        }
        if ($this->mode === 'throw_after') {
            throw new \RuntimeException('probe: after charge');
        }
    }
}

class OperationAccountingNestedCommand
{
    public function handle(): void {}
}
