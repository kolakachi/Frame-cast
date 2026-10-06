#!/bin/sh
# One render slot per shared output volume. Never replay a command on contention.
set -eu
if [ "${1:-}" = "--slot-acquired" ]; then
  shift
  echo 'WYV_SANDBOX_ACQUIRED' >&2
  exec "$@"
fi
wait_seconds=${CREATE_SANDBOX_WAIT_SECONDS:-600}
case "$wait_seconds" in ''|*[!0-9]*) echo 'Invalid sandbox queue wait' >&2; exit 64;; esac
if [ "$wait_seconds" -lt 1 ] || [ "$wait_seconds" -gt 600 ]; then
  echo 'Sandbox queue wait must be between 1 and 600 seconds' >&2
  exit 64
fi
echo 'WYV_SANDBOX_WAITING' >&2
# --no-fork keeps cancellation directed at the waiting lock holder or its command.
# flock releases the lock on normal exit, failure and process termination.
exec flock --no-fork --wait "$wait_seconds" --conflict-exit-code 75 /sandbox-lock/worker.lock /bin/sh "$0" --slot-acquired "$@"
