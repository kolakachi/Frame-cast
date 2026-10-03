# Prepared mascot rig — optional Hyperframes adapter

Read this before using `wyv-mascot.js`. This is a small WyvStudio-owned layer adapter, not a replacement for Hyperframes or an automatic character generator. Use upstream authoring/animation skills for the composition.

## Eligibility and limits

Use only when the approved character already has layered SVG artwork, or the user has approved creating an original vector character. A photo, generated PNG or five still poses is NOT a rig. Never replace an approved character with the test mascot, invent a different face or claim that applying a texture makes it faithful. Report the missing prepared artwork or propose a priced performance clip through the app. No provider calls from composition code.

This adapter animates blinks, pupils, a small head tilt/translation and four mouth shapes. It does not turn the head in 3D, articulate hands, walk, automatically align phonemes, or prove speech sync. Mouth changes are expressions unless an independently verified speech-alignment workflow exists. Continue using the app's native talking-video tools (or VEED Fabric for explicitly selected cloned-voice lip-sync) for actual speech. No random repeated mouth opening described as lip-sync.

The default remains neutral and motionless. Author cues only where the brief/reference needs them. Storyboard runs show a held pose; do not add the animated score. No universal palette, character framing or recurring blink interval is required.

## Layer contract

An inline `<svg>` contains exactly one group each with `data-rig-part="head"`, `left-eye`, `right-eye`, `left-pupil`, `right-pupil`. Eyes and all mouth groups must be descendants of the head. Each pupil belongs inside its corresponding eye so it closes with that eyelid. Supply four groups tagged `data-rig-mouth="rest|smile|open|round"`. Those groups must contain the actual different mouth artwork; empty groups do not supply performance. Author layers in shared SVG coordinates, avoiding competing transforms on controlled groups. Use an outer wrapper for composition movement. Keep the underlying approved artwork unchanged on unrelated edits.

## Timeline API

Load `gsap.min.js`, then `wyv-mascot.js`. Register the existing Hyperframes composition timeline inline as normal:

```js
const tl = gsap.timeline({paused:true});
WyvMascot.attach(tl, document.querySelector('#prepared-mascot'), {
  duration: 6,
  blinks: [{at:1,duration:.2}, {at:4.4,duration:.24}],
  head: [{at:1.6,duration:.4,x:0,y:-3,tilt:-5},
         {at:3,duration:.5,x:0,y:0,tilt:0}],
  gaze: [{at:1.6,duration:.2,x:4,y:0}, {at:3,duration:.2,x:0,y:0}],
  mouths: [{at:1.6,shape:'round'}, {at:2.4,shape:'smile'}, {at:4,shape:'rest'}]
}, 0);
window.__timelines = window.__timelines || {};
window.__timelines.main = tl;
```

Seconds are local to the score; the fourth argument offsets the entire score in the parent. Cues are ordered and nonoverlapping within each channel; mouth changes have distinct times and hold until the next cue. Duration 0.1–300 seconds; max 1,000 cues/channel; translations ±30 SVG units, head tilt ±20 degrees. Exceeding limits throws before mutation. No implicit timers, loops, speech, sound, asset fetching or spending. GSAP property tweens/sets support backward seeking even with suppressed events; avoid replacing them with onUpdate callbacks.

## Verify before delivery

Use a consecutive-frame sequence around at least one blink, mouth change and head motion. Inspect the character region independently of the moving scene: translating a whole image does not prove facial performance. Check identity/style against the approved design, intended gaze direction, mouth placement, layers at cue boundaries and backward seek. Confirm the final encoded artifact, not only the HTML preview. Label unsupported actions and missing audio explicitly; do not substitute static pose swaps for requested movement.

## Offline verification

From `hyperframes-worker/`, after building the local proof image with the new files:

```sh
mkdir -p artifacts/mascot-proof
docker compose -f compose.local.yml run --rm -v "$PWD/artifacts/mascot-proof:/output" smoke node scripts/mascot-fixture.mjs
```

Uses the existing network-disabled proof container. Produces `mascot-proof.mp4`, screenshots, render/decode logs and `verification.json`. It tests exact layer-state restoration at cue boundaries, bounded screenshot differences (browser antialiasing), no autonomous movement, and eye-region motion in the encoded video. The original robot fixture is technical evidence, not an approved customer design or creative acceptance. The fixture is intentionally silent and does not establish speech synchronization.
