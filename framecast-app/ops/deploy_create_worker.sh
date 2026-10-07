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
#  4. reinstalls packages when the lockfile changed, and rebuilds the sandbox image when anything it copies changed;
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
# The sandbox image copies agent/, runtime/, scripts/ and fixtures/ (Dockerfile COPY), so it is rebuilt whenever any
# of them, or the Dockerfile or lockfiles, changed since the deployed revision (or when that revision is unknown).
deployed=$(ssh "$HOST" "cat $ROOT/hyperframes-worker/REVISION 2>/dev/null" || true)
image_inputs="Dockerfile package-lock.json hyperframes-runtime.lock.json agent runtime scripts fixtures compose.local.yml"
rebuild=1
if [ -n "$deployed" ] && git cat-file -e "$deployed^{commit}" 2>/dev/null; then
  changed=$(cd framecast-app/hyperframes-worker && git diff --name-only "$deployed" HEAD -- $image_inputs | grep -v '^runtime/art-packs/' || true)
  [ -z "$changed" ] && rebuild=0
fi
echo "Sandbox image rebuild: $([ $rebuild = 1 ] && echo yes || echo no)" 
tar_file=$(mktemp -t create-worker-XXXXXX).tar
trap 'rm -f "$tar_file"' EXIT
git archive --format=tar --prefix=hyperframes-worker/ "HEAD:framecast-app/hyperframes-worker" > "$tar_file"
scp -q "$tar_file" "$HOST:/tmp/create-worker-$short.tar"

ssh "$HOST" "REV=$rev SHORT=$short ROOT=$ROOT WAIT_SECONDS=$WAIT_SECONDS UNITS='$UNITS' REBUILD=$rebuild bash -s" <<'REMOTE'
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
needs_image=$REBUILD

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
if [ "$needs_image" = 1 ]; then echo "Sandbox inputs changed: rebuilding the sandbox image"; (cd hyperframes-worker && docker compose -f compose.local.yml build -q smoke) || { echo "Sandbox image build failed; slots stay stopped." >&2; exit 3; }; fi

for u in $UNITS; do sudo systemctl start "$u"; done
sleep 5
for u in $UNITS; do echo "$u: $(systemctl is-active "$u")"; done
echo "Worker now at $(cat hyperframes-worker/REVISION)."
REMOTE
