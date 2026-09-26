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
- [~] Record production accounting/ledger and recovery observations before outreach.
  (First accounted operation on production, project #226, a zero-credit
  `update_project` proposal: operation `op_01m3e6mjr8dn9ptg7wwmey6sp9` opened,
  applied, `producer_closed`, status `completed`, `GET /operations/{quote}` reports
  `settled` with the recorded result. Paid-operation observations still pending
  the smoke budget.)

Do not mark these complete using historical production project #226 or local
mock tests. The earlier deployment evidence belongs to earlier commits.
