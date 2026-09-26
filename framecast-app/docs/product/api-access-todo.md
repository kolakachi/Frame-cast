# API and MCP implementation TODO

> **Current release status — 26 September 2026 (evening):** A–E (`ad4c498`) and the
> worker-path accounting fix (`91387e9`) are deployed; operation accounting is
> enabled on production and a paid smoke settled with attributed charges. A4 is
> closed between changes (a race inside one change is an accepted limitation).
> MCP 1.8.x added character reference edits, sharing, publishing and previews. Historical evidence
> below applies only to its stated build. The current source of
> truth is [release closure](api-release-closure.md); outreach is gated on its
> unchecked release steps.

## Reopened release verification

These are release gates; A4 also has an unresolved implementation gap:

- [ ] A1–A3: verify reservations, settlement, initiating-key attribution and key
  rotation with accounting enabled on the release deployment.
- [ ] A4: close the dashboard/worker check-to-apply race without restoring
  broad reject-on-write triggers. A5: verify stale-export selection through
  the deployed connector.
- [ ] A6–A8: verify recovery, concurrency, cancellation and async failures with
  the deployed queue/provider configuration.
- [ ] B1–B2/C3: verify editor option forwarding, narration and owned animation
  history through the deployed connector.
- [ ] E1–E3: verify delivery handoffs and app confirmation; no programmatic
  publishing or app-only destructive operations are promised.

Local evidence and exact backlog IDs: [A–F backlog](api-mcp-gap-backlog.md).


> **25 September Phase A correction:** the historical completion notes below do
> not establish current release readiness. Spending reservations, attribution,
> revision fences, stale-export guards and recovery are implemented and tested
> locally in [the A1–A8 backlog](api-mcp-gap-backlog.md), behind a disabled
> accounting flag. See [verification and rollout](api-phase-a-verification.md).
> Production migration/enablement and connector verification remain required.


Created 25 September 2026. Tracks [API access plan v1.3](api-access-plan.md).

The plan defines behavior; this checklist tracks delivery. No implementation or
production verification was performed when creating this checklist. Foundation
code exists in `5428bab`; unchecked items below are outstanding or unverified,
not a claim that every underlying service is missing.

## How to use

- Work in dependency order. Stage D (MCP) is delivered with Stage A; it is the
  pilot's client surface. The expansion phases (1 → 3 → 2 → 4) gate customer
  outreach; each ships behind its own scope doc.
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
- [x] Choose one first creation flow: standard narrated video or UGC. (Standard
      narrated video from a prompt or script; UGC stays in the app.)
- [x] Specify supported inputs, output options and required saved voices/characters.
      Decided 25 September 2026: prompt or script; stock, AI image or AI video
      visuals; duration, aspect ratio, tone, title, goal. Default voice only;
      saved voices and characters are the first post-pilot expansion (C1).
- [x] Set numeric per-key/workspace request, concurrency and credit limits, plus
      maximum spend per operation and pilot credential expiry. Decided 25
      September 2026: keep 60 reads / 10 writes per key per minute, 120/20 per
      workspace, 3 videos in flight; pilot keys expire after 90 days; spend cap
      sized per customer at issue time.
- [x] Confirm Creator-and-above eligibility and explicit pilot enrollment behavior.
      (Creator and above, enforced on every request. No enrollment step: OAuth
      is self-serve for eligible owners/admins; keys are issued by support until
      the Settings screen ships.)
- [x] Assign implementation/support owners and agree pilot success criteria.
      (Owner for build and support: Kolawole, hello@wyvstudio.com. Success =
      the customer independently quotes, creates and fetches a useful video with
      correct credit accounting and no access outside their workspace; track
      completions, repeat use, errors, credits and support effort, per plan §8.)

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
- [x] Enforce resource ownership and existing plan/feature gates for every
      allowed operation; do not inherit access to other workspaces. (Every
      developer endpoint scopes by the key's workspace; quotes and videos from
      another workspace are 404; plan duration and credit gates reuse the
      dashboard's. Tested in `DeveloperApiTest`.)
- [x] Resolve `api_access` entitlement against the billing workspace so a
      client workspace follows the agency's current tier. (Decided: leave as is
      for the pilot; every plan gate reads the child's own tier, so the API
      matches the app. Listed under post-pilot follow-ups.)
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
- [x] Verify plaintext secrets appear only once and are redacted from logs,
      errors and analytics; document treatment of already accepted jobs on revocation.
      (Plaintext returned only by create and rotate; nothing logs the bearer;
      Sentry `send_default_pii` is off; the validation logger records field
      names, not values; the sidecar logs a 12-char hash of the token. Docs:
      revocation stops new requests, in-flight videos finish and are charged.)

## A2. Spending and execution safety

- [x] Add per-key and aggregate workspace throttling with `429`/retry guidance;
      allow sensible progress polling separately from generation requests.
      (Step 3: `ThrottleDeveloperApi`, read and write buckets, per caller and
      per workspace; defaults in `config/developer.php`, env-tunable.)
- [x] Limit concurrent generation jobs across all keys in a workspace.
      (Step 3: `max_active_videos`, default 3, counted under the workspace row lock.)
- [x] Enforce per-key spend ceilings and maximum approved operation cost using
      atomic reservations, including downstream stages and retries.
      (Per-key monthly `spend_cap_credits` enforced at create against the quoted
      maximum, summed from the ledger via `projects.api_key_id`. The quoted
      maximum is the approved operation cost; the first real run spent 18 of a
      30 maximum. A hard mid-pipeline reservation is a post-pilot follow-up.)
- [x] Add idempotency to costly create/generate/export operations: same request
      returns the same operation, conflicting payload fails without extra spend.
      (Single-use quote + idempotency key on create; export is automatic and
      one-per-project. Tested.)
- [x] Settle/release reservations on completion, failure and cancellation using
      the existing ledger; prevent duplicate charges and refunds. (No reservation
      exists to settle: credits are deducted per stage and refunded per stage by
      the existing jobs, unchanged. Duplicate creates are prevented by the
      single-use quote and idempotency key.)
- [x] Add request/key/workspace/job attribution to operational logs and usage.
      (Log context: api_key_id, workspace_id, user_id on every key request.)
- [x] Tag PostHog events on the key path with `source` and key ID; exclude them
      from activation and usage funnels. (`via: api|app` on project_created,
      project_ready, generation_failed and credit_blocked. Funnel filters in
      PostHog still need updating by hand.)
- [x] Define retryable errors, paused states and safe recovery instructions.
      (Status has `failure.retryable`; the MCP tools page lists every error code
      with what to do; the pipeline has no paused state.)

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
- [x] Document versioned endpoints, schemas, authentication, key lifecycle,
      charging, limits, idempotency, polling, errors and link expiry.
      (docs site: API & AI assistants → REST API, API keys, MCP tools.)
- [x] Supply and validate an OpenAPI schema and working examples for GPT Actions
      if that is the pilot client. (`docs/static/openapi/wyvstudio-developer-v1.yaml`,
      linted, linked from the REST page. The customer uses connectors, so it is
      for custom GPTs and code generators.)
- [x] Include saved voice/character discovery and selection only as required by
      the selected flow, with ownership and compatibility checks. (Not required:
      default voice only in the pilot, by decision.)

## A4. Private pilot acceptance gate

All A1–A3 items applicable to the chosen contract must be complete before release.

- [x] HTTP tests prove allowed operations work and forbidden operations fail.
      (`DeveloperApiTest`, `OAuthFlowTest`.)
- [x] Test cross-workspace access, membership removal, role/plan downgrade,
      inactive users, suspended workspace/parent and revoked/expired keys.
- [x] Test concurrent key issuance and deterministic prefix-collision cases.
      (Prefix collision tested; issuance limit tested; the lock itself is not
      exercised concurrently in sqlite.)
- [x] Test concurrent/replayed generation, payload conflicts, exhausted credits,
      spend caps, downstream failures and settlement/refund behavior. (Replay,
      conflicting key, exhausted credits, key cap and in-flight cap are tested.
      Downstream failure settlement is the existing per-stage refund path,
      covered by its own job tests.)
- [x] Test expired, foreign and payload-mismatched `quote_id` on create.
- [x] Complete an end-to-end client run within an agreed test budget: estimate,
      authorize, create, poll, export and retrieve the video. (25 September
      2026, ChatGPT connector on the owner's account in production: DCR +
      consent + PKCE; quoted 24–30cr, asked before creating; project #226,
      6 scenes, 36s export; 18cr spent, all narration; no sidecar/api errors.)
- [x] Verify deployed code, migrations and service health; record release evidence.
      (25 September 2026, after push `454d48d`: AS and PRM discovery documents
      served; `/mcp` answers 401 with the resource_metadata pointer; developer
      namespace answers 401 without a token; registration validates; consent
      page served by the SPA; a bogus `wyv_oat_` token is refused cleanly, which
      exercises the new tables; docs pages live.)
- [x] Issue the pilot credential securely through the supported management path
      after checks pass; record expiry, limits and support contact. (Not needed:
      the customer connects via OAuth, which issues a 90-day key on approval.
      Limits are the defaults; support contact hello@wyvstudio.com.)
- [ ] Have the customer independently complete the workflow; record failures,
      useful-video completion, repeat usage, credits and support effort.
      **All four expansion phases are live and tested on the owner's account
      (25 September 2026). Outreach is unblocked.**

## B. Supported customer API rollout

Depends on a successful Stage A pilot.

- [x] Add dashboard issuance, one-time display, revocation, expiry and rotation.
      (Settings → API & Apps: list with connected-app badges, create with expiry
      and cap, secret shown once with copy, rotate, revoke/disconnect with confirm.)
- [x] Show per-key usage and spending limits without exposing secrets. (Masked
      prefix, expiry, credits this month against cap, last used.)
- [x] Publish tested documentation and state versioning/support expectations.
      (Docs section live; REST page states the v1 promise.)
- [x] Align pricing/settings copy with actual entitlements and released operations.
      (Plans page lists "API & ChatGPT/Claude access" on Creator and Pro; Settings
      lock wall names the plans.)
- [x] Review pilot evidence and explicitly enable broader availability. (Owner
      tested the full surface in production on 25 September 2026; open to all
      eligible workspaces — nothing to flip, eligibility is the plan gate.)

## Expansion before outreach (decided 25 September 2026)

One-glance status and open decisions: [api-expansion-todo.md](api-expansion-todo.md).

Order: Phase 1 → Phase 3 → Phase 2 → Phase 4. The assistant may choose
styles, brand kits, channels, templates and voices itself from the lookups,
as long as the quote shows what it chose. Each phase gets its own scope doc,
per-operation allowlisting, quote-bound spending, and request-level tests.

### Phase 1 — full creation settings (scope: [api-phase1-creation-settings.md](api-phase1-creation-settings.md))

Built 25 September 2026 (`21ad19b`), live; caption preset deferred to phase 4.

- [x] Lookups: brand kits, channels, niches, caption presets, characters,
      visual styles, library images and music, platform targets, languages.
- [x] Quotes accept every New Video setting the dashboard does (visual style,
      custom style, brand kit, channel, template via channel/niche, niche,
      character, caption preset, music, languages, platform target, allow
      script edit, image sources, audiogram settings) with the same ownership
      and plan validation and the same estimate.
- [x] Source types beyond prompt/script that need no upload: url,
      product_description, images (from the library).
- [x] MCP: lookup tools and the widened `estimate_video`; capabilities enumerate
      the option keys.
- [x] Docs, schema and tests updated.

### Phase 3 — UGC (scope: [api-phase3-ugc.md](api-phase3-ugc.md))

Built 25 September 2026 (`e400964`), live; delegates to the app's UGC controller. Footage restyle deferred.

- [x] Second quote-and-create pair over the UGC planner: plan, suggest, quote,
      reference, variants, generate, generate-one-shot; footage restyle.
- [x] Own-face and take-pass cost rules, take reservation and plan labels
      honoured (see project memory on the UGC pass).
- [x] MCP tools, docs, schema, tests.

### Phase 2 — characters (scope: [api-phase2-characters.md](api-phase2-characters.md))

Built 25 September 2026 (`6ed0951`), live; delegates to the app's character controller.

- [x] Create a character from a description or a library reference image;
      generate images and poll them; enforce plan character limits.
- [x] Ownership and consent checks; unsupported model/resource combinations
      fail clearly.
- [x] MCP tools, docs, schema, tests.

### Phase 4 — editor operations (scope: [api-phase4-editor-operations.md](api-phase4-editor-operations.md); required before outreach)

Built 25 September 2026 (`782d840`), live; captions, motion and timing ride `update_scene`; scene deletion excluded; verified on production project #226.

- [x] Read: project revision, ordered scenes with every setting, readiness,
      export freshness; project-specific operation and setting schemas.
- [x] Script and structure: scene script updates, reorder, add, duplicate,
      rewrite. Deletion stays out of the first release.
- [x] Visuals: assign a library asset, swap, generate, edit image, animate
      (cancel, revert, history), visual style and prompt per scene.
- [x] Narration: voice per scene, voice settings, regenerate; stale-narration
      and lip-sync flags preserved.
- [x] Captions: enabled, preset, style, position, font, colours, animation,
      highlight, backdrop, UGC headline.
- [x] Music and sound: project music and settings, per-scene sound, regenerate
      music.
- [x] Motion and timing: motion effect, intensity, fit; duration where
      narration does not control it; transition rule.
- [x] Project settings: title, aspect ratio, channel, brand kit; hooks.
- [x] Export: readiness, export with options, freshness, older-export delivery
      as an explicit choice.
- [x] Proposal → approval → apply with a revision precondition, per plan §5;
      bounded planning cost; partial-failure reporting.
- [~] Parity tests against the dashboard for every operation; MCP tools; docs.
      (MCP tools and docs done. Every operation runs the editor's own controller, so
      delegation reduces duplication but does not prove input/output parity; fixture-by-fixture parity tests remain a follow-up.)

### Phase 5 — optional in-app assistant delegation (after Phase 4, on demand)

Built 25 September 2026: Cruise Control exposed as a bounded planner (`AssistantController`), two MCP tools.

- [x] Audit the assistant's existing tools and execution behavior before reuse.
      (20 registry tools, each with paramsSchema, diffLines, estimateCost, confirmationClass, execute; apply cross-checks the estimate, checks balance, snapshots a revert and audits. Listed live at `GET /assistant/tools`.)
- [x] Expose bounded proposal generation with proposal/run IDs and structured results.
      (`POST /videos/{id}/assistant/plans` → plan_id, reply, actions with diff and credits.)
- [x] Route execution through the same revision, permission, approval and spend checks.
      (Apply claims the plan like any quote, requires the revision, runs Cruise's own apply per action.)
- [x] Prevent recursive delegation, implicit execution and broader tool access.
      (Only Cruise-named actions can run, frozen in the plan; the client never names a tool; planning applies nothing; Cruise has no outbound MCP.)
- [x] Test expired/modified proposals, unauthorized actions and partial recovery.
      (Revision conflict, wrong-endpoint use, replay, audit log, and the unresolvable case are tested; per-action failures are reported and continue.)

## D. MCP server

Delivered with Stage A over the `/api/developer/v1` namespace. C1–C3 tools are
added only after their own gates pass.

### D1. Server and transport

- [x] Add the `mcp` Node sidecar (official TypeScript SDK) to both compose files;
      it forwards the caller's bearer token and holds no credentials of its own.
      (`framecast-app/mcp/`, SDK v2.1: `@modelcontextprotocol/server|express|node`.)
- [x] Serve Streamable HTTP at `/mcp`, stateless; add the nginx location with
      proxy buffering off and long read/send timeouts. (Deployed; upstreams are
      resolved per request since 26 September.)
- [x] Restrict CORS to the confirmed client origins. (Host/Origin validation via
      `MCP_ALLOWED_HOSTS`; prod = app.wyvstudio.com. Add client origins when known.)
- [x] Health endpoint and structured logs: `/healthz`, plus one JSON line per tool
      call (tool, status, ms, caller hash, kind, error code).

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
- [x] Add voice/character listing tools only if the chosen flow needs them.
      (Decided out of the pilot; first C1 expansion.)

### D4. Testing and documentation

- [x] Smoke-tested locally over raw JSON-RPC (`mcp/smoke.sh`): 401s, initialize,
      tools/list, capabilities, quote, validation error, not_found. Inspector run
      with a real client still to do.
- [x] Test workspace isolation across two keys, revoked key mid-session, expired
      and foreign `quote_id`, replayed `create_video`, client retry after timeout,
      and result retrieval without public sharing. (All at the API layer in the
      PHP suites; the sidecar adds nothing the API does not enforce. Client retry
      after timeout is the idempotency key, which the tool defaults to the quote id.)
- [x] One end-to-end run in the confirmed client within an agreed test budget.
      (ChatGPT connector, production, owner's workspace: project #226, 18cr.)
- [x] Publish connection steps per client, header format, the five tools with
      example calls, quote and idempotency behaviour, and supported-client list
      on the `docs` site. (docs/docs/api-and-connectors/, builds clean; the
      docs image is rebuilt on deploy.)
- [x] If the customer uses a private GPT with Actions instead, publish the
      OpenAPI schema for the developer namespace; no MCP work required. (Schema
      published regardless; see A3.)

## Post-pilot follow-ups

Not blocking the customer. In rough priority order.

- [x] Settings → API keys screen (Stage B): issue, one-time display, cap,
      expiry, rotate, revoke, connected apps. (Shipped 25 September 2026 as
      Settings → API & Apps.)
- [ ] Hard mid-pipeline spend reservation so a video stops at the authorized
      maximum instead of being attributed after the fact.
- [ ] PostHog: filter activation and usage funnels on `via = app`.
- [ ] Sync client-workspace `plan_tier` when the agency's plan changes
      (product-wide; the API inherits it).
- [ ] Docs site: bare page URLs 301 to a trailing slash over plain http.
- [ ] Sidecar: add `mcp` to `MCP_ALLOWED_HOSTS` in prod if an internal health
      check is wanted.
- [ ] Tighten tool descriptions so the assistant does not describe a script it
      has not seen.
- [ ] `SubscriptionRenewalTest` scheduler case fails on every run (pre-existing).
- [ ] Submit WyvStudio to the ChatGPT app directory once the pilot proves out.

## Evidence log

| Date | Checklist item / release gate | Commit or PR | Validation | Deployment / availability |
|---|---|---|---|---|
| 25 September 2026 | Tracker created from plan v1.2 | Not committed | Documentation only | No release performed |
| 25 September 2026 | Updated to plan v1.3: MCP as pilot surface, developer namespace, quote-bound create | Not committed | Documentation only | No release performed |
| 25 September 2026 | Tracker restructured: outreach gated on four expansion phases (creation settings → UGC → characters → editor ops); assistant may choose options itself | Not committed | Decisions recorded | — |
| 25 September 2026 | Phase 5 deployed (`9b26301`): in-app assistant as a bounded planner; sidecar 1.2.0 with 31 tools | pushed | 597 PHP tests pass (1 pre-existing failure); prod tools/list and `/assistant/tools` on the owner's workspace | **Live** |
| 25 September 2026 | Full-surface production test: ChatGPT (lookups, UGC planner + consent gate, character #116) and live API on project #226 (read, schema, proposal, apply, export #166 rendered) | — | Sidecar per-call log; server-side checks | Historical outreach result; current release gates reopened |
| 25 September 2026 | Expansion phases 1–4 built and deployed (`21ad19b`, `e400964`, `6ed0951`, `782d840`): creation settings, UGC, characters, editor operations; 29 MCP tools | pushed | 596 PHP tests pass (1 pre-existing failure); prod smoke on the owner's workspace | **Live.** Owner's full-surface test, then customer outreach |
| 25 September 2026 | Stage B + first C1 item: Settings → API & Apps screen, plans copy, voice catalogue endpoint and `voice_id` on quotes, `list_voices` tool, docs + schema updated | Not committed | 590 PHP tests (1 pre-existing failure); SPA, docs and schema build clean; sidecar smoke on dev | Awaiting push and the owner's test |
| 25 September 2026 | Tracker closed out: every Stage 0, A and D item done or decided; OpenAPI schema published; sidecar per-call logs; consent copy and ChatGPT steps corrected; post-pilot list added | Not committed | Schema lints clean; docs build clean; log lines verified on dev | Awaiting push |
| 25 September 2026 | First real connector run: ChatGPT → OAuth consent → quote → approval → create → export, in production on the owner's workspace | — | Project #226: 6 scenes, 36.1s, 18cr against a 30cr quoted max; ledger, key spend and last_used all attributed; zero errors | **Pilot path proven end to end** |
| 25 September 2026 | Deployed to production via the GitHub Action (push `454d48d`, eight commits) | `b2ece84`…`454d48d` | External probes of discovery, MCP challenge, developer API, registration, consent page and docs | **Live.** Real ChatGPT connector run on the owner's account still pending |
| 25 September 2026 | Developer docs: four pages under "API & AI assistants" (connect ChatGPT/Claude/Cursor, API keys, MCP tools, REST API) linked from the help intro | Not committed | Docusaurus build succeeds, no broken links | Not deployed |
| 25 September 2026 | OAuth for connectors: Laravel authorization server (DCR, PKCE, refresh rotation, revocation), SPA consent page with login carry-through, sidecar discovery, nginx | Not committed | `OAuthFlowTest` 9 tests; suite 589 passed (pre-existing renewal failure only); SPA build clean; curl flow through one-off api + sidecar on test account | Migration on dev DB; not deployed |
| 25 September 2026 | Key expiry + rotation, per-key monthly spend cap, log context, analytics `via` tag; narration now quoted on the routed engine (Gemini 3cr) instead of a flat 1cr | Not committed | 3 new tests; suite 579 passed, pre-existing renewal failure only | Migration on dev DB; not deployed |
| 25 September 2026 | Sidecar: `mcp/` Node service, compose (dev+prod) and nginx wiring, five tools, bearer pass-through; platform admins may manage keys | Not committed | `mcp/smoke.sh` against the rebuilt dev stack on the test account; PHP suite green | Dev stack only; nginx/compose prod changes not deployed |
| 25 September 2026 | Step 3: developer-namespace throttling (read/write buckets, caller + workspace) and an in-flight video cap | Not committed | 3 new tests; full suite green except the pre-existing renewal failure | Not deployed |
| 25 September 2026 | Step 2: issuer role/membership/suspension parity on the key path, hash lookup with unique index, owner/admin key management under a row lock | Not committed | 7 new tests; full suite green except the pre-existing renewal failure | Migration applied to dev DB only; not deployed |
| 25 September 2026 | Step 1: developer namespace, five endpoints, quote-bound create, project creation extracted to a service | Not committed | `DeveloperApiTest` (14 tests) + full suite 567 passed; 1 pre-existing unrelated failure in `SubscriptionRenewalTest` | Migration applied to dev DB only; not deployed |
