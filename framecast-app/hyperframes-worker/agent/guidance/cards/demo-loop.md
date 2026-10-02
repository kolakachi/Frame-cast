Distilled from iart-ai product-demo-video and its references (MIT) and HyperFrames product-launch-video (Apache-2.0).

# The demo loop (UI walkthroughs from screenshots)

## One feature, one loop
Every feature beat is the same five moves; repeat it per feature, never two features in one loop.
| Move | Budget | Rule |
|---|---|---|
| Settle on the screen | 0.5–1 s | nothing moves until the viewer can orient |
| Cursor travels | 0.4–0.8 s | an eased arc (WM.cursor), never linear, never a real cursor |
| Click | 0.1–0.3 s | ripple and the UI's state change on the same frame (WM.press + the toggle/row/modal tween at the click time) |
| Zoom to the region | 1–2 s in, hold 2–4 s | 2× at most, converging on the element, with one caption |
| Clear | before the next step | caption out, zoom back or cut to the next screen |
- Move the camera OR the cursor, never both at once.
- Per-feature budget: 15 s video → 3–4 steps at ~3.5 s; 30 s → 5–7 at ~4 s; longer → 4–5 s each on dense screens.
- Hold the outgoing screen until its click resolves, then transition; never cut mid-click.

## Frame every screen
- A raw screenshot reads as a bug report. Mount it in browser-device-stage (real URL in the bar) or device-frame-stage (phone/tablet); one chrome theme for the whole video, on a branded field with a soft shadow.
- Rebuild the UI from the page capture at 2× detail (type, buttons, rows as real elements) so a 2× zoom stays crisp; a bitmap screenshot blurs when zoomed. Keep the brand's real copy; no lorem, no invented numbers.
- Swap one screen per step; the same card persisting across two screens is a match-cut (keep its position, fade the rest).

## Cursor, zoom, callouts with our kit
- Cursor: `WM.cursor(tl,'#cursor',[{x,y,at},{x,y,at,click:true}])` with `WM.press(tl,'#btn',at,{glow})` at the same `at`; or mount cursor-glyph-trail (a dotted click path over a screen) or gesture-tap (a finger tap on a mobile pill). One cursor per video.
- Zoom: wrap the screen in `#camera`; `tl.to('#camera',{scale:2,x,y,duration:1.2,ease:WM.ease.default},at)` with x/y chosen so the target element lands centred; ease back out before the next screen. Over 3× loses context.
- Spotlight: dim the surround with a ring on the target (`box-shadow:0 0 0 9999px rgba(0,0,0,.45)`) while zoomed; or before-after-wipe for a state comparison.
- Caption: one benefit noun phrase per step ("One-click export"), not a sentence; enters on the zoom, leaves before the next step; lower third, inside the safe area, one line; counters via count-up / WM.count only with approved numbers.

## Transitions between screens (pick one language, keep it)
- Push/slide (WM.push): linear walkthrough. Cross-zoom: diving into a detail. Crossfade: context switch (settings → dashboard). Match-cut: a shared card persists.

## Checklist
- Screens framed, real URL, one theme; cursor eased, click and state on the same frame; zoom ≤ 2×, held 2–4 s; one motion at a time; one caption per step in the safe lower third; transitions in one language; every screen's content real.
