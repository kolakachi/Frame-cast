# Create drain and restart

Local implementation, 2026-10-07. Not deployed or verified on Oracle. This is a planned-maintenance procedure;
it does not replace provider reconciliation or prove an uncertain worker has stopped.

## Rollout prerequisites

1. In a quiet maintenance window, apply `2026_10_07_000000_create_create_runtime_controls.php` and deploy the
   matching API, scheduler, planning worker and Node worker code. Keep the existing audience restriction.
2. Enable `CREATE_RUNTIME_CONTROLS_ENABLED=true` consistently in all PHP Create processes. Refresh any cached
   configuration and restart long-lived processes only after checking current activity. Mixed old/new processes
   or a process with the switch off can ignore the pause. This first rollout still needs the old manual quiet window.
3. Complete the durable-planning rollout before relying on the planning journal inventory. Legacy after-response
   planning is not represented there. Existing reference intake, uploads, quotes and HTTP requests are not journaled
   by this drain feature either: allow those requests to finish and verify PHP-FPM activity separately.
4. Verify the scheduler runs `create:check-leases` and `create:recover-planning` each minute. Lease checking flags
   up to 100 expired builds per pass even while draining; it does not release holds or retry work.
5. Verify the Node service's signal delivery and stop policy on the actual worker. `SIGTERM` stops new claims and
   lets the current claimed build finish with heartbeats, provider calls within its existing approval and settlement.
   Repeated `SIGTERM` is still a drain. `SIGINT` uses the immediate-stop path and may require reconciliation.

For systemd, the initial termination signal must go to the coordinator alone, not its entire child process group.
Review a service drop-in like this against the installed unit before applying it:

```ini
[Service]
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec=infinity
```

This intentionally prevents an automatic timeout from killing a customer's active build. Deployment automation
must have its own bounded wait: when that wait expires, abort the deployment, leave the service draining and
alert the operator. Do not fall back to `kill -9` or replace files under a running worker. Verify child cleanup,
service restart behavior and actual process exit in the host drill; Node unit tests do not prove systemd behavior.
For container supervisors, verify equivalent signal routing and grace periods rather than assuming this unit applies.

## Planned maintenance after rollout

Keep `CREATE_ENABLED=true` and the existing paid-execution policy available while active work finishes.
Disabling them can prevent callbacks or settlement. Run these commands from the deployed API environment:

```sh
php artisan create:drain pause --reason="Planned deployment"
php artisan create:drain status
```

The shared database pause takes effect on each admission check without restarting processes. New planning
requests, quotes, approvals, worker claims and expensive intake are refused or deferred. Existing approval and
durable planning request keys still return their saved result. Queued plans/builds remain queued; no additional
hold is taken for rejected admissions. Briefs, saved versions and cancellation remain accessible.

Wait for running planning jobs and builds to finish. The status command reports counts of journal states,
unconfirmed workers and unresolved attempts. A successful command exit means the inventory was read, **not**
that shutdown is safe. Confirm all of the following before restarting:

- No running/cancel-requested build or running planning job remains.
- Every `needs_attention` run/job has been reviewed. Confirm host/container termination and provider receipts;
  a lease timeout, zero active count or old timestamp is not evidence that external work stopped.
- API requests, legacy planning, reference intake and uploads have finished. Retention/migration jobs are not
  paused by this control; coordinate them separately before storage maintenance.
- Host processes and Docker containers match the journal. Preserve run directories, completion/failure files,
  media, provider receipts and budget records needed for recovery.

Stop/drain the Node coordinator and PHP queue workers gracefully. Deploy compatible API, web, worker, sandbox
and art-pack revisions; record their IDs and keep the pause set during health checks. Confirm scheduler operation,
private asset delivery and worker connectivity before resuming:

```sh
php artisan create:drain resume --reason="Deployment checks passed"
php artisan create:recover-planning
php artisan create:drain status
```

Previously queued planning may wait up to the existing five-minute redispatch interval. A queued job already in
Redis may be delivered immediately after resume. Draining does not extend quote validity; users with unapproved,
expired quotes need a fresh quote. Already approved runs keep their recorded allowance.

## Interruption and rollback

- `create:check-leases` transitions expired running/cancel-requested builds to `needs_attention` and logs
  `create.worker_lease_expired`. It retains attempt state, credit reservations and the global unknown-worker block.
  A current worker can report a verified stop through its authenticated stop callback; unknown provider costs
  remain held. This is detection and existing reconciliation support, not automatic host fencing or recovery.
- `CREATE_ENABLED=false` remains the emergency shutdown. It also rejects callbacks; external providers may still
  finish and charge. Record run/provider IDs and reconcile before restart. Do not use it for routine deployment.
- A rollback must still understand the drain row, planning journal and storage catalog. Older code can ignore the
  drain row and cannot safely read remote-only files. Keep admission closed at the deployment boundary when rolling
  back to such code; do not drop these tables or switch the controls flag off while work is active.

## Required release evidence

Record the tested revisions and run IDs for: PostgreSQL admission-versus-pause contention; two PHP processes seeing
the same control; real Node/systemd shutdown during a model wait and render; completion after drain; duplicate and
delayed callbacks; queued planning after resume; API restart and legacy-request quiescence; and lost-worker
reconciliation without duplicate provider work. These remain release gates. Use mock/offline providers first.

For assignment inspection and evidence-backed operator recovery, follow [the worker recovery runbook](create-worker-recovery-runbook.md).
