<?php

namespace App\Services\OAuth;

use App\Models\ApiKey;
use App\Models\OAuth\OAuthAuthorizationCode;
use App\Models\OAuth\OAuthClient;
use App\Models\OAuth\OAuthGrant;
use App\Models\OAuth\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agency\WorkspaceAccess;
use App\Services\CreditService;
use App\Services\WorkspaceUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 authorization server for MCP connectors (ChatGPT, Claude web).
 *
 * Public clients with dynamic registration and PKCE. Approving a connector
 * creates a hidden, 90-day API key owned by a grant; access tokens are
 * one-hour handles onto that key and refresh tokens rotate on every use.
 * Downstream, a connector request is "a request with api_key_id", so every
 * control built for keys — namespace, entitlement, membership re-check,
 * throttles, spend cap, attribution — applies unchanged.
 */
class AuthorizationServer
{
    public const SCOPE = 'videos';

    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly CreditService $credits,
    ) {
    }

    /** @return array<string, mixed> RFC 8414 document */
    public function metadata(): array
    {
        $issuer = (string) config('developer.oauth.issuer');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/api/v1/oauth/token',
            'registration_endpoint' => $issuer.'/api/v1/oauth/register',
            'revocation_endpoint' => $issuer.'/api/v1/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => (array) config('developer.oauth.scopes'),
            'service_documentation' => 'https://docs.wyvstudio.com/api',
        ];
    }

    /**
     * RFC 7591. Only what a connector needs; everything else is fixed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
    {
        $name = trim((string) ($input['client_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new OAuthException('invalid_client_metadata', 'client_name is required (max 120 characters).');
        }
        $uris = array_values(array_unique(array_map('strval', (array) ($input['redirect_uris'] ?? []))));
        if ($uris === [] || count($uris) > 10) {
            throw new OAuthException('invalid_redirect_uri', 'Between 1 and 10 redirect_uris are required.');
        }
        foreach ($uris as $uri) {
            if (! self::acceptableRedirect($uri)) {
                throw new OAuthException('invalid_redirect_uri', "Redirect URI not allowed: {$uri}. Use https, or http://localhost for development, with no fragment.");
            }
        }
        $method = (string) ($input['token_endpoint_auth_method'] ?? 'none');
        if ($method !== 'none') {
            throw new OAuthException('invalid_client_metadata', 'Only public clients are supported (token_endpoint_auth_method must be "none").');
        }

        $client = OAuthClient::query()->create(['id' => 'wyvc_'.bin2hex(random_bytes(12)), 'name' => $name, 'redirect_uris' => $uris]);

        return [
            'client_id' => $client->getKey(),
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => self::SCOPE,
        ];
    }

    public static function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! $parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * Validate an authorization request and describe what consent would grant.
     *
     * @param  array<string, mixed>  $params
     * @return array{client: OAuthClient, scopes: list<string>, workspaces: list<array{id:int,name:string,role:string}>}
     */
    public function context(User $user, array $params): array
    {
        $client = $this->validateAuthorizationRequest($params);

        return [
            'client' => $client,
            'scopes' => [self::SCOPE],
            'workspaces' => $this->eligibleWorkspaces($user),
        ];
    }

    /**
     * Approve: create (or reuse) the grant and hidden key, mint a code.
     *
     * @param  array<string, mixed>  $params
     * @return string the redirect URL carrying code and state
     */
    public function approve(User $user, array $params, int $workspaceId): string
    {
        $client = $this->validateAuthorizationRequest($params);

        $workspace = Workspace::query()->whereKey($workspaceId)->where('status', 'active')->first();
        $role = $workspace ? $this->access->role($user, $workspace) : null;
        if (! $workspace || ! $role) {
            throw new OAuthException('access_denied', 'That workspace is not available to you.', 403);
        }
        if (! in_array($role, ['owner', 'admin'], true) && ! WorkspaceUsageService::isAdmin($user)) {
            throw new OAuthException('access_denied', 'Only a workspace owner or admin can connect an app.', 403);
        }
        if (! $this->credits->limitFor((int) $workspace->getKey(), 'api_access')) {
            throw new OAuthException('access_denied', 'API access is available on Creator and Agency plans.', 403);
        }

        $grant = DB::transaction(function () use ($user, $client, $workspace): OAuthGrant {
            $grant = OAuthGrant::query()
                ->where('client_id', $client->getKey())->where('user_id', $user->getKey())->where('workspace_id', $workspace->getKey())
                ->whereNull('revoked_at')->lockForUpdate()->first();
            $key = $grant ? ApiKey::query()->whereKey($grant->api_key_id)->whereNull('revoked_at')->first() : null;
            if ($grant && (! $key || $key->isExpired())) {
                // The key behind it was revoked from the dashboard or aged
                // out: the old grant is over, this approval starts a new one.
                $grant->revoke();
                $grant = null;
            }
            if (! $grant) {
                [$key] = ApiKey::issue((int) $workspace->getKey(), (int) $user->getKey(), $client->name, now()->addDays((int) config('developer.oauth.key_ttl_days')));
                $grant = OAuthGrant::query()->create([
                    'client_id' => $client->getKey(), 'user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(),
                    'api_key_id' => $key->getKey(), 'scopes' => [self::SCOPE],
                ]);
            }

            return $grant;
        });

        $code = bin2hex(random_bytes(32));
        OAuthAuthorizationCode::query()->create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $client->getKey(),
            'grant_id' => $grant->getKey(),
            'code_challenge' => (string) $params['code_challenge'],
            'redirect_uri' => (string) $params['redirect_uri'],
            'expires_at' => now()->addMinutes((int) config('developer.oauth.code_ttl_minutes')),
        ]);

        return self::redirect((string) $params['redirect_uri'], array_filter(['code' => $code, 'state' => $params['state'] ?? null], fn ($v) => $v !== null && $v !== ''));
    }

    /** @param array<string, mixed> $params */
    public function deny(array $params): string
    {
        $this->validateAuthorizationRequest($params);

        return self::redirect((string) $params['redirect_uri'], array_filter(['error' => 'access_denied', 'error_description' => 'The user declined.', 'state' => $params['state'] ?? null], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * The token endpoint: code + PKCE verifier, or a refresh token.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function token(array $input): array
    {
        $grantType = (string) ($input['grant_type'] ?? '');

        if ($grantType === 'authorization_code') {
            return $this->exchangeCode($input);
        }
        if ($grantType === 'refresh_token') {
            return $this->refresh($input);
        }

        throw new OAuthException('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
    }

    /** RFC 7009. Always succeeds from the caller's point of view. */
    public function revoke(string $token): void
    {
        $hash = hash('sha256', $token);
        $row = OAuthToken::query()->where('access_token_hash', $hash)->orWhere('refresh_token_hash', $hash)->first();
        $row?->grant?->revoke();
    }

    /**
     * Resolve a presented access token to the key it acts as, or null.
     * Expiry, rotation, grant revocation and key revocation all end here.
     */
    public function resolveAccessToken(string $token): ?ApiKey
    {
        $row = OAuthToken::query()->where('access_token_hash', hash('sha256', $token))->first();
        if (! $row || $row->access_expires_at->isPast()) {
            return null;
        }
        $grant = $row->grant;
        if (! $grant || $grant->revoked_at) {
            return null;
        }

        return ApiKey::query()->whereKey($grant->api_key_id)->whereNull('revoked_at')->first();
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    private function validateAuthorizationRequest(array $params): OAuthClient
    {
        if (($params['response_type'] ?? 'code') !== 'code') {
            throw new OAuthException('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $client = OAuthClient::query()->find((string) ($params['client_id'] ?? ''));
        if (! $client) {
            throw new OAuthException('invalid_client', 'Unknown client_id. Register the client first.', 401);
        }
        $redirect = (string) ($params['redirect_uri'] ?? '');
        if ($redirect === '' || ! $client->allowsRedirect($redirect)) {
            throw new OAuthException('invalid_request', 'redirect_uri is missing or not registered for this client.');
        }
        if (($params['code_challenge_method'] ?? 'S256') !== 'S256') {
            throw new OAuthException('invalid_request', 'code_challenge_method must be S256.');
        }
        $challenge = (string) ($params['code_challenge'] ?? '');
        if (! preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $challenge)) {
            throw new OAuthException('invalid_request', 'A PKCE code_challenge (43–128 characters) is required.');
        }
        $scope = trim((string) ($params['scope'] ?? self::SCOPE));
        foreach (preg_split('/\s+/', $scope) ?: [] as $s) {
            if ($s !== '' && ! in_array($s, (array) config('developer.oauth.scopes'), true)) {
                throw new OAuthException('invalid_scope', "Unknown scope: {$s}.");
            }
        }

        return $client;
    }

    /** @return list<array{id:int,name:string,role:string}> */
    private function eligibleWorkspaces(User $user): array
    {
        $identity = User::query()->find($user->getKey()) ?? $user;
        $ids = DB::table('workspace_memberships')->where('user_id', $identity->getKey())->whereNull('revoked_at')->pluck('workspace_id')->all();
        if ($identity->workspace_id) {
            $ids[] = (int) $identity->workspace_id;
        }
        if ($agency = $this->access->agency($identity)) {
            $ids = array_merge($ids, Workspace::query()->where('parent_workspace_id', $agency->getKey())->pluck('id')->all());
        }

        $out = [];
        foreach (Workspace::query()->whereIn('id', array_unique($ids))->where('status', 'active')->orderBy('id')->get() as $w) {
            $role = $this->access->role($identity, $w);
            if (! $role || (! in_array($role, ['owner', 'admin'], true) && ! WorkspaceUsageService::isAdmin($identity))) {
                continue;
            }
            if (! $this->credits->limitFor((int) $w->getKey(), 'api_access')) {
                continue;
            }
            $out[] = ['id' => (int) $w->getKey(), 'name' => (string) ($w->client_label ?: $w->name), 'role' => $role];
        }

        return $out;
    }

    /** @param array<string, mixed> $input */
    private function exchangeCode(array $input): array
    {
        $code = (string) ($input['code'] ?? '');
        $verifier = (string) ($input['code_verifier'] ?? '');
        if ($code === '' || $verifier === '') {
            throw new OAuthException('invalid_request', 'code and code_verifier are required.');
        }

        // A replayed code is an attack signal: end the grant it belonged to.
        // Done before the transaction so the revocation is not rolled back
        // with the refusal.
        $seen = OAuthAuthorizationCode::query()->where('code_hash', hash('sha256', $code))->first();
        if ($seen?->used_at) {
            OAuthGrant::query()->find($seen->grant_id)?->revoke();
            throw new OAuthException('invalid_grant', 'The authorization code was already used; the connection has been revoked. Reconnect the app.');
        }

        return DB::transaction(function () use ($code, $verifier, $input): array {
            $row = OAuthAuthorizationCode::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (! $row || $row->used_at || $row->expires_at->isPast()) {
                throw new OAuthException('invalid_grant', 'The authorization code is invalid, expired or already used.');
            }
            if (isset($input['client_id']) && (string) $input['client_id'] !== $row->client_id) {
                throw new OAuthException('invalid_grant', 'client_id does not match the authorization.');
            }
            if ((string) ($input['redirect_uri'] ?? '') !== $row->redirect_uri) {
                throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization.');
            }
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! hash_equals($row->code_challenge, $expected)) {
                throw new OAuthException('invalid_grant', 'PKCE verification failed.');
            }
            $grant = OAuthGrant::query()->find($row->grant_id);
            if (! $grant || $grant->revoked_at) {
                throw new OAuthException('invalid_grant', 'The authorization has been revoked.');
            }
            $row->forceFill(['used_at' => now()])->save();

            return $this->issueTokens($grant);
        });
    }

    /** @param array<string, mixed> $input */
    private function refresh(array $input): array
    {
        $refresh = (string) ($input['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new OAuthException('invalid_request', 'refresh_token is required.');
        }

        // Refresh tokens are single-use. A second use means the token was
        // copied: revoke everything rather than guess which holder is the
        // real one. Outside the transaction so the revocation sticks.
        $seen = OAuthToken::query()->where('refresh_token_hash', hash('sha256', $refresh))->first();
        if ($seen?->rotated_at) {
            $seen->grant?->revoke();
            throw new OAuthException('invalid_grant', 'This refresh token was already used; the connection has been revoked. Reconnect the app.');
        }

        return DB::transaction(function () use ($refresh): array {
            $row = OAuthToken::query()->where('refresh_token_hash', hash('sha256', $refresh))->lockForUpdate()->first();
            if (! $row || $row->rotated_at) {
                throw new OAuthException('invalid_grant', 'Unknown or already used refresh token.');
            }
            $grant = $row->grant;
            if ($row->refresh_expires_at->isPast() || ! $grant || $grant->revoked_at) {
                throw new OAuthException('invalid_grant', 'The connection has expired or been revoked. Reconnect the app.');
            }
            $key = ApiKey::query()->whereKey($grant->api_key_id)->whereNull('revoked_at')->first();
            if (! $key || $key->isExpired()) {
                $grant->revoke();
                throw new OAuthException('invalid_grant', 'The connection has expired or been revoked. Reconnect the app.');
            }
            $row->forceFill(['rotated_at' => now()])->save();

            return $this->issueTokens($grant);
        });
    }

    /** @return array<string, mixed> */
    private function issueTokens(OAuthGrant $grant): array
    {
        $access = 'wyv_oat_'.bin2hex(random_bytes(20));
        $refresh = 'wyv_ort_'.bin2hex(random_bytes(20));
        $accessTtl = (int) config('developer.oauth.access_ttl_minutes');
        OAuthToken::query()->create([
            'grant_id' => $grant->getKey(),
            'access_token_hash' => hash('sha256', $access),
            'refresh_token_hash' => hash('sha256', $refresh),
            'access_expires_at' => now()->addMinutes($accessTtl),
            'refresh_expires_at' => now()->addDays((int) config('developer.oauth.refresh_ttl_days')),
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl * 60,
            'refresh_token' => $refresh,
            'scope' => implode(' ', (array) $grant->scopes),
        ];
    }

    /** @param array<string, string> $query */
    private static function redirect(string $uri, array $query): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }
}
