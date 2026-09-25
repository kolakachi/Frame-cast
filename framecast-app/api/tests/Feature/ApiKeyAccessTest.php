<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/**
 * API keys: who may hold one, and what it may do.
 *
 * A key is long-lived and usually ends up pasted into a third-party tool, so
 * the properties that matter are: the secret is never stored or recoverable,
 * the plan is checked on every call rather than at issue time, and the key
 * cannot reach billing, admin, auth or key management — a credential must not
 * be able to change what the account costs or mint more of itself.
 */
class ApiKeyAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'apikey_test', 'database.connections.apikey_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('apikey_test');
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('name'); $t->string('prefix'); $t->string('token_hash');
            $t->timestamp('last_used_at')->nullable(); $t->timestamp('revoked_at')->nullable(); $t->timestamps();
        });
    }

    public function test_the_secret_is_never_stored(): void
    {
        [$key, $plain] = ApiKey::issue(1, 1, 'ChatGPT plugin');

        $row = DB::table('api_keys')->find($key->getKey());
        $this->assertStringNotContainsString($plain, json_encode($row),
            'the plaintext key must not appear anywhere in the row');
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertStringStartsWith('wyv_live_', $plain);
    }

    public function test_a_valid_key_resolves_and_a_tampered_one_does_not(): void
    {
        [$key, $plain] = ApiKey::issue(7, 3, 'ok');

        $this->assertSame($key->getKey(), ApiKey::resolve($plain)?->getKey());
        $this->assertNull(ApiKey::resolve($plain.'x'), 'a modified token must not resolve');
        $this->assertNull(ApiKey::resolve('wyv_live_'.str_repeat('a', 40)), 'a guessed token must not resolve');
        $this->assertNull(ApiKey::resolve('some-jwt-looking-string'), 'a non-key must not be treated as one');
    }

    public function test_a_revoked_key_stops_working_immediately(): void
    {
        [$key, $plain] = ApiKey::issue(7, 3, 'leaked');
        $this->assertNotNull(ApiKey::resolve($plain));

        $key->forceFill(['revoked_at' => now()])->save();

        $this->assertNull(ApiKey::resolve($plain), 'revocation must take effect on the next call');
    }

    public function test_two_keys_never_collide(): void
    {
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            [, $plain] = ApiKey::issue(1, 1, "k$i");
            $this->assertNotContains($plain, $seen);
            $seen[] = $plain;
        }
    }

    public function test_only_creator_and_above_carry_api_access(): void
    {
        $limits = \App\Services\CreditService::PLAN_LIMITS;

        foreach (['free', 'ugc_pass', 'starter', 'appsumo_starter', 'lifetime_starter'] as $tier) {
            $this->assertFalse($limits[$tier]['api_access'] ?? false, "$tier must not have API access");
        }
        foreach (['creator', 'agency', 'pro', 'appsumo_creator', 'appsumo_agency',
                  'lifetime_creator', 'lifetime_agency'] as $tier) {
            $this->assertTrue($limits[$tier]['api_access'] ?? false, "$tier should have API access");
        }
    }

    public function test_a_key_is_confined_to_the_developer_namespace(): void
    {
        // Allowlist, not denylist: the paths a key must never reach are
        // simply not under the one prefix it may. DeveloperApiTest proves
        // the same over HTTP.
        $namespace = \App\Http\Middleware\AuthenticateWithJwt::API_KEY_NAMESPACE;

        foreach (['api/v1/billing/status', 'api/v1/admin/users', 'api/v1/auth/refresh', 'api/v1/api-keys',
            'api/v1/me', 'api/v1/workspaces/switch/2', 'api/v1/workspace-access/switch/2', 'api/v1/cruise/apply',
            'api/v1/projects/1/share', 'api/v1/social/accounts'] as $path) {
            $this->assertFalse(\Illuminate\Http\Request::create('/'.$path)->is($namespace),
                "$path must stay out of reach of a long-lived credential");
        }
        $this->assertTrue(\Illuminate\Http\Request::create('/api/developer/v1/videos')->is($namespace));
    }
}
