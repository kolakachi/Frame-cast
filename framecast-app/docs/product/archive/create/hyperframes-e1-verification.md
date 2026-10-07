# E1 — local agent implementation evidence

Date: 2026-09-28. **In progress; creative acceptance has not passed.**
E0 baseline: `62a8eb7`. E1 changes are local; nothing pushed.

## Authorization and isolation

The user approved use of the configured app Replicate credential for a total of
$5 and explicitly approved transfer of the supplied product image and generated
contact sheets. This supersedes the initial plan for a separate test credential.
Only the host live-test driver reads the credential; it is neither logged nor
passed into the offline Docker renderer. No production services were changed.

## Verified provider contract

Reduced schemas are in `hyperframes-worker/agent/contracts/`. Public schema pages
had stale version identifiers. Authenticated model metadata supplied the updated
advertised versions recorded there. Actual predictions return version `hidden`;
the adapter checks advertised model metadata in that case. This detects advertised
schema drift, but does not pin or certify an undisclosed underlying deployment.

Both endpoints accept prompt, system_prompt, one image and max_tokens; outputs
are string arrays. No native tool-call/messages interface was exposed. The host
validates one JSON action, tolerating commentary around it but never executing
commentary or arbitrary shell commands. Polling and prediction-ID recording were
exercised live; cancellation is unit-tested, not a live cancellation benchmark.
The pilot uses a 4,096-token response ceiling and ten calls per run.

Sources: [Sonnet](https://replicate.com/anthropic/claude-4.5-sonnet),
[Opus](https://replicate.com/anthropic/claude-opus-4.6),
[input files](https://replicate.com/docs/topics/predictions/input-files).

## Implemented and tested

- Scoped source reads/writes/exact patches, asset hashes, traversal/symlink denial.
- Pinned upstream core guidance and allowlisted references, with manifest hashes
  and Apache license, alongside existing npm router/CLI guidance.
- Bounded model calls, context, output tokens, elapsed time and repair attempts.
- Shared local $5 test ledger reserves before every paid request. Known token
  metrics use published rates plus 20% and $0.005 headroom; unknown requests retain
  their original bound. These are cost estimates/reservations, not invoice charges.
  The ledger supports a single local coordinator, not concurrent production use.
- Atomic journals preserve responses and prediction IDs. Uncertain calls pause
  for reconciliation rather than automatically buying another prediction.
- Current-revision validation and contact-sheet visual review gate preview readiness.
  A sampled model review does not certify every frame or human creative acceptance.
- Media actions propose work and pause; these tests buy no image/video generation.
- 30 deterministic Node tests pass, including actionable snapshot-limit diagnostics.

## Real outputs and findings

Sonnet created a 15-second product teaser and made an opening-only follow-up edit.
Both rendered and decoded successfully. Original render: 34.139s, peak memory
736,075,776 bytes. Edited render: 31.411s, peak memory 770,355,200 bytes.
Local outputs under `hyperframes-worker/artifacts/live/product-sonnet-r2/render/`:

- Original: `cfa68808-4fe9-4c07-b37c-378d9b5787c4/video.mp4`.
- Edited: `cb29fd2a-a8e7-4aa6-9310-9a9e74812dac/video.mp4`.

Source diff changes only opening markup/styles/timeline; later scene code and
product asset are retained. Encoded frame comparison at 7s returned SSIM 0.973792,
below the attempted 0.99 threshold. Cause remains unverified: do not claim pixel
identity or close the full visual preservation gate from the source diff alone.
The edited run required manual finalization after the shared budget stopped a
redundant finish call; check, snapshot and visual pass were already journaled.
The runner now completes on that visual pass, removing the redundant call.

**Human review:** “Works, but creative quality needs improvement.” This is evidence
of functional progress, not E1 creative acceptance. No candidate winner is selected.

Live tests exposed and corrected stale schema IDs, hidden-version handling,
oversized check reports, missing pinned reference access, and model commentary
around action JSON. Opus also requested six snapshots against the five-image
limit twice; the ambiguous error consumed its repair allowance. The policy and
error now state the exact limit. Earlier failed runs remain recorded as failures.
These recovered sessions are not clean first-attempt success-rate evidence.

The subsequent Opus verification run passed sampled visual review and rendered
`product-opus-r1/render/db835076-f84e-4276-b044-5cb4f5d79af7/video.mp4`
in 37.254s, peak memory 720,363,520 bytes. It is a technical candidate sample,
not a human-approved creative winner.

At the end of these tests the shared ledger contains 51 requests: known token-rate
estimates total **$1.724993**, with **$2.555160 reserved** including headroom and
unresolved requests, against the approved $5 cap. Invoice reconciliation remains
open. These totals include harness debugging and both candidates, not just the
successful video.

## Still open before E1 closure

- Improve creative direction using reviewed visual examples and obtain acceptance.
- Complete same-footage three-style and five-brief model comparison, including
  presenter/audio, mismatched-product and difficult-input cases.
- Verify output preservation after targeted edits, including timing/audio where used.
- Add remaining timeline/primitive tools and richer grounded context; current live
  test covers a still product image with approved copy, not the full source workflow.
- Document per-acceptable-output cost including failed/repaired attempts; token
  estimates must not be presented as reconciled provider invoice totals.
- Production concurrency, leases, app accounting and tenant isolation remain E2.

Run `node --test agent/tests/agent.test.mjs` for deterministic coverage. Paid smoke
is an explicitly authorized local harness, not a normal test command: it reads the
configured token, sends approved images and uses the shared ledger. Artifacts and
journals stay ignored by Git. Do not reset the ledger to evade the spending cap.


## Round-up attempt — subsequent local work

**E1 remains open.** The second product sample was also rejected by the user:
“Still too basic—improve the creative direction.” A stronger product-first creative
contract is implemented but has not been tested in a paid run or human-approved.
Do not substitute the model's positive self-review for that acceptance.

Implemented: compact old source payloads (full journal retained), combined
`preview`, timeline inspection, installed primitive lookup, per-call metrics,
structured brand/facts/transcript slot, exact runtime filenames and locked source
regions. Structured host guards pause product conflicts, unsupported claims,
short footage and new speech before spending. These are not automatic semantic
detection of every conflicting user prompt. Local budget reservations now lock
the ledger and settlement reloads it; unknown requests retain reservations.
Production transactional accounting remains E2. **41 Node tests pass.**

| New case | Requests | Estimated model cost | Result |
|---|---:|---:|---|
| Sonnet product | 4 | $0.120204 | Rendered; creative quality rejected |
| Sonnet opening edit | 7 | $0.151794 | Rendered; sampled model review passed |
| Opus product | 10 | $0.500170 | Call limit; unfinished repairs |
| Opus opening edit | 10 | $0.472825 | Call limit; no approved draft |
| Sonnet presenter overlay | 6 | $0.238242 | Blocked removal of locked source timing |
| Sonnet typography | 7 | $0.216048 | Rendered 1080×1920, 15s; human review pending |
| Sonnet editorial reference | 1 | $0.043743 | Draft written; next call blocked by budget |

The editorial draft was inspected offline: it requested nonexistent
`local font.ttf`. Validation failed, so it was not rendered or represented as a
success. Exact runtime filenames and compact error hints now address that issue.

Fresh offline snapshots from the earlier Sonnet original/opening-edit sources at
**7s and 13s are byte-for-byte identical**. This verifies unchanged sampled scenes,
not all-frame identity; the earlier encoded SSIM difference remains unexplained.

The shared ledger stopped further calls at the original $5 reservation cap:
96 requests, **$3.468019 known token-price estimates**, **$4.871791 reserved**
including headroom and unresolved requests. Two earlier requests remain
unreconciled. These are not invoice-confirmed totals. No cap increase applied;
the later request to raise the total cap to $8 was declined. The user requested offline work only; the $5 cap remains unchanged.

Still needed: accepted creative output, agent-authored presenter/audio fidelity,
three styles from the same footage, remaining Opus briefs, long-copy and
contradictory-brief live cases, and completed scoring. Sonnet is the provisional
next-test choice, not a certified production winner. The local benchmark report
and media-verification JSON preserve results. Do not tick the whole phase.


## Offline hardening — 2026-09-28 follow-up

User decision: **keep the $5 cap and finish offline work only**. No new provider
calls or image transfers were made. The ledger remains at 96 requests,
$4.871791 reserved and $3.468019 in known token-price estimates. These are not
invoice totals, and unresolved reservations were not released.

Changes verified:

- Locked-source removal or duplication is rejected before writing. The agent
  receives an actionable correction within the existing two-repair allowance.
  This fixes the immediate abort observed in the presenter benchmark without
  relaxing source locks. Repeated violations stop; sandbox/filesystem failures
  still stop immediately. Literal source locks are not semantic video analysis.
- An exact patch that no longer matches can read the current draft and correct
  itself within the same allowance. No automatic replay of uncertain work.
- Full bounded source reads reach the model. The 16 KB diagnostic summarizer no
  longer silently truncates HTML needed for targeted edits; the overall context
  and source-size limits still apply.
- The live harness now supplies scenario-specific approved copy rather than
  product copy for every brief, explicit separation for the mismatched-product
  fixture, and a persisted source hash as the base revision. These harness changes
  passed syntax checks, but have **not** been re-tested with a paid model. Use a
  fresh test ID; old journals must not be reset or rewritten to force a replay.

Verification:

- **45 deterministic Node tests pass**, including recovery after a rejected edit,
  duplicate source rejection, bounded repair exhaustion, complete source reads,
  patch recovery and terminal sandbox violations.
- Rebuilt offline renderer verifies **223 pinned skill files**. The scripted
  smoke also loads hash-verified core and determinism guidance.
- Real offline Hyperframes smoke: six scripted actions, one rejected destructive
  edit, one repair, exact source comparison proving only CTA text changed, then
  check, snapshots, MP4 render and encoded-media validation. Render took 23.391s;
  peak memory 704,839,680 bytes. Provider cost **$0**.
- Local artifact:
  `hyperframes-worker/artifacts/agent-smoke/f9fd63ba-a69a-47a7-bbe0-c74cd13d9ae5/renders/c1f76b03-bccc-4879-8976-58825c05c955/video.mp4`.

Reproduce without paid calls:

```sh
cd framecast-app/hyperframes-worker
node --test agent/tests/agent.test.mjs
docker compose -f compose.local.yml build smoke
docker compose -f compose.local.yml run --rm smoke node agent/fake-smoke.mjs
```

**E1 as a whole remains open.** A scripted offline render establishes engineering
behavior, not model creativity. Still required for the original exit criterion:
accepted real creative output, agent-authored presenter/audio preservation,
remaining style/brief comparisons and difficult-input runs, measured cost per
acceptable output, and invoice reconciliation. Model selection and reviewed
positive visual exemplars remain unproven. No paid admission or production
readiness is implied by this offline pass.
