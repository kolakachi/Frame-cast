#!/usr/bin/env bash
# Run the Create worker's tests inside the worker image, which has every dependency (@babel/parser, Remotion,
# Chromium, ffmpeg). The host has no worker node_modules, and installing them there would mix macOS and Linux builds.
#
#   framecast-app/ops/test_worker.sh                 # all tests
#   framecast-app/ops/test_worker.sh reads final     # only tests whose file name contains one of the words
#
# The current agent/ code and runtime scripts are mounted over the image's copies, so the image only needs rebuilding
# when package*.json changes (WORKER_IMAGE=... to use another tag; REBUILD=1 to rebuild first).
set -euo pipefail
here="$(cd "$(dirname "$0")/../hyperframes-worker" && pwd)"
image="${WORKER_IMAGE:-wyv-hyperframes-proof-smoke:latest}"
if [[ "${REBUILD:-0}" == 1 ]] || ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build -q -t "$image" "$here" >/dev/null
fi
mounts=(-v "$here/agent:/opt/worker/agent:ro")
for f in "$here"/runtime/*.js; do mounts+=(-v "$f:/opt/worker/runtime/$(basename "$f"):ro"); done
files='agent/tests/*.test.mjs'
if [[ $# -gt 0 ]]; then
  files=''
  for w in "$@"; do for t in "$here"/agent/tests/*"$w"*.test.mjs; do [[ -e "$t" ]] && files+=" agent/tests/$(basename "$t")"; done; done
  [[ -n "$files" ]] || { echo "No test file matches: $*" >&2; exit 2; }
fi
exec docker run --rm "${mounts[@]}" -w /opt/worker --entrypoint sh "$image" -c "node --test $files"
