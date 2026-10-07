#!/usr/bin/env bash
# Update the Create build worker (framecast-create) to the committed worker code at HEAD.
#
#   framecast-app/ops/deploy_create_worker.sh            # deploy HEAD
#   WAIT_SECONDS=1800 framecast-app/ops/deploy_create_worker.sh
#
# A push to master does not update the worker. This script:
#  1. packages framecast-app/hyperframes-worker at HEAD (committed code only);
#  2. drains every worker slot (SIGTERM: no new claims, the build in hand finishes), waiting at most WAIT_SECONDS;
#     if a build is still running then, it aborts and leaves the slots draining (they claim nothing new) - rerun later;
#  3. replaces the code, keeping runtime/art-packs, artifacts and node_modules;
#  4. reinstalls packages / rebuilds the sandbox image only when their inputs changed since the deployed revision;
#  5. starts the slots again and checks they are running.
# Queued builds wait while the slots are stopped; nothing is lost.
set -euo pipefail

HOST=${HOST:-framecast-create}
ROOT=/opt/wyv-create
WAIT_SECONDS=${WAIT_SECONDS:-5400}
UNITS="wyv-create-worker wyv-create-worker@2 wyv-create-worker@3"

repo=$(git rev-parse --show-toplevel)
cd "$repo"
if [ -n "$(git status --porcelain --untracked-files=no -- framecast-app/hyperframes-worker)" ]; then
  echo "Worker code has uncommitted changes; commit them first (only committed code is deployed)." >&2
  exit 1
fi
rev=$(git rev-parse HEAD)
short=${rev:0:8}
tar_file=$(mktemp -t create-worker-XXXXXX).tar
trap 'rm -f "$tar_file"' EXIT
git archive --format=tar --prefix=hyperframes-worker/ "HEAD:framecast-app/hyperframes-worker" > "$tar_file"
scp -q "$tar_file" "$HOST:/tmp/create-worker-$short.tar"

ssh "$HOST" "REV=$rev SHORT=$short ROOT=$ROOT WAIT_SECONDS=$WAIT_SECONDS UNITS='$UNITS' bash -s" <<'REMOTE'
set -euo pipefail
cd "$ROOT"
old=$(cat hyperframes-worker/REVISION 2>/dev/null || echo unknown)
echo "Deployed: $old  ->  new: $SHORT"
staging=$(mktemp -d)
trap 'rm -rf "$staging" "/tmp/create-worker-$SHORT.tar"' EXIT
tar xf "/tmp/create-worker-$SHORT.tar" -C "$staging"

# What changed decides whether packages or the sandbox image are rebuilt.
changed() { ! cmp -s "$staging/hyperframes-worker/$1" "hyperframes-worker/$1" 2>/dev/null; }
needs_npm=0; changed package-lock.json && needs_npm=1
needs_image=0
for f in Dockerfile compose.local.yml package-lock.json hyperframes-runtime.lock.json; do changed "$f" && needs_image=1; done

echo "Draining worker slots (the build in hand finishes; at most ${WAIT_SECONDS}s)..."
# The units send SIGTERM to the coordinator alone and wait without a timeout (KillMode=mixed, TimeoutStopSec=infinity),
# and a stopped unit is not restarted. If the bounded wait ends first, the stop continues inside systemd: the slots
# keep draining and claim nothing new.
if ! sudo timeout "$WAIT_SECONDS" systemctl stop $UNITS; then
  echo "A build is still running after ${WAIT_SECONDS}s; slots left draining (no new claims). Rerun when it finishes." >&2
  exit 2
fi
echo "All slots stopped."

# Replace code; keep runtime/art-packs, artifacts and node_modules.
cp -a "$staging/hyperframes-worker/." hyperframes-worker/
echo "$SHORT" > hyperframes-worker/REVISION
if [ "$needs_npm" = 1 ]; then echo "Packages changed: npm ci"; (cd hyperframes-worker && npm ci --omit=dev --no-audit --no-fund); fi
if [ "$needs_image" = 1 ]; then echo "Sandbox inputs changed: rebuilding the sandbox image"; (cd hyperframes-worker && docker compose -f compose.local.yml build smoke); fi

for u in $UNITS; do sudo systemctl start "$u"; done
sleep 5
for u in $UNITS; do echo "$u: $(systemctl is-active "$u")"; done
echo "Worker now at $(cat hyperframes-worker/REVISION)."
REMOTE
