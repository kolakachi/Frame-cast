# API/MCP release closure

26 September 2026. Implementation: `ad4c498`; safety review: `5f507be` and
`8502d4b`, present in local Git. The user-supplied deployment report records
`8502d4b` live, both remaining migrations applied, workers restarted, no project
or scene triggers, accounting disabled, and MCP 1.7.0 exposing 43 tools.
This report was not independently rechecked against production in this closure.
Phase F documentation and contract extension remain local.

A4 is still partial: developer API requests are serialized, but a dashboard or
worker write can race between revision checking and applying changes. Local
regression passes do not close that window. Do not restore the broad write
triggers removed during review; they could reject ordinary concurrent work.

## Evidence and boundaries

| Backlog | Implementation | Local evidence | Deployment |
| --- | --- | --- | --- |
| A1–A8 | ad4c498 | DeveloperApiTest reservations, debit/refund attribution, rotation, target immutability, revision/freshness, retry, sync child jobs, pending/stalled operations; historical PostgreSQL/Redis probe recorded in Phase A guide (removed-trigger checks no longer establish current guarantees) | Deployment reported at 8502d4b; current family smoke unverified; accounting default remains disabled |
| B1–B6 | ad4c498 | Editor settings, null forwarding, recorded MCP payload persistence, bulk edits, scene-bound animation history, immutable proposals | Deployment reported at 8502d4b; current family smoke unverified |
| C1–C3 | ad4c498 | Upload validation, clone consent/quota/scope, catalogue voice save, narration choices, new character reference consent | Deployment reported at 8502d4b; current family smoke unverified |
| D1–D3 | ad4c498 | Mode validation, reference analysis, quoted preview replay, UGC export replay, stale narration and queued spokesperson guards | Deployment reported at 8502d4b; current family smoke unverified; no paid provider smoke |
| E1–E3 | ad4c498 | Durable assistant handoffs, version checks, viewer/workspace restrictions, no email/share creation, explicit scope | Deployment reported at 8502d4b; current family smoke unverified; external delivery stays in app |
| F | Local closure | Real MCP discovery/forwarding, schema rejection, structured API errors, actual 30-second timeout, operation polling and identical replay | Connector verification pending |

Recorded combined API regression: 139 tests, 1,112 assertions. Accounting-enabled
DeveloperApiTest: 78 tests, 792 assertions. See Phase E guide for the original run.
These are targeted suites, not a claim that the entire repository passes. Known
caption-render parity failures and the historical renewal scheduler failure are
separate follow-ups. Shared controller reuse alone does not establish parity.

The MCP contract uses a mock backend. It proves transport and payload behavior;
Laravel and the separate concurrency probe establish backend replay/accounting
behavior. It does not prove a real provider will succeed or a deployed connector
will discover the current tool inventory.

## Repeatable local contract check

Provide a disposable directory containing the MCP package dependencies installed
from `mcp/package.json`, then run from `framecast-app`:

```sh
MCP_TEST_DIRECTORY=/tmp/wyv-phase-b-mcp node mcp/tests/editor-contract.mjs
MCP_EDITOR_PAYLOADS=/tmp/wyv-phase-b-mcp/editor-payloads.json php api/vendor/bin/phpunit --configuration api/phpunit.xml --filter DeveloperApiTest
PHASE_A_ACCOUNTING_TESTS=1 MCP_EDITOR_PAYLOADS=/tmp/wyv-phase-b-mcp/editor-payloads.json php api/vendor/bin/phpunit --configuration api/phpunit.xml --filter DeveloperApiTest
```

The contract opens ephemeral localhost ports, makes no production/provider calls,
and takes at least 30 seconds to exercise the real sidecar timeout.

## Release gates still open

> **Incident, 26 September 2026 06:31 UTC, about 4 minutes.** Recreating the
> api container by hand to load the flag gave it a new address; nginx resolves
> `fastcgi_pass api:9000` at start and kept the old one, so every API request
> answered 502 until nginx was restarted. The GitHub deploy never hits this
> because it recreates nginx too. Manual recreates must restart nginx in the
> same step; recorded in the deploy notes.
>
> **Second incident, 06:37 UTC, about 6 minutes, wyvstudio.com only.** The fix
> for the first (per-request upstream resolution in the shared
> `fastcgi-api.conf`) broke the marketing container, which bind-mounts the same
> include but whose server block did not define `$api_upstream`; it
> crash-looped on config load. Hot-patched on the server and committed
> (`38dfbb3`). The app was unaffected. Lesson recorded: that include is shared
> by two nginx containers; validate both configs before changing it.

26 September 2026: character reference cost is now in every estimate
(`character_reference` in the breakdown), so an accounted character video is
not under-quoted; API access is open to every plan, with the plan's own limits.

- [x] Commit the Phase F documentation and contract extension. (Reviewed and
  pushed 26 September 2026; the contract test was rerun in a disposable
  container before push and passed, including the 30-second timeout path.)
- [x] Supplied report records `8502d4b` deployed, operation/voice-consent
  migrations applied and workers restarted. No trigger migration is required.
- [x] Close or explicitly accept the remaining A4 check-to-apply race. (Closed
  between changes by a per-change fingerprint re-check; accepted within a
  single change as editor semantics. 26 September 2026.)
- [ ] Commit and release the Phase F changes separately; existing deployment
  evidence does not cover these later changes.
- [ ] Verify staging provider completion, refusal and retry across exposed families
  within an explicitly agreed spend budget.
- [x] Check old in-flight jobs and database session affinity before enabling
  operation accounting; follow the Phase A rollout procedure. (26 September
  2026, 06:2x UTC: zero generating projects, exports or queued jobs and zero
  operation rows; `DEVELOPER_OPERATION_ACCOUNTING=true` set in the server env;
  api, three workers and scheduler recreated and each confirmed `true`.)
- [ ] Verify current MCP discovery and quote → approval → apply → operation →
  export through the real connector, plus app handoff confirmation.
  **Owner's ChatGPT smoke, 26 September 2026 (accounting off, MCP 1.7.0, 43
  tools, OAuth grant #3):** discovery, `estimate_video` → approval →
  `create_video` → `get_video_status` → `get_video_result` for project #228
  (30 s stock, 9:16; 6 scenes; export 168 completed; 18 credits charged
  against an 18–24 quote), plus `estimate_ugc` (10 s one-take draft, described
  presenter, 210 credits; the script ran 13.5 s so the quote priced 14 s) and
  a character image (asset 4460, 16 credits). The UGC draft was estimated but
  never submitted: the quote expired unconsumed, so generation through the
  connector is still unverified. Apply/edit, replay and refusals were driven
  from the API side (below), not from ChatGPT. App handoff confirmation still
  open.
- [ ] Record production accounting/ledger and recovery observations before outreach.
  **Accounting re-disabled 26 September 2026:** the first paid operation stalled
  in the accounted-job wrapper (see backlog A6, reopened). Zero-credit
  operations settled correctly; the queued-job lifecycle did not.
  (First accounted operation on production, project #226, a zero-credit
  `update_project` proposal: operation `op_01m3e6mjr8dn9ptg7wwmey6sp9` opened,
  applied, `producer_closed`, status `completed`, `GET /operations/{quote}` reports
  `settled` with the recorded result. Paid-operation observations still pending
  the smoke budget.)
  **API-side smoke, same morning, accounting off:** project #227 (AI stills,
  15 s brief; 3 scenes; export 167, 22.8 s; 138 credits against a 276–368
  quote, the estimator assuming 6–8 scenes); edit on #226 applied
  `regenerate_voice` and refused `animate` with `no_source_image`; same-key
  replay returned the recorded result; a 20-credit capped key was refused
  `key_spend_cap_reached`; an expired quote was refused `quote_expired`.
  **Accounting root causes fixed locally** (backlog A6, 26 September): nested
  synchronous dispatches re-entered the job wrapper, exceptions stranded
  videos silently, the dashboard's in-request re-voice never charged, and
  `rescue()` swallowed budget refusals. Verified on the dev stack with
  `queue:work redis` and the flag on: a verbatim-script stock video (project
  97) ran script → breakdown → brief → hooks → visuals → narration to
  `ready_for_review`; 15 registered jobs all `completed`; operation
  `completed`, `spent=9` (three `tts:gemini` entries carrying the operation
  and key), reservation released; an accounted `regenerate_voice` edit charged
  3 attributed credits. Not yet deployed; the production flag stays off until
  the fix ships and the paid smoke is repeated with it on.

Do not mark these complete using historical production project #226 or local
mock tests. The earlier deployment evidence belongs to earlier commits.
