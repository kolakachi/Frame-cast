# API access for Creator and above — plan and current state

Version 1.3 · 25 September 2026 · Foundation implemented; not ready for customer keys; MCP is the target client

Audience: product owner and implementation team.

Implementation tracker: [API and MCP TODO](api-access-todo.md).

This is the proposed release plan, not developer documentation or a claim that
all controls below exist. The implementation baseline reviewed is `5428bab`.
Production deployment and migrations have not been verified by this review.

## 1. Purpose and scope

A customer wants to create videos from a private ChatGPT integration and has
asked for developer documentation and authentication steps.

WyvStudio already has internal endpoints for credit estimation, project
creation, generation, status, export and delivery. Those endpoints originally
used browser-session JWTs. An integrator can technically manage token refresh,
but that is not a supported external authentication contract; customers should
not have to share passwords or copy browser-session credentials.

Build a small, supported API over the existing generation and billing services.
Do not rebuild the video pipeline or expose every internal route by default.
The first milestone is one complete customer workflow, not a general developer
platform or simultaneous support for every video type.

The delivery target is an MCP server that an AI assistant connects to. The
REST contract in §5 exists to serve that server and any GPT Actions client;
MCP is not an optional extra after the API, it is what the API is for. Stage A
and Stage D are therefore delivered together for the pilot.

API access may help retention, upgrades and distribution. These are hypotheses
to validate through the pilot, not guaranteed outcomes. Activation work remains
important. The earlier draft cited 8 of 17 paying customers not finishing a
video; that is historical context, not a newly verified metric.

## 2. Entitlements and pilot decisions

Retain the implemented **Creator-and-above** entitlement as the starting point:
`creator`, `pro`, `agency`, `enterprise`, `studio`, `scale`, and qualifying
AppSumo/lifetime equivalents. Exclude `free`, `ugc_pass` and every Starter tier.
`CreditService::PLAN_LIMITS.api_access` remains the source of truth.

- Re-evaluate entitlement and authorization on every request.
- Preserve existing feature restrictions, quotas, credit prices and refund
  behavior. API access does not unlock features absent from the workspace plan.
- Use the existing credit ledger, with API-key/request attribution added for
  troubleshooting and spend reporting; do not create a competing balance.
- Start with an explicitly enrolled customer pilot. Plan eligibility alone
  should not enable broad rollout before the release gates in §7 pass.
- No customer key should be issued under the current implementation.

Before inviting the lead, confirm which MCP client they will use, and
whether their first workflow is standard narrated video or UGC. Choose one
supported creation flow for the pilot. Exact rate, concurrency and spending
limits must be agreed and configured before access is granted.

The client decides the authentication work (see §7 Stage D). Claude, Cursor
and Claude Code accept a bearer header on a remote MCP server, so an
API-key-authenticated pilot needs no OAuth. ChatGPT connectors require an
OAuth 2.1 authorization server that WyvStudio does not have. Pilot with a
bearer-header client first; build OAuth only when a ChatGPT connector is
confirmed as a requirement.

## 3. Target authentication and authorization

The following are requirements unless marked as already implemented in §4.

| Area | Target behavior |
|---|---|
| Secret format | Retain `wyv_live_` plus 40 random hex characters. |
| Storage | Store only the SHA-256 hash; return plaintext once. Never log the secret or include it in analytics. |
| Lookup | Use a unique full-token hash lookup, or a collision-safe identifier. The current short-prefix lookup must not select only the first colliding row. The visible prefix is a display aid, not an authorization boundary. |
| Workspace | Bind each key to one workspace. No implicit switching or inherited access to other client workspaces. |
| Issuing identity | Validate the issuing user's active status, current workspace membership and effective role on every request. Removed membership invalidates access; reduced permissions immediately constrain the key. |
| Workspace status | Enforce both workspace and parent-agency suspension rules, as the session path does. |
| Permissions | Deny by default. Explicitly allow approved HTTP methods and route operations for the pilot. New internal routes are not automatically API-accessible. |
| Key management | Session-only, workspace owner/admin authorization for listing, creating and revoking keys. Any platform-admin support exception must be explicit and tested. |
| Key count | Maximum five active keys per workspace, enforced transactionally so concurrent requests cannot exceed it. |
| Lifecycle | Immediate revocation, replacement/rotation instructions, and a finite pilot expiry. Revocation blocks new requests; document how already accepted jobs are handled. |

A full configurable scope UI can wait. An explicit allowlist cannot.

Implement the allowlist as a separate namespace rather than a denylist over
the SPA's routes: API keys reach only `/api/developer/v1/*`, and nothing else.
That namespace carries thin request/response shapes that wrap existing
services, so SPA-driven changes to `/api/v1` cannot break integrators and no
internal route is ever exposed by omission. The MCP server wraps only this
namespace and never calls `/api/v1` directly.
Initially exclude account deletion/profile changes/data export, billing,
credential management, workspace/member administration, social publishing,
public sharing, and destructive operations. Customers can use the dashboard
for those actions. Revisit individual capabilities only with a defined use case.

Reuse shared authorization rules rather than maintaining a weaker parallel
path for API keys. Permissions must be the intersection of the key's allowed
operations, the user's current authority and the workspace's entitlements.

## 4. Implementation baseline and confirmed gaps

Commit `5428bab` includes:

- `api_keys` migration and `ApiKey` model with issuance, hashed storage,
  resolution, revocation and masking.
- API-key support in `AuthenticateWithJwt`.
- A forbidden-path list for billing, admin, auth, api-keys and workspaces.
- Plan entitlements and session-authenticated key-management endpoints:
  `GET/POST /api/v1/api-keys` and `DELETE /api/v1/api-keys/{keyId}`.
- Six tests focused on key storage/resolution, entitlement configuration and
  the contents of the forbidden-path constant.

The key-management API already exists; tinker is not required. There is no
customer-facing key dashboard. Controlled pilot issuance can use the management
API after authorization and other release blockers are fixed.

### Release blockers found in review

1. **Account operations remain reachable.** The exclusion list allows
   `DELETE /api/v1/me`, which can delete the issuing account and, in some cases,
   its workspace. Other `/me` operations also remain reachable. Retyping an
   email is not a separate authorization check; the credential can read it.
2. ~~**Membership and suspension parity is missing.**~~ Resolved in step 2:
   the key path resolves the issuer's current role through `WorkspaceAccess`
   (inactive user, revoked membership, downgraded role all end the key),
   applies the session path's workspace and parent-agency suspension rule,
   and keeps client-seat limits. Tested in `DeveloperApiTest`.
3. ~~**Key administration is incomplete.**~~ Resolved in step 2: listing,
   creation and revocation are owner/admin only; the five-key count and
   insert run under the workspace row lock.
4. **Default access is too broad.** Routes such as `/workspace-access` are not
   covered by the `/workspaces` exclusion. Cruise Control (the in-app
   assistant), social publishing, scheduled posts, public share toggling,
   approvals, and project/character/voice-profile deletion are all reachable
   today, although §5 defers or excludes each of them. The namespace rule in
   §3 closes all of these at once. The switch handler currently needs
   a session ID, so this review does not claim successful token minting; the
   route should nonetheless be explicitly inaccessible to API keys.
5. ~~**Short-prefix collisions are not handled.**~~ Resolved in step 2:
   resolution is by the full hash with a unique index; the prefix is display
   only. A forced-collision test covers it.
6. **Operational controls are missing.** No rate limiting exists anywhere on
   the API today, for sessions or keys; there is no throttle middleware in
   the kernel, providers or routes. No concurrency cap or credit-spending
   ceiling is implemented by this feature either.
   Duplicate-request protection for supported operations must also be verified
   and supplied before release.
7. **Tests do not prove route enforcement.** Inspecting a constant is not a
   substitute for authenticated HTTP requests to forbidden and allowed routes.
8. **Client-workspace entitlement lags the agency.** A client workspace copies
   the agency's plan tier at creation only, and `limitFor` reads the child's
   own tier; nothing syncs children when the agency's plan changes. This is
   product-wide (every plan gate behaves this way), not specific to API keys,
   so the key path deliberately matches the rest of the app. Fixing it means
   syncing child tiers on plan change, tracked outside this plan.

The static review did not rerun PHP tests because PHP was unavailable in the
review shell. Earlier claims about mutation-test results are not treated as
release evidence here.

## 5. Smallest useful external API

Expose a stable, versioned contract for these capabilities. Tool names and any
new endpoint paths remain implementation choices, not currently available APIs.

| Capability | Required behavior |
|---|---|
| Capabilities and balance | Return permitted video options, applicable limits and available credits without exposing payment credentials or unrelated account data. |
| Estimate | Validate the proposed video and return a quote: a `quote_id`, the credit amount, the assumptions and an expiry (10 minutes). An estimate is not a guarantee or permission to exceed a spending cap. |
| Create/generate | Require a `quote_id` and an idempotency key. Reject an expired quote, a quote from another workspace, or a payload that differs from what was quoted. Require the balance to cover the quoted maximum, record the authorized maximum on the project, and return a stable job/project ID promptly. A hard reservation that stops the pipeline at the cap is an A2 control (§6); credits are deducted per stage today, so Stage A authorizes and attributes spend rather than escrowing it. |
| Progress | Return structured queued/running/paused/failed/completed states, actionable error details and retry guidance. |
| Export/result | Reuse existing export rules; expose readiness and authorized, expiring download links plus a WyvStudio project link. |

The quote is the approval mechanism. An assistant cannot spend credits without
first surfacing a quote to the user and passing its ID back; the backend
enforces the amount and expiry regardless of what the model claims was
approved. Tool annotations and prompt instructions are hints to the client,
not controls.

Generation is asynchronous. Do not hold an MCP or ChatGPT tool call open for an
entire render, and do not promise background polling after the conversation ends.
The customer can check status again or follow the project link. Signed completion
webhooks can be added later if automation demand warrants them.

Every resource lookup must enforce the key's workspace. Do not make a video
public merely to provide a result link. Test all existing generation gates and
export restrictions through the external authentication path.

### Reusable voices and characters

For the initial flow, allow listing and selecting existing workspace voices and
characters where that flow supports them. Return stable IDs and compatibility
information, not provider credentials. Access to a saved resource does not imply
that every model can use it, or that the plan permits custom presenters.

After the basic creation pilot, add these explicitly authorized capabilities:

| Capability | Required behavior |
|---|---|
| Asset upload | Accept supported image/audio inputs through a bounded upload flow; validate actual media type, size, duration and workspace ownership before use. Remote imports, if offered, must enforce network and download restrictions. |
| Voice cloning | Capture the required permission/consent to use the voice; validate the sample, estimate cost, enforce cloning allowances, create an asynchronous job and return its status and saved voice ID. |
| Voice preview | Preview authorized saved voices and report any credit cost before starting paid work. |
| Character creation | Accept supported descriptions and/or authorized reference assets, enforce character limits, and return a saved character ID. |
| Character images | Estimate and generate supported images, report job progress and attach successful results to the character. |

Exact payloads, supported providers and compatibility rules need implementation
review before documentation is published. Do not promise that a saved character
will work as a reference with every video model. Return a clear unsupported
combination rather than silently substituting a different presenter or model.
Deletion, voice replacement and other destructive library operations remain
outside the initial allowlist. Cloning and character generation inherit the
same spend caps, idempotency and failure-settlement controls as video creation.

### Editor operations and assistant-assisted editing

Expose approved editor operations through shared backend services, not through
UI automation or unrestricted configuration JSON. API and MCP permissions must
be identical for the same operation. Proposed scope:

| Area | Supported operation to define and test |
|---|---|
| Project inspection | Read ordered scenes, supported settings, current revision, asset readiness and export freshness. |
| Script and scenes | Update scripts and reorder scenes. Scene addition/duplication requires an explicit operation and cost implications; deletion stays out of the first editing release. |
| Visuals | Assign an owned asset or request a supported image/animation operation. Preserve uploaded footage where requested. |
| Narration | Select a compatible saved voice, update supported settings and regenerate affected narration. |
| Captions | Update allowlisted styles, placement and other supported caption settings. |
| Music and sound | Select authorized assets and adjust supported volumes/timing; paid generation is a separate estimated operation. |
| Output settings | Select supported aspect ratios and other export options without promising arbitrary conversion. |
| Regeneration/export | Regenerate affected content, check readiness and export a new version. Return the export revision and freshness with delivery results. |

Provide project-specific capabilities: allowed operations and setting schemas,
valid values/ranges, plan/model restrictions and reasons for unavailable edits.
Whole-video takes must retain their supported review/delivery behavior rather
than being presented as independently editable scenes. Narration-controlled
scene duration must remain governed by the existing editor rules.

Required editing workflow:

1. Read the current project and revision.
2. Produce structured proposed changes, affected scenes, required regeneration
   and an estimate. Proposal creation must not implicitly apply edits or start
   production; disclose and cap any charge for the planning operation itself.
3. Obtain approval for the concrete proposal and maximum spend. Bind approval
   to the workspace, project revision and exact proposal; expire it and reject
   reuse for changed content. Do not treat a model-supplied boolean as proof of
   approval for arbitrary spending.
4. Apply validated changes with a revision precondition. Reject stale proposals
   if the dashboard or another integration has since edited the project.
5. Preserve stale-narration/lip-sync flags and export freshness. Regenerate only
   affected content within approved limits, then export the approved revision.
6. Report partial failures and recovery options. Do not imply an old export
   contains new edits; require an explicit choice to deliver an older version.

Start with direct tools: the external assistant chooses narrowly defined
operations, while WyvStudio validates and executes them. Later, optionally
expose the in-app assistant as a bounded planning operation for broader requests
such as “make this video more energetic.” Reuse its underlying services only
after reviewing their authorization and execution behavior. Its proposed edits
must pass the same approval, revision and spending checks as direct tools.

Keep delegation one-way and bounded: the internal assistant must not recursively
call the external assistant, expose arbitrary internal tools, or grant itself
extra permissions. Give each proposal/run an ID and return structured changes,
status and errors so users can understand what happened.

## 6. Spending, retries and operational controls

Rate limits protect request volume; they do not bound the cost of one request
or prevent slow repeated spending. Before the pilot, implement:

- Per-key and aggregate workspace request limits, with a separate allowance
  for progress polling and a `429` response with retry guidance.
- Per-key/workspace limits on simultaneous generation jobs. Multiple keys
  must not bypass workspace capacity or spending limits.
- A configurable per-key spend ceiling and maximum authorized spend per
  generation. Enforce reservations atomically before provider work starts,
  including downstream stages and retries; pause before exceeding the cap.
- Idempotency for costly create/generate/export operations. Bind a request key
  to the workspace, operation and canonical payload. Replays return the same
  operation; a conflicting payload returns a conflict, not another charge.
- Defined reservation settlement/release on success, failure and cancellation,
  using existing ledger/refund semantics without issuing duplicate refunds.
- Structured logs with request ID, key ID, workspace, operation, job ID and
  credit outcome. Redact authorization headers and secrets.
- Tag every analytics event raised on the key path with `source: api` or
  `source: mcp` and the key ID, and exclude those events from activation and
  usage funnels. `CreditService` already captures PostHog events; untagged
  API traffic would be attributed to the issuing user and inflate product
  metrics.

Documentation must distinguish retryable transport errors from validation,
insufficient-credit, permission and content-policy failures. Retries must not
silently change the user's requested content or evade a rejected request.

## 7. Delivery sequence and acceptance gates

### Stage A — safe private API pilot

Complete before giving the lead a key:

1. Authorization parity, the `/api/developer/v1` namespace, safe key
   management and collision-safe lookup from §§3–4.
2. Rate, concurrency, spend and duplicate-request controls from §6.
3. One supported creation flow with status and finished-video retrieval.
4. Minimal developer documentation: base URL/version, supported operations,
   authentication, key issuance/revocation/expiry, request/response examples,
   credit estimates and charging behavior, idempotency, limits, error codes,
   polling and download-link expiry. Include an OpenAPI schema for GPT Actions.
5. Request-level regression tests and one end-to-end integration check with
   controlled test credentials and an agreed generation budget.

Required tests include forbidden account/billing/admin/key-management routes;
allowed generation; cross-workspace resource denial; removed membership;
role downgrade; inactive user; suspended workspace/parent; plan downgrade;
revoked/expired key; concurrent issuance; prefix collisions; repeated and
concurrent generation requests; spending caps; failure settlement; and result
retrieval without public sharing.

A manual enrollment process is acceptable here. Record the pilot limits and
support contact. Verify the deployment/migration before issuing credentials.

### Stage B — supported customer API feature

Add the dashboard for issuance, one-time secret display, revocation, rotation,
expiry and usage. Publish the tested developer guide and examples. Show API
eligibility consistently in pricing and settings, with documented support and
versioning expectations. Broaden the rollout only after pilot evidence shows
users can complete the workflow and failures are diagnosable.

### Stage C — reusable media and editor expansion

Add voice/character listing and selection to the pilot only where needed by its
chosen flow. After Stage A is dependable, add uploads, voice cloning and
character creation, then the direct editor operations defined in §5. Each new
operation requires explicit allowlisting, documentation, cost controls and
request-level tests before customer access.

Acceptance tests must cover ownership of every uploaded/referenced asset,
consent and plan limits, unsupported model/resource combinations, duplicate
cloning/image jobs, script changes invalidating narration, stale lip sync,
whole-video restrictions, stale exports, revision conflicts, bounded approved
spend and partial failure recovery. Verify dashboard and API editing produce
the same state transitions and accounting outcomes.

Assistant delegation follows working direct editor tools. Test that planning
cannot silently execute a proposal and that expired or changed proposals need
fresh approval. These expansions are planned work, not capabilities confirmed
available through the current API-key implementation.

### Stage D — MCP server

MCP is the pilot's delivery surface, built over the `/api/developer/v1`
namespace and delivered alongside Stage A. It never receives wider authority
than an API key.

**Placement (decided 25 September 2026).** Nothing in the stack speaks MCP
today; there is no PHP or Node MCP dependency. The server is a Node sidecar
(`mcp` service in compose, beside `renderer` and `extract`) using the official
TypeScript SDK. It holds no credentials of its own: it forwards the caller's
bearer token to the developer namespace on every request, so authorization,
entitlement and spend enforcement stay in Laravel and are tested once. The
Laravel MCP package was considered and rejected; do not add a second path.

**Transport.** Streamable HTTP at `https://<api host>/mcp`, stateless (no
server-side MCP session), so no session store is needed and the sidecar can
be restarted or scaled freely. Nginx must disable proxy buffering and raise
read/send timeouts on that path. Provide CORS for the confirmed client
origins only.

**Authentication by client.**

| Client | Mechanism | Work required |
|---|---|---|
| Claude (web/desktop), Claude Code, Cursor | Bearer header carrying a WyvStudio API key | None beyond Stage A. Document the header. |
| ChatGPT connectors / Apps SDK | OAuth 2.1 with authorization server metadata, dynamic client registration, PKCE, protected-resource metadata at `/.well-known/oauth-protected-resource` | A new authorization server issuing tokens bound to one workspace, consent screen, disconnect and revocation. Sized as its own stage; not started until the lead confirms this client. |

Under OAuth the issued access token maps to the same key model (one workspace,
same allowlist, same limits) so no second authorization path exists.

**Tool set for the pilot.** Five tools, each a one-to-one wrapper over a
developer-namespace operation:

- `get_capabilities` (read-only): permitted options, limits, credit balance.
- `estimate_video` (read-only): returns the quote described in §5.
- `create_video`: requires `quote_id`; starts generation, returns project ID.
- `get_video_status` (read-only): structured state, failure reason, retry
  guidance.
- `get_video_result` (read-only): readiness, expiring download link as a
  resource link, WyvStudio project link.

Mark read-only tools with `readOnlyHint`. Nothing is marked destructive
because nothing destructive is exposed. Tool descriptions must state that
`create_video` spends credits and that the user should be shown the quote
first; the backend still enforces the quote regardless. Voice and character
listing tools are added only if the chosen flow needs them. Library, editor
and assistant tools follow Stage C and each requires its own gate.

**Long operations.** `create_video` returns within seconds. The tool result
tells the assistant to call `get_video_status`; the server never blocks on a
render and never promises background polling after the conversation ends.

**Testing.** Use the MCP Inspector locally against the sidecar. Request-level
tests cover: workspace isolation across two keys, revoked key mid-session,
expired and foreign `quote_id`, replayed `create_video` with the same
idempotency key, client retry of a timed-out call, and result retrieval
without public sharing. One end-to-end run in the confirmed client with an
agreed test budget.

**Documentation.** Connection steps per confirmed client, the header format,
the five tools with example calls, the quote and idempotency behaviour, and
an honest statement of which clients are supported. Host on the existing
`docs` site.

For a private GPT using Actions rather than MCP, publish an OpenAPI schema for
the developer namespace and use API-key authentication; that path needs no
MCP work. Recheck current client requirements when implementing:

- [MCP authorization specification](https://modelcontextprotocol.io/specification/draft/basic/authorization)
- [GPT Actions authentication](https://developers.openai.com/api/docs/actions/authentication)
- [ChatGPT MCP authentication](https://developers.openai.com/plugins/build/auth)

No public marketplace listing or custom chat UI is required for the pilot.
Validate with the requesting customer before promising compatibility with
additional clients.

## 8. Success criteria and remaining decisions

The pilot succeeds when the customer independently creates a useful video,
checks progress and retrieves it, with correct credit accounting and no access
outside the approved workspace and operations. Track completion, repeat usage,
errors, credits consumed and support effort. API availability alone is not proof
of customer value.

Confirm before implementation of the pilot contract:

- Customer's MCP client (bearer-header client or ChatGPT/OAuth) and first
  video flow.
- ~~MCP server placement~~ Decided: Node sidecar.
- Numerical request, concurrency and spending limits; pilot key lifetime.
- Exact supported input options and any asset-upload requirements.
- Which saved voices/characters the initial flow must support.
- Priority order for cloning, character creation and editor operations.
- Approval UX for edit proposals and whether internal-assistant planning is
  needed after direct editing tools are available.
- Whether existing eligible customers need explicit enrollment during rollout.
- Support ownership and the criteria for moving beyond the pilot.

Keep current plan gating and credit rates unless product decisions change them.
Do not announce API/MCP availability, promise activation benefits or issue a
customer key merely because the base authentication code has been committed.
