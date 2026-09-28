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
