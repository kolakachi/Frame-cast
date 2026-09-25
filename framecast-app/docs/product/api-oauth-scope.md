# OAuth for MCP connectors — scope

25 September 2026 · Implements D2 (OAuth path) of [api-access-todo.md](api-access-todo.md).
Status: built and verified on the dev stack the same day; see the tracker's evidence log.
Decided in scope 25 September 2026 so ChatGPT and Claude web connectors can
connect without the user handling a key.

## What a connector needs

ChatGPT connectors and Claude web connectors both implement the MCP
authorization spec (OAuth 2.1 as a public client):

1. `GET /.well-known/oauth-protected-resource/mcp` on the MCP host, naming
   the authorization server.
2. `GET /.well-known/oauth-authorization-server` on that server: endpoints,
   PKCE S256, `authorization_code` + `refresh_token` grants, `none` client
   auth, scopes.
3. Dynamic client registration (`POST /oauth/register`, RFC 7591): the
   connector registers itself with its redirect URI and gets a client_id.
4. `GET /oauth/authorize`: the user signs in, sees what is being asked, picks
   a workspace, approves; the browser is sent back with a code.
5. `POST /oauth/token`: code + PKCE verifier → access token + refresh token;
   refresh token → new pair.
6. The MCP endpoint answers 401 with `WWW-Authenticate: Bearer
   resource_metadata="…"` when the token is missing or bad.

## Design: an OAuth grant is a hidden API key

No second authorization path. Approving a connector creates an `ApiKey`
(name = client name, 90-day expiry, optional cap) that the user never sees,
owned by a grant. Access tokens are short-lived handles onto that key.
Everything downstream — namespace confinement, entitlement, membership
re-check, throttles, spend cap, attribution, analytics `via` — is unchanged,
because by the time a request reaches a controller it is "a request with
api_key_id", exactly as today.

| Table | Purpose |
|---|---|
| `oauth_clients` | Registered connectors: `id` (public client_id), `name`, `redirect_uris` (json), `created_at`. Public clients; no secret. |
| `oauth_authorization_codes` | `code_hash`, `client_id`, `grant_id`, `code_challenge`, `redirect_uri`, `expires_at` (5 min), `used_at`. |
| `oauth_grants` | One per user × workspace × client: `user_id`, `workspace_id`, `client_id`, `api_key_id`, `scopes`, `revoked_at`. |
| `oauth_tokens` | `access_token_hash`, `refresh_token_hash`, `grant_id`, `access_expires_at` (1 h), `refresh_expires_at` (90 d), `rotated_at`. |

Token format: `wyv_oat_<40 hex>` (access), `wyv_ort_<40 hex>` (refresh).
Stored hashed like keys.

**Middleware.** `AuthenticateWithJwt` gains a third branch: a `wyv_oat_`
bearer resolves an unexpired token → grant (not revoked) → api key, then
continues down the existing key path with that key. The SPA session and
`wyv_live_` paths are untouched.

**Revocation.** Revoking a grant revokes its key and tokens. The dashboard
key list shows connector grants alongside keys ("Connected apps") with the
same revoke action. Removing membership or suspending the workspace ends the
grant through the existing key checks.

**Refresh.** Refresh tokens rotate on every use; a reused refresh token
revokes the grant (replay detection).

## Endpoints (Laravel)

| Route | Auth | Does |
|---|---|---|
| `GET /.well-known/oauth-authorization-server` | none | Metadata, static from config. |
| `GET /.well-known/oauth-protected-resource/mcp` | none | Served by the sidecar (SDK metadata router). |
| `POST /api/v1/oauth/register` | none, throttled | DCR: validate `redirect_uris` (https, no fragments; localhost allowed for dev), store, return client_id. |
| `POST /api/v1/oauth/authorize/context` | session | Given client_id + redirect_uri + state + code_challenge, validate and return what the consent page shows: client name, scopes, the user's workspaces eligible for API access. |
| `POST /api/v1/oauth/authorize/approve` | session, owner/admin of the chosen workspace | Create grant (+ hidden key), issue code, return the redirect URL. |
| `POST /api/v1/oauth/token` | none, throttled | `authorization_code` (PKCE S256 verified, code single-use, redirect_uri match) or `refresh_token` (rotate). |
| `POST /api/v1/oauth/revoke` | none | RFC 7009: revoke a token's grant. |

The consent page itself is an SPA route, `/oauth/authorize`, so the existing
login and workspace state are reused. The router guard must carry the full
consent URL through login; today it drops the destination.

## Consent page (SPA)

One view. Shows: the connector's name, "will be able to: estimate, create and
fetch videos in <workspace>, spending up to the workspace's credits", a
workspace selector if the user has more than one eligible workspace, Approve
and Deny. Deny redirects with `error=access_denied`. Client seats cannot
approve (they cannot hold keys).

## Sidecar changes

- `requireBearerAuth({ verifier, resourceMetadataUrl })` so 401s carry the
  discovery pointer.
- `mcpAuthMetadataRouter` mounted at root for the protected-resource document.
- Verifier accepts `wyv_oat_` as well as `wyv_live_` (it already just asks the
  API, so this is a prefix check).
- nginx: `/.well-known/oauth-protected-resource*` → mcp; `/.well-known/
  oauth-authorization-server` → Laravel.

## Tests

Feature (PHP): register client; full code flow with PKCE (wrong verifier
fails, code reused fails, redirect mismatch fails); refresh rotation and
replay revokes; access token reaches the developer namespace and nothing
else; expired access token 401 with `WWW-Authenticate`; revoked grant ends
access; membership removal ends access; client seat cannot approve; grant
is listed and revocable from the key endpoints.

Sidecar: smoke script extended with an OAuth token; one manual connect from
a Claude web connector and one from a ChatGPT connector against the deployed
build.

## Out of scope

Confidential clients and client secrets, ID tokens / OpenID, scopes beyond a
single `videos` scope, consent for third-party marketplaces, revocation UI
beyond the existing key list.

## Decisions taken in this scope

1. **Grant = hidden API key.** One authorization path, not two.
2. **Public clients with DCR only.** That is what the connectors do; a
   manually registered confidential client can be added later.
3. **Access tokens live one hour, refresh tokens 90 days**, matching the
   pilot key lifetime.
4. **Owner/admin approval only**, same rule as issuing a key.
5. **Consent lives in the SPA**, not a Blade page, because the session is a
   bearer JWT the SPA holds.

## Estimate

Laravel authorization server and middleware branch: two days. SPA consent
page and login redirect carry-through: one day. Sidecar metadata and nginx:
half a day. Tests and a real connector run: one day. About a week, as
planned.
