<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuthSession;
use App\Models\OAuth\OAuthGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\JwtService;
use App\Services\WorkspaceUsageService;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/**
 * OAuth for MCP connectors, end to end: a public client registers, a user
 * approves it for a workspace, the code is exchanged with PKCE, and the
 * access token is exactly a hidden API key — reaching the developer
 * namespace and nothing else, ended by refresh replay, revocation, key
 * revocation or the key's own expiry.
 *
 * Deliberately uses a fresh in-memory DB, never the configured application database.
 */
class OAuthFlowTest extends TestCase
{
    use BuildsDeveloperSchema;

    private const REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'oauth_test', 'database.connections.oauth_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('oauth_test');
        config(['cache.default' => 'array', 'app.frontend_url' => 'https://app.test', 'developer.oauth.issuer' => 'https://app.test', 'services.posthog.key' => '']);
        Bus::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('get')->andReturn(null);
        $this->buildDeveloperSchema();

        $usage = $this->createMock(WorkspaceUsageService::class);
        $usage->method('hasExceededApiBudget')->willReturn(false);
        $usage->method('exportsRemaining')->willReturn(10);
        $this->instance(WorkspaceUsageService::class, $usage);
    }

    /** @return array{0: Workspace, 1: User} */
    private function tenant(string $tier = 'creator'): array
    {
        $ws = Workspace::query()->create(['name' => 'Acme', 'plan_tier' => $tier, 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 500]);
        $u = User::query()->create(['email' => uniqid().'@acme.test', 'name' => 'Owner', 'role' => 'owner', 'status' => 'active']);
        $u->forceFill(['workspace_id' => $ws->getKey()])->save();
        DB::table('workspace_memberships')->insert(['workspace_id' => $ws->id, 'user_id' => $u->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

        return [$ws->fresh(), $u->fresh()];
    }

    private function sessionToken(User $u, Workspace $ws): string
    {
        $session = AuthSession::query()->create(['user_id' => $u->getKey(), 'workspace_id' => $ws->getKey()]);

        return app(JwtService::class)->issue($u, $ws, $session);
    }

    private function registerClient(): string
    {
        return $this->postJson('/api/v1/oauth/register', ['client_name' => 'ChatGPT', 'redirect_uris' => [self::REDIRECT]])
            ->assertStatus(201)->assertJsonPath('token_endpoint_auth_method', 'none')->json('client_id');
    }

    /** @return array{verifier: string, challenge: string} */
    private function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /** @return array<string, string> */
    private function authParams(string $clientId, string $challenge, string $state = 'xyz'): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'videos'];
    }

    /** Runs register → approve → exchange and returns the token response plus the verifier/client. */
    private function connect(User $u, Workspace $ws): array
    {
        $clientId = $this->registerClient();
        $pkce = $this->pkce();
        $decision = $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/decide', $this->authParams($clientId, $pkce['challenge']) + ['decision' => 'approve', 'workspace_id' => $ws->id])->assertOk();
        parse_str((string) parse_url($decision->json('data.redirect_url'), PHP_URL_QUERY), $q);
        $this->assertSame('xyz', $q['state']);
        $tokens = $this->post('/api/v1/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $clientId, 'code_verifier' => $pkce['verifier']])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('scope', 'videos')->json();

        return ['tokens' => $tokens, 'client_id' => $clientId, 'code' => $q['code'], 'verifier' => $pkce['verifier']];
    }

    private function capabilities(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/developer/v1/capabilities');
    }

    public function test_discovery_document_points_at_the_spa_consent_page_and_api_endpoints(): void
    {
        $this->get('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', 'https://app.test')
            ->assertJsonPath('authorization_endpoint', 'https://app.test/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://app.test/api/v1/oauth/token')
            ->assertJsonPath('registration_endpoint', 'https://app.test/api/v1/oauth/register')
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
    }

    public function test_registration_accepts_public_clients_with_safe_redirects_only(): void
    {
        $this->postJson('/api/v1/oauth/register', ['client_name' => 'Bad', 'redirect_uris' => ['http://evil.example/cb']])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/api/v1/oauth/register', ['client_name' => 'Bad', 'redirect_uris' => ['https://ok.example/cb#frag']])->assertStatus(400);
        $this->postJson('/api/v1/oauth/register', ['client_name' => 'Secretive', 'redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
        $this->postJson('/api/v1/oauth/register', ['client_name' => 'Dev', 'redirect_uris' => ['http://localhost:6274/oauth/callback']])->assertStatus(201);
        $this->assertStringStartsWith('wyvc_', $this->registerClient());
    }

    public function test_consent_context_lists_only_workspaces_the_user_may_connect(): void
    {
        [$ws, $u] = $this->tenant();
        [$free] = $this->tenant('free');
        DB::table('workspace_memberships')->insert(['workspace_id' => $free->id, 'user_id' => $u->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $clientId = $this->registerClient();

        $ctx = $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/context', $this->authParams($clientId, $this->pkce()['challenge']))->assertOk();
        $this->assertSame('ChatGPT', $ctx->json('data.client.name'));
        $this->assertSame([$ws->id, $free->id], array_column($ctx->json('data.workspaces'), 'id'), 'every plan has API access; both admin-or-owner workspaces are offered');

        $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/context', $this->authParams('wyvc_nope', $this->pkce()['challenge']))->assertStatus(422)->assertJsonPath('error.code', 'invalid_client');
        $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/context', ['redirect_uri' => 'https://other.example/cb'] + $this->authParams($clientId, $this->pkce()['challenge']))->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
        $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/context', ['code_challenge' => 'short'] + $this->authParams($clientId, 'x'))->assertStatus(422);
    }

    public function test_approval_creates_a_hidden_key_and_the_token_acts_as_it(): void
    {
        [$ws, $u] = $this->tenant();
        $c = $this->connect($u, $ws);
        $access = $c['tokens']['access_token'];
        $this->assertStringStartsWith('wyv_oat_', $access);
        $this->assertStringStartsWith('wyv_ort_', $c['tokens']['refresh_token']);
        $this->assertSame(3600, $c['tokens']['expires_in']);

        $this->capabilities($access)->assertOk()->assertJsonPath('data.plan', 'creator')->assertJsonPath('data.key.name', 'ChatGPT');
        $this->withToken($access)->getJson('/api/v1/projects')->assertStatus(403)->assertJsonPath('error.code', 'api_key_forbidden_path');
        $this->withToken($access)->getJson('/api/v1/api-keys')->assertStatus(403);

        $key = ApiKey::query()->where('workspace_id', $ws->id)->firstOrFail();
        $this->assertSame('ChatGPT', $key->name);
        $this->assertEqualsWithDelta(90, now()->diffInDays($key->expires_at), 1);
        $grant = OAuthGrant::query()->firstOrFail();
        $this->assertSame($key->getKey(), (int) $grant->api_key_id);

        // The owner sees the connection in the key list, named after the app.
        $this->withToken($this->sessionToken($u, $ws))->getJson('/api/v1/api-keys')->assertOk()
            ->assertJsonPath('data.api_keys.0.connected_app', 'ChatGPT')->assertJsonPath('data.api_keys.0.name', 'ChatGPT');

        // A video can be made with it, attributed to the hidden key.
        $quote = $this->withToken($access)->postJson('/api/developer/v1/quotes', ['source_type' => 'prompt', 'content' => 'Three reasons a standing desk pays for itself within a month.', 'visual_mode' => 'stock', 'duration_seconds' => 30])->assertStatus(201);
        $video = $this->withToken($access)->postJson('/api/developer/v1/videos', ['quote_id' => $quote->json('data.quote_id'), 'idempotency_key' => 'k1'])->assertStatus(202);
        $this->assertSame($key->getKey(), (int) \App\Models\Project::query()->findOrFail($video->json('data.video.id'))->api_key_id);
    }

    public function test_code_exchange_enforces_pkce_single_use_and_redirect_match(): void
    {
        [$ws, $u] = $this->tenant();
        $clientId = $this->registerClient();
        $pkce = $this->pkce();
        $decision = $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/decide', $this->authParams($clientId, $pkce['challenge']) + ['decision' => 'approve', 'workspace_id' => $ws->id])->assertOk();
        parse_str((string) parse_url($decision->json('data.redirect_url'), PHP_URL_QUERY), $q);

        $base = ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $clientId];
        $this->post('/api/v1/oauth/token', ['code_verifier' => 'wrong-verifier-wrong-verifier-wrong-verifier-wrong'] + $base)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->post('/api/v1/oauth/token', ['redirect_uri' => 'https://other.example/cb', 'code_verifier' => $pkce['verifier']] + $base)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->post('/api/v1/oauth/token', ['grant_type' => 'password'] + $base)->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

        $ok = $this->post('/api/v1/oauth/token', ['code_verifier' => $pkce['verifier']] + $base)->assertOk();
        // Replaying a used code is an attack signal: the grant is ended.
        $this->post('/api/v1/oauth/token', ['code_verifier' => $pkce['verifier']] + $base)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->capabilities($ok->json('access_token'))->assertStatus(401);
    }

    public function test_refresh_rotates_and_a_replayed_refresh_token_revokes_the_connection(): void
    {
        [$ws, $u] = $this->tenant();
        $c = $this->connect($u, $ws);
        $first = $c['tokens'];

        $second = $this->post('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $c['client_id']])->assertOk()->json();
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->capabilities($second['access_token'])->assertOk();
        $this->capabilities($first['access_token'])->assertOk('the old access token lives until it expires');

        $this->post('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertNotNull(OAuthGrant::query()->firstOrFail()->revoked_at);
        $this->capabilities($second['access_token'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');
        $this->assertNotNull(ApiKey::query()->firstOrFail()->revoked_at, 'the hidden key goes with the grant');
    }

    public function test_expired_access_tokens_and_revocation_end_access(): void
    {
        [$ws, $u] = $this->tenant();
        $c = $this->connect($u, $ws);
        $access = $c['tokens']['access_token'];

        \App\Models\OAuth\OAuthToken::query()->update(['access_expires_at' => now()->subMinute()]);
        $this->capabilities($access)->assertStatus(401)->assertHeader('WWW-Authenticate');
        // Refresh still works: only the access token aged out.
        $fresh = $this->post('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $c['tokens']['refresh_token']])->assertOk()->json('access_token');
        $this->capabilities($fresh)->assertOk();

        $this->post('/api/v1/oauth/revoke', ['token' => $fresh])->assertOk();
        $this->capabilities($fresh)->assertStatus(401);
        $this->post('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $c['tokens']['refresh_token']])->assertStatus(400);
    }

    public function test_revoking_the_key_from_the_dashboard_ends_the_connection_and_reapproval_starts_a_new_one(): void
    {
        [$ws, $u] = $this->tenant();
        $c = $this->connect($u, $ws);
        $keyId = ApiKey::query()->firstOrFail()->getKey();
        $this->withToken($this->sessionToken($u, $ws))->deleteJson('/api/v1/api-keys/'.$keyId)->assertOk();
        $this->capabilities($c['tokens']['access_token'])->assertStatus(401);
        $this->post('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $c['tokens']['refresh_token']])->assertStatus(400);

        $again = $this->connect($u, $ws);
        $this->capabilities($again['tokens']['access_token'])->assertOk();
        $this->assertSame(1, ApiKey::query()->whereNull('revoked_at')->count());
        $this->assertSame(1, OAuthGrant::query()->whereNull('revoked_at')->count());
    }

    public function test_only_owners_and_admins_can_approve_and_deny_redirects_with_an_error(): void
    {
        [$ws, $u] = $this->tenant();
        $editor = User::query()->create(['email' => 'ed@acme.test', 'name' => 'Ed', 'role' => 'owner', 'status' => 'active']);
        $home = Workspace::query()->create(['name' => 'Home', 'plan_tier' => 'free', 'status' => 'active']);
        $editor->forceFill(['workspace_id' => $home->getKey()])->save();
        DB::table('workspace_memberships')->insert(['workspace_id' => $ws->id, 'user_id' => $editor->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        $clientId = $this->registerClient();
        $params = $this->authParams($clientId, $this->pkce()['challenge']);

        $this->withToken($this->sessionToken($editor->fresh(), $ws))->postJson('/api/v1/oauth/authorize/decide', $params + ['decision' => 'approve', 'workspace_id' => $ws->id])->assertStatus(403)->assertJsonPath('error.code', 'access_denied');
        $this->assertSame(0, ApiKey::query()->count());

        $deny = $this->withToken($this->sessionToken($u, $ws))->postJson('/api/v1/oauth/authorize/decide', $params + ['decision' => 'deny'])->assertOk();
        $this->assertStringContainsString('error=access_denied', $deny->json('data.redirect_url'));
        $this->assertStringContainsString('state=xyz', $deny->json('data.redirect_url'));
        $this->assertSame(0, ApiKey::query()->count());

        $this->flushHeaders()->postJson('/api/v1/oauth/authorize/decide', $params + ['decision' => 'approve', 'workspace_id' => $ws->id])->assertStatus(401);
    }
}
