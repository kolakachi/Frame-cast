# Remotion adapter: local implementation

## Scope

Native Remotion clips and still images are available to the existing Create agent
through the validated `run` tool. Hyperframes remains the top-level composition,
preview/review and export pipeline. The agent keeps the native React `.js` source
in the same revision bundle and integrates generated media as protected assets.
This is an integration with Remotion's bundler/renderer, not a reimplementation of
its animation API or an automatic conversion between React and HTML.

Initial workflow: an iart-inspired product demonstration/kinetic headline with
provided product imagery, authored palette, frame-based animation and source edits.
One prompt/chat/history remains; no separate Remotion product UI is introduced.

## Dependencies and license

Pinned in worker package/lockfile: Remotion, @remotion/bundler and
@remotion/renderer 4.0.532; React/react-dom 19.2.0. Existing installed package
versions remain unchanged. No CLI package, template installer, Studio, cloud
renderer, Tailwind or Three.js is included. Local Chromium and FFmpeg are reused.

Verified against official sources on 2026-10-02:

- [bundle API](https://www.remotion.dev/docs/bundle)
- [renderMedia API](https://www.remotion.dev/docs/renderer/render-media)
- [renderStill API](https://www.remotion.dev/docs/renderer/render-still)
- [Remotion license](https://github.com/remotion-dev/remotion/blob/main/LICENSE.md)

Remotion is not simply an MIT dependency. Its published license includes a
noncommercial evaluation allowance; company eligibility determines commercial
licensing requirements. This work is local evaluation. Before customer rollout,
record the applicable license and any usage reporting/billing obligations with
Remotion. No license purchase, entitlement claim, commercial rollout or provider
spending is authorized by this implementation.

## Authoring and execution contract

- Agent writes a flat `.js` file exporting a default React component (JSX allowed).
- Imports: `react`, JSX runtime, `remotion`, flat sibling `.js` files. Use inline React styles; stylesheet imports are unsupported.
- `staticFile()` references staged existing assets. Runtime does not impose colours.
- Host registers the component, fixes FPS at 24 and uses approved aspect/dimensions.
- `run`: `remotion render project/remotion-demo.js 6` or
  `remotion still project/remotion-demo.js 6 72` (argv arrays, never shell strings).
- Clip length 0.5–30 seconds, no longer than approved output duration; frame bounded.
- Agent cannot choose an output path, webpack config, install command or Node loader.
- Source compiles for the browser; it is never imported/executed as host Node code.
- Render lives in the existing no-network, read-only, unprivileged container.
  Only flat source/media are staged; links/directories rejected. Native imports
  from authored files cannot select filesystem modules, arbitrary packages or loaders.
- New outputs use UUID filenames and publish only after rendering and (for MP4)
  probe/decode verification. On failure, partial new outputs are removed. Remotion
  subprocess group is killed on timeout; existing run/deadline limits apply.
- Tool stdout journals engine version, source hashes and selected geometry. The
  normal run result records generated media hashes and accounting remains in the
  existing agent loop. Rendering makes no model/API call; compute is still a cost.

## Preview, edits and recovery

Render a still or clip, place its returned filename in a normal timed Hyperframes
media element with a unique ID, and use existing check/preview/critic/export tools.
When audio is in the clip, explicitly preserve it and avoid duplicate voice tracks.

Edits patch React source, create a new clip, and update the HTML reference. Old
media stay immutable. React source travels in normal `.js` revision bundles.
Last-good recovery snapshots all authored source files, including React components,
instead of only index.html/style.css/main.js.

## Boundaries still requiring work

- This is a clip adapter, not a full native-Remotion project type or in-browser
  Remotion Player/Studio. No automatic HTML/React conversion.
- Internal React text is opaque to existing HTML factual, speech-cue, contrast and
  layout checks. Rendered visual review remains necessary; stronger native checks
  and source-to-render freshness enforcement across revisions remain open.
- Colours remain authored plan inputs, not pixel-enforced constraints.
- Advanced iart examples may depend on packages outside the installed set.
- No transparent output, custom FPS, dynamic composition metadata, remote imports,
  external fonts, user webpack config or arbitrary runtime installation.
- Current adapter bundles each command; persistent content-addressed bundle caching
  and engine performance comparison remain future work. Do not claim it is the
  cheapest renderer before measuring representative workloads.
- Live model choice, creative acceptance, native audio/video coverage, cancellation
  stress, commercial licensing and broader UI acceptance remain release work.

## Verification

`scripts/remotion-fixture.mjs` drives the actual composition agent with an explicitly
scripted offline provider: read adapter + iart guidance, author React, render still
and MP4, embed and export through Hyperframes, edit React, render again, verify
source/old-clip hashes and retained editable source. An unsupported Node import
must fail and produce no media output. No paid provider call or ASR is involved.

Run in the rebuilt local image with no network and a dedicated `/output` mount.
The fixture replaces only `/output/live/remotion-proof`. Outputs include original
and edited videos/stills plus `remotion-report.json`; it is not a creative verdict.
