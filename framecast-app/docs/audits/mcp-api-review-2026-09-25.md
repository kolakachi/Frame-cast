# MCP and developer API audit — 25 September 2026

Reviewed local HEAD `9b26301`, `framecast-app/mcp`, developer controllers,
API-key accounting, relevant OAuth/auth code, `api-access-todo.md` and
`api-expansion-todo.md`. This is a review, not a release verification. No
production writes, paid generations or customer contact were performed.

## Verdict

Do not treat the tracker’s “outreach unblocked” as demonstrated readiness.
The authentication boundary is materially improved over the original API-key
implementation, and the existing focused tests pass, but spending, replay,
revision and delivery guarantees still have gaps.

## Findings

### 1. High — authorized maximum and key cap are not reserved or enforced throughout execution

`ClaimsQuotes.php:64–80` checks historical spend plus the new quote, then marks
the quote consumed without reserving credits. Queued work has not yet charged
its stages, so another request can pass the same cap. Reproduced with two
sequential requests while jobs were faked: both returned 202 with the key cap
set to exactly one quote's maximum. This does not even require concurrent HTTP.

The workspace lock also ends before project creation, so concurrent claims
can count the same in-flight projects and over-admit work. MCP `create_video`,
`apply_edits` and `apply_assistant_plan` advertise an upper spending bound which
the wrapper does not enforce during downstream execution.

Fix: durable operation reservations under the lock, in-flight claims counted
before dispatch, per-operation authorization enforced at every charge, and
settlement/release on terminal states. Mark A2's reservation/settlement checks
incomplete; these are not merely post-pilot improvements while a maximum is promised.

### 2. High — per-key accounting misses expanded operations

`ApiKey::spentThisMonth()` (`app/Models/ApiKey.php:58`) attributes all spend using
`projects.api_key_id`, which identifies the creator of the project. Editing a
project created in the app or by another key does not charge the editing key's
reported monthly usage. Conversely, app edits on a key-created project are
counted against its original key. Character image charges in
`GenerateCharacterImageJob.php:191` have no project/key attribution and are not
counted by this query. Repeated operations can therefore pass a key cap even
after earlier operations have finished charging.

Fix: attribute each debit/refund/reservation to its initiating API operation and
key, independently of project creation. Preserve usage continuity on rotation.
`EditorController::retry` also dispatches without the quote/cap preflight and
needs to participate in these controls.

### 3. High — wrong-target calls can reopen completed proposals

`EditorController.php:202–209` and `AssistantController.php:121–128` check target
identity after claiming, and unconditionally release on mismatch before handling
a replay. Present a completed proposal with its original idempotency key to a
different owned project: claim returns replay, then target mismatch clears
`consumed_at` and `idempotency_key`. Reproduced over HTTP (409 response, consumed
flag cleared). The analogous character-image branch has the same structure.

This invalidates the single-use invariant. Revision checks may stop subsequent
execution, but are not a replacement for immutable consumption.

Fix: validate target/kind and replay payload before mutation. Release only the
claim owned by the current operation, never a completed or another in-flight one.
Bind assistant `only` selection to the idempotency payload as well.

### 4. High — revision checks miss changes and are not atomic with application

`EditorController::revision()` (`:283`) hashes timestamps, scene IDs/order and
project status rather than settings. Two updates within the same second can
produce the same revision; reproduced with a changed project title and a frozen
clock. Apply also checks the revision and then executes without a project-wide
revision claim, so distinct proposals or dashboard edits can race after checking.

Fix: an atomically checked revision counter or canonical content fingerprint,
with an execution claim honored by every editing path. Test concurrent proposals,
concurrent dashboard edits and same-second setting changes.

### 5. High — completed result can silently be an outdated export

`VideoController::result()` (`:131`) chooses the latest completed export without
freshness validation, even when a newer export is rendering or has failed.
`status()` (`:193`) likewise reports completed when an old completed export is
still latest after edits. `EditorController::exports()` omits freshness from its
returned field list. This conflicts with the tracker’s explicit-old-export choice.

Fix: return export ID/revision/freshness; refuse stale delivery by default and
require explicit selection/acceptance of an older version. Poll the specific
new export rather than treating any earlier completed file as success.

### 6. Medium — reference updates bypass the character consent gate

Developer character creation requires consent with reference photos, but
`CharacterController::update()` (`:52`) accepts new reference IDs without consent.
The delegated app update method likewise checks ownership but not new consent.
A description-only character can be created and references then added through
`update_character`, bypassing the creation requirement.

Fix: apply consent requirements when references are introduced/replaced, record
what was acknowledged, and expose the requirement in the MCP update schema.

### 7. Medium — partial execution and transport timeout are not recoverable as documented

`mcp/server.js:35` limits upstream requests to 30 seconds. Editor/assistant
application runs actions synchronously and only stores the aggregate result at
the end. The quote is consumed first but its result pointer is written last.
A retry while execution is in progress returns `quote_consumed`, not a status
handle; a process failure after partial mutations can leave no durable action
results. The sidecar advises a fresh estimate for every `quote_consumed`, which
can lead a customer to repeat changes whose first execution is still running.

Fix: durable queued/running/completed operation states, per-action checkpoints,
pollable application jobs and replay-safe results. Never advise repeating a paid
operation merely because its result is pending or the transport timed out.

## Tracker corrections needed

- Reopen A2 spend reservation, settlement and accurate key attribution.
- Reopen A4 concurrency/timeout/failure acceptance checks; sequential SQLite
  tests do not establish those guarantees.
- Reopen Phase 4 revision and explicit stale-export delivery guarantees.
- Qualify C3 assistant execution safety until it shares durable operation control.
- Keep parity testing incomplete. Delegation shares controller code, but not
  necessarily route middleware, request parameters, lifecycle or accounting.
- D2's “revocation within a minute” needs distinction: cached MCP handshake
  authentication may last a minute, but each tool forwards the token and the
  API revalidates it. Do not imply resource access necessarily lasts a minute.
- Historical evidence rows mix “not deployed” and “live”; record one current
  release status with commit and verification, retaining earlier rows as history.
- Remove unconditional “Ready/outreach unblocked” until the above gates pass.

The report does not establish that every OAuth or MCP protocol edge case is
correct. A client smoke test is useful evidence but does not cover these failures.

## Validation

- Existing `DeveloperApiTest`, `OAuthFlowTest`, `ApiKeyAccessTest` selection:
  **50 tests, 590 assertions passed**, using PHP 8.4.6 directly.
- Three temporary isolated regression probes in `/tmp/McpAuditRegressionTest.php`:
  **3 tests, 8 assertions passed**, asserting the current faulty behavior:
  missing spend reservation, consumed-proposal reset, same-second revision collision.
  These are reproductions, not passing fixes; no paid work was dispatched.
- `node --check framecast-app/mcp/server.js` passed.
- No live MCP client run or production deployment was performed during this audit.
- No implementation or TODO status was changed; this report records the review.
