> **Review note, 26 September 2026:** the `serialize_project_mutations` trigger migration was removed before push. The session fence applies to developer-API routes only. Any step below that verifies trigger rejections (SQLSTATE 55P03) no longer applies.

# Phase A verification and rollout

25 September 2026. Local working-tree implementation; not committed or deployed.
`DEVELOPER_OPERATION_ACCOUNTING` remains false by default. This is the release
checklist for A1–A8, not permission to enable the flag on production.

## Local regression checks

From the repository root, with PHP 8.4 and API Composer dependencies installed:

```sh
php framecast-app/api/vendor/bin/phpunit --configuration framecast-app/api/phpunit.xml --filter 'DeveloperApiTest|OAuthFlowTest|ApiKeyAccessTest|EditorIntegrityTest|ExportFreshnessTest|UgcExecutionTest'
PHASE_A_ACCOUNTING_TESTS=1 php framecast-app/api/vendor/bin/phpunit --configuration framecast-app/api/phpunit.xml --filter DeveloperApiTest
node --check framecast-app/mcp/server.js
```

Recorded results: 102 tests / 804 assertions; accounting enabled: 49 tests /
499 assertions. These use fake providers; they do not incur generation costs.

## Isolated concurrency and queue test

The probe is intentionally pinned to disposable localhost ports. Never redirect
it at the application database or Redis: it creates/drops `phase_a_probe` and
flushes the disposable Redis database. It must have exclusive use of these two
containers and ports. It never mounts application volumes.

```sh
docker run --rm -d --name wyv-phase-a-postgres -p 127.0.0.1:55439:5432 -e POSTGRES_USER=phase_a -e POSTGRES_PASSWORD=phase_a_disposable -e POSTGRES_DB=phase_a postgres:16
docker run --rm -d --name wyv-phase-a-redis -p 127.0.0.1:56379:6379 redis:7-alpine
```

Wait for PostgreSQL readiness (`docker exec wyv-phase-a-postgres pg_isready -U phase_a`).
Then run:

```sh
PHASE_A_QUEUE=redis php framecast-app/api/tests/Integration/phase_a_probe.php
docker stop wyv-phase-a-postgres wyv-phase-a-redis
```

The probe launches separate PHP workers, kills one after a test debit, and checks
that delivery of the same job cannot repeat its paid effect. It also verifies
concurrent key admission, UGC capacity slots, project/scene write fencing,
released jobs, cancelled queue deliveries, shared queue context, migration
backfill and unused-hold settlement. A failed run exits nonzero. The probe's
fake debit is confined to its own schema; no real customer is charged.

## Recovery contract

1. After a timeout, replay with the original quote and idempotency key, or call
   `get_operation` using the original quote/proposal/plan ID.
2. A running operation retains its capacity and unused reservation. `settled`
   refers to execution/accounting; poll the media/export separately.
3. `needs_attention` means an action may have completed without its result being
   saved. Inspect its recorded results, action checkpoints and ledger. Never
   automatically regenerate or refund completed charges on this signal alone.
4. `cancel_operation` requires explicit confirmation. It refuses while an active
   producer/worker owns the operation fence. Once safe, it prevents old jobs from
   running and releases only unused credits; existing results and spend remain.
5. Failed composable regeneration uses `estimate_retry` then `retry_video` with
   that new approved quote. Prior unresolved operations block retry authorization.
   Other edits use fresh proposals after inspection. This is not automatic
   continuation of an uncertain provider request.

## Production release gates

- [ ] Review and commit only task-owned changes; preserve concurrent work.
- [ ] Deploy serially. Apply both new migrations with accounting still disabled:
  `2026_09_25_200000_create_api_operations` and
  `2026_09_25_210000_serialize_project_mutations` — **removed at review, 26 September 2026; not part of the deploy.**
- [ ] Verify migration completion and restart workers onto the same code version.
  PostgreSQL session advisory locks require session affinity: do not put these
  requests/workers behind transaction-pooling connections.
- [ ] In staging, enable accounting and check each exposed operation family with
  the configured providers, including normal finish, provider refusal and retry.
  Run connector discovery and quote → approval → apply → operation → export.
- [ ] Verify production has no old in-flight developer jobs before enablement;
  old payloads carry no operation ID and cannot be retroactively reserved.
- [ ] Enable accounting in a controlled window and monitor `needs_attention`,
  reserved totals, key spend and project-busy conflicts before expanding use.
- [ ] Record the actual deployed commit, migration status and connector evidence
  in the backlog. A local test pass is not production verification.

Do not disable accounting while operations are pending: drain or reconcile them
first. Do not roll back attributed ledger columns on a live populated deployment.
Historical backfill captures only the previous project-owner key approximation;
it cannot reconstruct missing historical initiating-key information.
