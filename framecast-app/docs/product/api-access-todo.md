# API and MCP implementation TODO

Created 25 September 2026. Tracks [API access plan v1.3](api-access-plan.md).

The plan defines behavior; this checklist tracks delivery. No implementation or
production verification was performed when creating this checklist. Foundation
code exists in `5428bab`; unchecked items below are outstanding or unverified,
not a claim that every underlying service is missing.

## How to use

- Work in dependency order. Stage D (MCP) is delivered with Stage A; it is the
  pilot's client surface, not a follow-on. Stage C never blocks it.
- For each completed item, record the commit/PR and test or verification evidence.
  Keep local implementation, deployment and customer availability distinct.
- Add an owner and target date when scheduling work. Do not mark a release gate
  complete because individual code changes exist.
- Preserve concurrent work: inspect diffs and stage only task-owned files.
- Reconcile findings with current code before implementing; the baseline may
  have changed since review.

## 0. Confirm pilot scope

- [x] Confirm the customer's MCP client. Decided 25 September 2026: support both.
      Bearer-header clients work now; the OAuth server in D2 is in scope so
      ChatGPT connectors (and Claude web connectors) can connect without a key.
- [x] Decide MCP server placement: Node sidecar (decided 25 September 2026).
- [ ] Choose one first creation flow: standard narrated video or UGC.
- [x] Specify supported inputs, output options and required saved voices/characters.
      Decided 25 September 2026: prompt or script; stock, AI image or AI video
      visuals; duration, aspect ratio, tone, title, goal. Default voice only;
      saved voices and characters are the first post-pilot expansion (C1).
- [x] Set numeric per-key/workspace request, concurrency and credit limits, plus
      maximum spend per operation and pilot credential expiry. Decided 25
      September 2026: keep 60 reads / 10 writes per key per minute, 120/20 per
      workspace, 3 videos in flight; pilot keys expire after 90 days; spend cap
      sized per customer at issue time.
- [ ] Confirm Creator-and-above eligibility and explicit pilot enrollment behavior.
- [ ] Assign implementation/support owners and agree pilot success criteria.

## A1. Authorization — release blockers

- [x] Add the `/api/developer/v1` namespace and restrict API keys to it; remove
      the `FORBIDDEN` denylist once nothing else is reachable. (Step 1, uncommitted;
      `DeveloperApiTest` proves it over HTTP.)
- [x] Deny account deletion, profile mutation and personal data export; cover
      `/me` and related paths with authenticated request tests. (Closed by the
      namespace rule; tested in `DeveloperApiTest`.)
- [x] Deny billing, admin, auth, credential management, workspace/member
      administration and all workspace-switch paths to API keys. (Namespace rule.)
- [x] Keep publishing, public sharing and destructive operations outside the
      initial allowlist. (Namespace rule.)
- [x] Validate active user, current membership and effective role on every call. (Step 2)
- [x] Enforce workspace and parent-agency suspension consistently with sessions. (Step 2)
- [ ] Enforce resource ownership and existing plan/feature gates for every
      allowed operation; do not inherit access to other workspaces.
- [ ] Resolve `api_access` entitlement against the billing workspace so a
      client workspace follows the agency's current tier. (Deferred: product-wide
      behaviour, see plan §4 item 8; not an API-specific gate.)
- [x] Enforce owner/admin permissions for listing, issuing and revoking keys;
      define and test any platform-admin support exception. (Step 2; no
      platform-admin exception exists or is planned.)
- [x] Enforce the five-active-key limit transactionally under concurrent issuance. (Step 2, workspace row lock)
- [x] Replace first-match prefix resolution with collision-safe lookup and a
      suitable database constraint; preserve existing valid keys if any exist.
      (Step 2: lookup by hash, unique index; existing rows unaffected.)
- [x] Add finite pilot expiry, immediate revocation and rotation behavior.
      (`expires_in_days` on create, `api_key_expired` 401, `POST /api-keys/{id}/rotate`
      revokes the old key at once and carries name/expiry/cap over.)
- [ ] Verify plaintext secrets appear only once and are redacted from logs,
      errors and analytics; document treatment of already accepted jobs on revocation.

## A2. Spending and execution safety

- [x] Add per-key and aggregate workspace throttling with `429`/retry guidance;
      allow sensible progress polling separately from generation requests.
      (Step 3: `ThrottleDeveloperApi`, read and write buckets, per caller and
      per workspace; defaults in `config/developer.php`, env-tunable.)
- [x] Limit concurrent generation jobs across all keys in a workspace.
      (Step 3: `max_active_videos`, default 3, counted under the workspace row lock.)
- [~] Enforce per-key spend ceilings and maximum approved operation cost using
      atomic reservations, including downstream stages and retries.
      (Per-key monthly `spend_cap_credits` enforced at create against the quoted
      maximum, summed from the ledger via `projects.api_key_id`. The atomic
      mid-pipeline reservation remains open; per-stage deduction is unchanged.)
- [ ] Add idempotency to costly create/generate/export operations: same request
      returns the same operation, conflicting payload fails without extra spend.
- [ ] Settle/release reservations on completion, failure and cancellation using
      the existing ledger; prevent duplicate charges and refunds.
- [x] Add request/key/workspace/job attribution to operational logs and usage.
      (Log context: api_key_id, workspace_id, user_id on every key request.)
- [x] Tag PostHog events on the key path with `source` and key ID; exclude them
      from activation and usage funnels. (`via: api|app` on project_created,
      project_ready, generation_failed and credit_blocked. Funnel filters in
      PostHog still need updating by hand.)
- [ ] Define retryable errors, paused states and safe recovery instructions.

## A3. Minimal external contract and documentation

- [x] Implement a limited capabilities/balance response without unrelated
      account or billing details. (`GET /api/developer/v1/capabilities`)
- [x] Validate and estimate the selected video flow; return a `quote_id`, credit
      amount, assumptions and a 10-minute expiry. (`POST /quotes`)
- [x] Require `quote_id` plus idempotency key on create; reject expired, foreign
      or mismatched quotes; require balance ≥ quoted maximum and record it on
      the project (hard reservation is the A2 spend-ceiling item).
      Scope: [api-developer-v1-step1.md](api-developer-v1-step1.md). (`POST /videos`)
- [x] Start asynchronous generation and return stable project/job identifiers.
- [x] Expose structured progress, paused/failure reasons and recovery options.
      (`GET /videos/{id}`; "paused" is not a state the pipeline has today.)
- [x] Expose export readiness and authorized expiring download/project links;
      never implicitly enable public sharing. (`GET /videos/{id}/result`)
- [ ] Document versioned endpoints, schemas, authentication, key lifecycle,
      charging, limits, idempotency, polling, errors and link expiry.
- [ ] Supply and validate an OpenAPI schema and working examples for GPT Actions
      if that is the pilot client.
- [ ] Include saved voice/character discovery and selection only as required by
      the selected flow, with ownership and compatibility checks.

## A4. Private pilot acceptance gate

All A1–A3 items applicable to the chosen contract must be complete before release.

- [ ] HTTP tests prove allowed operations work and forbidden operations fail.
- [x] Test cross-workspace access, membership removal, role/plan downgrade,
      inactive users, suspended workspace/parent and revoked/expired keys.
- [x] Test concurrent key issuance and deterministic prefix-collision cases.
      (Prefix collision tested; issuance limit tested; the lock itself is not
      exercised concurrently in sqlite.)
- [ ] Test concurrent/replayed generation, payload conflicts, exhausted credits,
      spend caps, downstream failures and settlement/refund behavior.
- [x] Test expired, foreign and payload-mismatched `quote_id` on create.
- [ ] Complete an end-to-end client run within an agreed test budget: estimate,
      authorize, create, poll, export and retrieve the video.
- [ ] Verify deployed code, migrations and service health; record release evidence.
- [ ] Issue the pilot credential securely through the supported management path
      after checks pass; record expiry, limits and support contact.
- [ ] Have the customer independently complete the workflow; record failures,
      useful-video completion, repeat usage, credits and support effort.

## B. Supported customer API rollout

Depends on a successful Stage A pilot.

- [ ] Add dashboard issuance, one-time display, revocation, expiry and rotation.
- [ ] Show per-key usage and spending limits without exposing secrets.
- [ ] Publish tested documentation and state versioning/support expectations.
- [ ] Align pricing/settings copy with actual entitlements and released operations.
- [ ] Review pilot evidence and explicitly enable broader availability.

## C1. Reusable media expansion

Depends on Stage A controls; release each operation independently.

- [ ] Add bounded image/audio uploads with actual type, size, duration and
      ownership validation; protect remote imports if supported.
- [ ] Add voice cloning with required consent, plan allowances, estimates,
      idempotency, asynchronous status and saved voice IDs.
- [ ] Add authorized voice previews with disclosed costs where applicable.
- [ ] Add character creation using supported descriptions/reference assets and
      enforce character limits.
- [ ] Add character-image generation, cost estimates, progress and saved results.
- [ ] Publish model/resource compatibility and fail clearly on unsupported inputs.
- [ ] Test consent, foreign assets, plan limits, duplicate jobs and settlement;
      document each operation before exposing it through API or MCP.

## C2. Direct editor operations

Depends on Stage A; reuse editor services and state transitions.

- [ ] Return project revision, ordered scenes, readiness and export freshness.
- [ ] Return project-specific operation/settings schemas, ranges and restriction reasons.
- [ ] Add script updates and scene reordering; separately specify supported
      addition/duplication operations. Keep scene deletion outside the first release.
- [ ] Add owned visual assignment and supported image/animation operations.
- [ ] Add voice selection/settings and affected-narration regeneration.
- [ ] Add supported caption styles/placement and music/sound selection/settings.
- [ ] Add supported output options and regeneration/export operations.
- [ ] Preserve narration-controlled timing and whole-video editing restrictions.
- [ ] Make proposed changes structured and separate from execution; disclose and
      bound any charge for planning itself.
- [ ] Bind approval to exact changes, workspace, project revision and maximum
      spend; expire approval and reject stale/conflicting revisions.
- [ ] Preserve stale narration/lip-sync/export indicators; regenerate only affected
      content and make older-export delivery an explicit choice.
- [ ] Test API/dashboard behavior parity, partial failures, concurrent edits,
      approval changes and spending limits; document recovery.

## C3. Optional in-app assistant delegation

Depends on working direct editor tools; pursue only with a concrete need.

- [ ] Audit the assistant's existing tools and execution behavior before reuse.
- [ ] Expose bounded proposal generation with proposal/run IDs and structured results.
- [ ] Route execution through the same revision, permission, approval and spend checks.
- [ ] Prevent recursive delegation, implicit execution and broader tool access.
- [ ] Test expired/modified proposals, unauthorized actions and partial recovery.

## D. MCP server

Delivered with Stage A over the `/api/developer/v1` namespace. C1–C3 tools are
added only after their own gates pass.

### D1. Server and transport

- [x] Add the `mcp` Node sidecar (official TypeScript SDK) to both compose files;
      it forwards the caller's bearer token and holds no credentials of its own.
      (`framecast-app/mcp/`, SDK v2.1: `@modelcontextprotocol/server|express|node`.)
- [x] Serve Streamable HTTP at `/mcp`, stateless; add the nginx location with
      proxy buffering off and long read/send timeouts. (nginx change is in the
      repo, not yet deployed.)
- [x] Restrict CORS to the confirmed client origins. (Host/Origin validation via
      `MCP_ALLOWED_HOSTS`; prod = app.wyvstudio.com. Add client origins when known.)
- [~] Health endpoint done (`/healthz`); structured per-request logs still to add.

### D2. Authentication

- [x] Bearer-header path: accept a WyvStudio API key and pass it through; verify a
      revoked key fails on the next tool call. (Verified by asking the API; cached
      60s by hash, so revocation lands within a minute.)
- [x] OAuth path: authorization server metadata, dynamic client registration,
      PKCE, protected-resource metadata, consent screen bound to one workspace,
      disconnect and revocation. Tokens map to the same key model and allowlist.
      (Scope: [api-oauth-scope.md](api-oauth-scope.md). Laravel server +
      `/oauth/authorize` SPA consent page + sidecar discovery + nginx. Verified
      end to end on the dev stack with the test account; `OAuthFlowTest` (9 tests).
      A real ChatGPT connector run against the deployed build is still to do.)

### D3. Tools

- [x] `get_capabilities`, `estimate_video`, `create_video`, `get_video_status`,
      `get_video_result`, each a one-to-one wrapper over a developer-namespace
      operation; `readOnlyHint` on the four read-only tools.
- [x] `create_video` requires `quote_id`; description states it spends credits.
      (Idempotency key defaults to the quote id, which is single-use.)
- [x] `create_video` returns within seconds and points the assistant at
      `get_video_status`; no blocking on renders.
- [x] `get_video_result` returns the expiring download link as a resource link
      plus the project link; never enables public sharing.
- [ ] Add voice/character listing tools only if the chosen flow needs them.

### D4. Testing and documentation

- [x] Smoke-tested locally over raw JSON-RPC (`mcp/smoke.sh`): 401s, initialize,
      tools/list, capabilities, quote, validation error, not_found. Inspector run
      with a real client still to do.
- [ ] Test workspace isolation across two keys, revoked key mid-session, expired
      and foreign `quote_id`, replayed `create_video`, client retry after timeout,
      and result retrieval without public sharing.
- [ ] One end-to-end run in the confirmed client within an agreed test budget.
- [ ] Publish connection steps per client, header format, the five tools with
      example calls, quote and idempotency behaviour, and supported-client list
      on the `docs` site.
- [ ] If the customer uses a private GPT with Actions instead, publish the
      OpenAPI schema for the developer namespace; no MCP work required.

## Evidence log

| Date | Checklist item / release gate | Commit or PR | Validation | Deployment / availability |
|---|---|---|---|---|
| 25 September 2026 | Tracker created from plan v1.2 | Not committed | Documentation only | No release performed |
| 25 September 2026 | Updated to plan v1.3: MCP as pilot surface, developer namespace, quote-bound create | Not committed | Documentation only | No release performed |
| 25 September 2026 | OAuth for connectors: Laravel authorization server (DCR, PKCE, refresh rotation, revocation), SPA consent page with login carry-through, sidecar discovery, nginx | Not committed | `OAuthFlowTest` 9 tests; suite 589 passed (pre-existing renewal failure only); SPA build clean; curl flow through one-off api + sidecar on test account | Migration on dev DB; not deployed |
| 25 September 2026 | Key expiry + rotation, per-key monthly spend cap, log context, analytics `via` tag; narration now quoted on the routed engine (Gemini 3cr) instead of a flat 1cr | Not committed | 3 new tests; suite 579 passed, pre-existing renewal failure only | Migration on dev DB; not deployed |
| 25 September 2026 | Sidecar: `mcp/` Node service, compose (dev+prod) and nginx wiring, five tools, bearer pass-through; platform admins may manage keys | Not committed | `mcp/smoke.sh` against the rebuilt dev stack on the test account; PHP suite green | Dev stack only; nginx/compose prod changes not deployed |
| 25 September 2026 | Step 3: developer-namespace throttling (read/write buckets, caller + workspace) and an in-flight video cap | Not committed | 3 new tests; full suite green except the pre-existing renewal failure | Not deployed |
| 25 September 2026 | Step 2: issuer role/membership/suspension parity on the key path, hash lookup with unique index, owner/admin key management under a row lock | Not committed | 7 new tests; full suite green except the pre-existing renewal failure | Migration applied to dev DB only; not deployed |
| 25 September 2026 | Step 1: developer namespace, five endpoints, quote-bound create, project creation extracted to a service | Not committed | `DeveloperApiTest` (14 tests) + full suite 567 passed; 1 pre-existing unrelated failure in `SubscriptionRenewalTest` | Migration applied to dev DB only; not deployed |
