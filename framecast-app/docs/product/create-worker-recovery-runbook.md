# Create worker ownership and recovery

Local implementation, 2026-10-07. No production rollout, host termination, provider request or real hold release
was performed to develop this slice. Use the [drain procedure](create-drain-runbook.md) for planned maintenance.

## What is recorded

Each identified claim gets an immutable assignment ID, a configured worker/host label, a process instance UUID,
a slot label, its claim time and last successful heartbeat. The worker includes the assignment and its local PID
in `started.json`; it does not persist the lease token there. The API keeps the lease fingerprint internally.

The instance UUID changes on process restart. A new UUID does **not** prove the previous process stopped. Worker
labels are supplied by the authenticated coordinator and must be configured uniquely; they are not hardware
attestation or separate per-host credentials. Slot labels are diagnostic metadata in this release, not additional
render capacity. The global single-build and unconfirmed-worker admission blocks remain in place.

A stop record comes from either the authenticated lease holder's sandbox-stop acknowledgement or an operator's
assignment-specific confirmation. Both are attestations. The application does not independently SSH into the
host or ask Oracle whether it has been fenced. Provider billing is a separate question.

## Rollout

1. Drain and apply `2026_10_07_010000_create_create_worker_assignments.php` before enabling worker identity.
2. Deploy matching API/scheduler/worker code, preserving artifact directories and recovery journals.
3. On each coordinator, set a unique stable `CREATE_WORKER_ID` (for example, `framecast-create`) and optionally
   `CREATE_WORKER_SLOT=render-1`. Do not configure an instance UUID: the Node process generates it once at startup.
4. Confirm a mock/offline claim returns and persists the matching assignment, and that heartbeat updates it.
   Then enable `CREATE_WORKER_OWNERSHIP_REQUIRED=true` consistently on API processes. It defaults to false for
   staged rollout. Missing identity then rejects a claim; supplied identity always requires the migrated table.
5. Verify the scheduler lease checks, stop acknowledgements and admin trajectory access on the deployed revision.

Legacy runs without assignments keep the earlier explicit operator-confirmation recovery behavior. Disabling the
rollout switch does not bypass recorded-stop requirements for a run that already has an assignment. Do not drop
the assignment table during rollback. The old code does not enforce these controls.

## Inspect first

From the deployed API environment:

```sh
php artisan create:worker-recovery RUN_ID
```

This default is read-only. It shows the exact assignment, expected container names, last heartbeat, run state,
unresolved attempt/provider IDs and credit reservations. It never prints the lease token or fingerprint. The
admin trajectory API/timeline also includes assignment and stop events, without credentials or raw evidence notes.

If the worker is still healthy, drain it and let it finish. For a lost worker:

1. Identify the original host and coordinator instance from the assignment and local journal. Inspect the old
   process and the exact `wyv-create-RUN_ID` and `wyv-create-RUN_ID-delivery` containers. A PID alone can be reused.
2. Stop/fence the original coordinator and its sandboxes using the host's approved operations procedure. If the
   host is unreachable, use independently verified infrastructure fencing; do not substitute lease expiry or a
   new worker instance as evidence. Preserve journals, artifacts, provider responses and billing records.
3. Record the host/process/container checks in the incident record, including the time and operator. Never put
   keys, bearer tokens or raw provider responses into the evidence argument below.
4. After those checks, record the exact inspected assignment:

```sh
php artisan create:worker-recovery RUN_ID --confirm-stopped=ASSIGNMENT_ID --worker-stopped --evidence="incident-123: original process and both containers confirmed stopped"
```

This requires an interrupted (`needs_attention`) or failed run and a matching assignment/lease fingerprint.
It revokes the old lease and sets execution capacity to zero. It does **not** alter charged/reserved credits,
attempt outcomes or provider jobs, and never requeues the run. A repeated confirmation is a no-op; the first
evidence record stays intact. The application cannot validate the truth of a written incident reference.

After a recorded stop, unrelated healthy queued work can use the existing single slot. The affected conversation
remains held while its costs are uncertain. An unconfirmed worker still blocks claims globally. Restarting a
coordinator does not automatically collect or replay its interrupted jobs.

## Reconcile without generating again

For an assigned run, all recovery services now require a durable stop record; `--worker-stopped` alone cannot
bypass it. Provider state and cost must still be established using receipts.

- If every attempt already has a complete settlement receipt (or there were no attempts), close the held run as
  a separate explicit action:

  ```sh
  php artisan create:worker-recovery RUN_ID --close-settled
  ```

  This uses the existing accounting reconciliation. Unknown calls or unsettled operation jobs cause rejection;
  unused credits are released only on successful closure. It does not render, generate or automatically retry.
- For an app-saved Anthropic response, use `create:recover-provider-receipt ATTEMPT_ID --worker-stopped` after
  recording the stop. It derives cost from the saved original response/rates; no new generation is sent.
- For an interrupted Replicate prediction, `create:reconcile-attempt` still requires provider terminal-state
  verification, actual billing cost and a billing reference. It reads provider state; do not invent a zero-cost
  receipt when a provider request ID is missing. Missing evidence remains unresolved.
- Preserve a saved completion and video if final delivery failed. After the run is confirmed failed, stopped and
  fully settled, `create:recover-finished RUN_ID COMPLETION_JSON VIDEO_MP4` records the existing output. Its
  temporary delivery lease and final database changes now share one transaction; a failure rolls them back.
  A competing recovery rechecks state under locks and cannot overwrite a completed version. The copied object
  may remain after a database failure and must be retained/reconciled, not treated as a completed version.

## Outstanding release checks

- PostgreSQL races: stop acknowledgement versus operator confirmation, stale callbacks and competing delivery.
  Current development tests use SQLite and mocked/offline providers.
- Real Oracle process/container stop proof, systemd restart, unreachable-host fencing and receipt recovery.
- Per-host authentication/registration if workers become separate trust boundaries; labels alone do not provide it.
- Automatic host fencing, restart collection of interrupted journals, operator alerts and a bounded response
  procedure. These are not implemented by this slice. Do not remove the unknown-worker block before that proof.
- Recovery files/assignment rows must be included in the restore and retention design. No worker directory was
  pruned by these changes.
