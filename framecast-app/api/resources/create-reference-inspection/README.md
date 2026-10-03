# Shared reference inspector

The API image already contains Node and FFmpeg. `cli.mjs` accepts only a host-staged reference manifest and a validated extraction request via stdin. It makes no provider requests.

The two inspector modules are exact copies of the worker's host-side implementation because the API Docker build context excludes its sibling worker directory. Change the worker modules, then run `node hyperframes-worker/scripts/sync-reference-inspection.mjs` from the application root. `--check` and the inspector tests reject drift. No third-party dependency or skill is duplicated here.

Planning stages private, workspace-scoped snapshots in a temporary request directory, preserves source hashes/coverage in the plan, and removes staged bytes in `finally`. Consecutive-frame cache reuse is within that planning request only. The 4-request / 3-model-call limits are hard ceilings, not configurable model instructions. They do not certify every-frame understanding of a whole video or audio understanding.
