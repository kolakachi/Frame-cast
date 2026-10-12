# UGC type production contracts

`UgcTypes` is the menu and director contract. `/ugc/settings` publishes descriptions and availability; the chat UI loads them from that response.

## Rendering routes

- Testimonial, listicle, founder story: spoken presenter. Founder story requires a workspace-owned character.
- Problem/solution, unboxing, day in the life: native generated action and dialogue, including wordless shots. Uploaded scenes remain uploaded scenes; narration over an uploaded scene is generated and composited onto that source.
- Before/after and comparison: sequential scenes by default. Assign uploaded files to **Left / Before** and **Right / After** to render a real split. The source pixels are never regenerated.
- Green screen: assign an uploaded image or video to **Background**. Generate a keyed presenter and composite it over the original background. A basic chroma-background check rejects clips that ignored the required backdrop.
- ASMR: native video/audio, hands/product framing, silence or explicitly scripted whispers. No music prompt. Missing audio streams fail before delivery. Uploaded source sound is preserved through export.
- Skit: exactly two accessible cast presenters in selection order. Every beat has `speaker: 1` or `speaker: 2`; a single video alternates shots with the appropriate face reference. Overlapping speech and simultaneous two-person acting are not supported.
- POV hook: silent text-led cards, with the headline applied by the compositor rather than painted by a video model.
- Reaction: existing silent 5- or 10-second animated reaction and headline path.

## Completion and billing

Each spoken clip is transcribed and cut back to exactly its line: strictly first (95% of the words, the closing word matched), then tolerantly (80%). Accents do not decide a match. A line that still cannot be matched (a brand heard differently, "$29" as "twenty-nine dollars"), a transcription outage or a placeholder keeps the clip uncut and logs it; only a clip with no speech at all for a scripted line stops delivery, uncharged. (Failing on a mismatch meant paying for clips a retry would fail the same way.)

Each generated clip reserves speech time and a finishing pause. Silent beats keep their duration and action/sound instructions. Speaker changes and uploaded scenes are never packed into the same provider clip.

A take is billed for the seconds generated, as the compiler cut them, at the engine's per-second rate (`UgcOneShotPricing::quoteSeconds`, used by both the pitch and the generate check): each clip's finishing second is billed; clips that only replay the user's own footage are free; Veo's 4/6/8-second snapping beyond that stays ours. A short line in a long planned shot is therefore billed for the shorter clip actually made. Skits always run on Omni.

Green screen: three frames per clip must show the key colour (two of three). A clip that drew scenery instead is remade once at our cost; a second miss stops the take, uncharged, offering a testimonial instead.

Skit clips carry their speaker's photos as asset ids; the job reads them, only from the workspace or stock.

Skits: only spoken shots need speaker 1 or 2; product inserts and silent beats need none.

Composition assets are workspace scoped and checked before generation. Composition and per-speaker references are saved in the private resumable recipe. A removed required background/comparison attachment invalidates the pitch before generation. Silent reused footage is excluded from video-generation pricing.

## Verification

Run from `framecast-app/api`:

```sh
docker compose exec -T api php -d memory_limit=2G vendor/bin/phpunit --filter 'Ugc|OneShotScriptSpan|ExportParity|ExportFreshness|ProductVision'
```

`UgcCompositionTest` invokes real FFmpeg on synthetic media and verifies panel pixels, presenter pixels, duration, and audible native sound through export. Controller tests verify native routes and workspace boundaries; worker tests cover speech refusal, speaker references, source reuse, and retry recipes.

Run `web/tests/ugc-types.mjs` against Vite with `PLAYWRIGHT_MODULE`, `CHROME_PATH`, and optionally `UGC_TEST_URL` set for your environment. All API calls are mocked and external requests are blocked. It checks the shared menu and background/skit controls.

## Rollout

**Gate.** Each type's new production route (native generation, composition, skits, POV hook, reaction) is staff-only until opened for customers in `UGC_TYPES_LIVE` (comma-separated type keys, prod `api/.env`). Until then customers get the previous behaviour: skit, POV hook and reaction are hidden; the other types are made as the closest talking-presenter ad, and the director is told so (`UgcTypes::contracts`). Open one type at a time, after its reviewed samples pass.

These code and synthetic-media checks do not replace provider-output QA. Before production rollout, generate reviewed samples for every type, including slow speech, green clothing/products, hair edges on keyed backgrounds, two different skit voices, and ASMR sound quality. The key switches to blue or magenta when product descriptions identify green details. Chroma key still needs visual review for colours absent from the product description and fine hair edges. No production deployment or paid provider sweep is part of the automated tests.

Deploy API and frontend together and restart UGC workers through the normal deployment procedure. There are no schema migrations. Existing completed videos are unchanged; repairing one requires re-editing available source clips or regenerating its incomplete parts.
