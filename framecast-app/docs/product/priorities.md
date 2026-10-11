# WyvStudio: everything pending, in priority order

One list across the app (Create, UGC, the marketing site, positioning and costs). Made 2026-10-11 from
`create-todo.md`, `ugc-chat-todo.md`, `positioning-roadmap.md` and this week's decisions. The detailed todos stay
where they are; this file is the order to work in.

**How it is ordered:**

1. A first visit that ends with a good ad (refunds were 8 of 18 AppSumo sales).
2. Knowing what we earn and spend.
3. Getting the ads and the agencies page live.
4. Quality and variety.
5. Everything else.

**Who:**

| Mark | Meaning |
|---|---|
| owner | a decision or something only you can provide |
| build | engineering work |
| marketer | waiting on the Google Ads tester |

## Now

| # | Item | Why it matters | Who / next |
|---|---|---|---|
| 1 | **Done (2026-10-11, prod take 281): the director sees product photos (VISION).** Each photo is read once (what it is, packaging, colours, size, label text). A pasted product link pulls its images. | The director only reads file names: in 3 of 6 test videos a tube became a pump bottle. This makes "paste your product link" true. | build (about a cent a photo) |
| 2 | **Outfits that hold.** An outfit step spells the outfit out in detail and names it in every clip prompt (from Jellyfish's outfit agent). | Planned outfits lose to the presenter photo's outfit (prod take 280). | build, then a few paid test takes |
| 3 | **Done (2026-10-11): Anthropic auto-reload on** (API-BAL). | If the Anthropic credit runs out, Create and the UGC chat stop. | owner (a few minutes) |
| 4 | **Done (2026-10-11): cost tracking step 2, money in.** Record the dollar amount of every purchase (Kelviq, Test Pass, AppSumo after its cut). | Step 1 (real cost of every paid call) is live. Without money in there is no margin. | build |
| 5 | **Done (2026-10-11): cost tracking step 3, "Customer economics" in God Mode.** Per workspace: money in, credits, real cost, margin. | Shows which buyers and channels make or lose money. | build |
| 6 | **Built (2026-10-12): where buyers came from.** Each account and purchase is tagged Google, AppSumo, affiliate or direct, and the Google click id is carried through sign-up. Turns on URL passthrough. | Proves the Google campaign works. Feeds item 5. | build |
| 7 | **Done (2026-10-11): Purchase conversion label.** | Purchases are not counted in Google Ads until then. | marketer (guide: `google-ads-conversions.md`) |
| 8 | **Ship the /agencies page.** Mockup at `marketing/agencies.html`; link it from the footer and the Agency pricing card. | Gives agencies a page that speaks to them. | owner: feedback or "ship" |
| 9 | **Built (2026-10-12): queue timeouts (QUEUE-REAP).** Composed scenes waiting behind long one-takes are timed out at 10 minutes. | A real customer's video can fail for no reason. | build |
| 9a | **Create builder rules (prompt only).** Into the builder's prompt: the HyperFrames trap list (a blend-mode overlay renders the film white, audio without an id is silent, a sub-timeline shorter than its slot flashes black) and the craft rules from the motion briefs in `skills/` (every frame a pure function of time, one camera container that never snaps back, shared-element handoffs, outgoing text gone before incoming, fonts loaded before measuring, no fade under 0.3 s, no opacity on 3D elements). | Prevents real failed or broken builds, at no cost. | build, then one test build (cost asked first) |
| 10 | **A take delivered after the balance ran out (DEDUCT-FALSE).** Today it is not charged. | Revenue leak, small. | owner: decide |

## Next

| # | Item | Why it matters | Who / next |
|---|---|---|---|
| 11 | **First session ends with a finished ad.** Review the sign-up to first video path and remove what stalls it. | The biggest lever on refunds. | build, after 1 |
| 12 | **Create: a motion vocabulary and a "no slideshow" check.** The planner picks motion from a scene-purpose to effect table, tied to our 386 unused HyperFrames blocks (from awesome-ai-motion). The check rejects videos with almost no motion inside shots (from huashu's "anti-slideshow" gate). Table sources (2026-10-12): product-launch-motion's 14-shot beat catalogue, cinetic's 273 measured techniques (review it first), the interface-morph recipes from motion-graphics-skills, animate's cut link types; plan-time storyboard lint (no text-only last shot, no empty gradient shots, no numbers floating in space). | More varied, more alive Create videos. | build (free) |
| 13 | **Create: requirements about what is said (REQ-SPOKEN).** They are checked by sight today, so a correct voice-only version is blocked. | Wrongly failed builds. | build |
| 14 | **Open Create to the two real customers (O9)** (ws 28, ws 27). | Real users on the flagship. | owner: decide |
| 15 | **Every size from one video (P2-ASPECT)**, plus a warning when a 16:9 export of a vertical video gets side bars (EXP-WARN). | Ads need 9:16, 1:1, 4:5 and 16:9. | owner: decide, then build |
| 16 | **Turn on face-check remakes** once about 10 cast takes have scores. | Pushes resemblance toward 99%. | build (switch) |
| 17 | **Skits: two or more people (CAST-ALL),** with a cast and place sheet: every person, place and prop gets a reference image and shots refer to them by `@name` (from Toonflow and huobao). Each shot gets a "ready" check before any paid generation, plus a check that two characters were not mixed up (from Jellyfish). | Unlocks the skit type. | build, then a paid test (600–900 credits) |
| 18 | **Proof.** One free ad each for 15 to 20 brands already running video ads, then 2 to 3 short case studies with real numbers. | 2 real customers today; proof sells. | owner picks brands; we make the ads (about 330 credits each) |
| 19 | **Google Ads landing pages.** One per keyword group. | Better ad relevance and cheaper clicks. | marketer: keyword groups |
| 20 | **UGC defaults to confirm** (already built the recommended way): an explicit type beats the sample's; a pasted script's length wins; Rewrite with AI is free; saved characters come from Library → Characters. | Close open decisions. | owner OK |
| 21 | **Old-form UGC changes to confirm:** captions on by default, cuts re-anchored on photos, a shorter resumable timeout. | Close an open decision. | owner OK |
| 22 | **Create pricing, limits and quality bar (O11),** and confirm the market-test round counts as customer acceptance (O10). | Needed before widening Create. | owner |

## Small fixes (fit in between)

| # | Item | Who |
|---|---|---|
| 23 | Leftover clip files after some project deletes (ORPHAN-PARTS) | build |
| 24 | The pitch names a picked presenter only sometimes (P2-POLISH) | build |
| 25 | Undo gaps: "Re-record all voices" not busy-guarded, a failing step can't be discarded, a voice re-record can't be undone, history not deleted with the workspace | build |
| 26 | An image in progress is lost when the build worker dies (0.4) | build |
| 27 | Local setup: rebuild the remaining container (I2) | build |
| 27a | When moderation blocks a generated shot, rewrite the prompt more neutrally and retry once automatically, instead of failing (from Pixelle-Video) | build |

## Later

| # | Item | Who |
|---|---|---|
| 28 | Create finishing checks: constant-speed motion, the same technique repeated, full-frame hits (FS3). Added 2026-10-12: text under the phone's UI or cut off, one-frame pops, every sound cue present in the file, effects placed by their measured peak, the drop on the key line, voice about 9 dB over music, −14 LUFS, captions from per-phrase word timings, a contact sheet before delivery | build |
| 29 | Create: pick the best seconds of a clip longer than its slot (D7) | build |
| 30 | Create builder: WyvBear and the GSAP plugins (AB3); the art library for icons (AB4); a seek-driven globe (cobe) and selected 3D helpers for product hero shots (drei) | build |
| 31 | Create: pause to ask inside a step, then resume without re-approval (S10) | build |
| 32 | Create: reconcile provider invoices with our cost records (HF-BILL); hard test briefs (HF-CASES): use the motion briefs in `skills/` (launch film with voiceover, chat-to-result, typographic speech, product explainer template), adapted to our own brands; never the Spotify brief (a real brand) | build |
| 33 | Create up to 60 seconds before falling back to Classic (WEAVE-60) | build |
| 34 | Create explainer styles (whiteboard, kinetic type, finance chart and others), and a reference-timing breakdown for "make it like this" (from huashu). Don't market them as named channel looks (Kurzgesagt, Vox). Also from 2026-10-12: student-kit explainer cards (renamed), one working object that explains the idea (anatomy), shader backgrounds ported to WebGL (gradient, aurora, glass, grain; time each render first) | build |
| 35 | Laban efforts: show them to users? Use them to direct takes? (LABAN) | owner |
| 36 | Create test bench: budget cap (7,000 credits?), a consented photo of you, a UGC reference video, a product photo, 15 to 25 example videos (O1 to O6, then the bench rounds) | owner, then build |
| 37 | UGC: ASMR as a silent one-take on Seedance | build |
| 38 | UGC: new types (green screen, before/after and comparison splits), per-type playbooks (P3) | build |
| 39 | UGC: change a delivered video by chat; chat tools in the API and connector (P4) | build |
| 40 | Presenter cutout as a post-production step (CUTOUT-PC) | build |
| 41 | Weekly ad batches, the habit a lean team needs | build |
| 42 | Monthly plan for teams that need ads every week? (decide once about 10 teams buy a second pack) | owner |
| 43 | 2 to 3 short ads about WyvStudio, made by the Creative Director itself (on hold) | owner |
| 44 | ManimGX for math-style explainer shots, rendered on the worker and dropped into HyperFrames as footage: watch until it matures and runs on our ARM worker | watch |
| 44a | Still-ad editor: put a logo or headline on a generated image and export 1.91:1 and 1:1 (fabric.js) | build |
| 44b | Creative Director loop cost levers (harness study): trim old tool output by rule; re-test whether planning and the fixed tools earn their cost on Opus | build |
| 45 | Pricing review rework, once everything above that does not depend on the marketer is done | build, last |

## Research repos we borrow from (2026-10-11)

Ideas only. We take no code unless the licence allows it and it fits.

| Repo | What we take | Where | Licence and cautions |
|---|---|---|---|
| Forget-C/Jellyfish | Outfit agent (#2); character mix-up check (#17) | UGC, Create | Apache-2.0: code allowed with attribution. Prompts are in Chinese. |
| gongnyang/awesome-ai-motion | Scene-purpose to effect tables (#12) | Create | MIT for its own work; some cards come from paid or unknown sources, so check before copying any card's code. |
| alchaincyf/huashu-art-motion | "No slideshow" gate (#12); explainer styles and reference-timing breakdown (#34) | Create | MIT, except its character art, some stroke data and fonts. |
| HBAI-Ltd/Toonflow-app | Cast, place and prop library; shot "ready" check (#17) | UGC skits, multi-shot Create | MIT from v1.1.9 only (older versions AGPL or restricted). Don't install it: no login, unsigned installer, tells you to switch off macOS protection. |
| chatfire-AI/huobao-drama | `@name` references in shot prompts (#17) | UGC skits | CC BY-NC-SA: no commercial use, so ideas only, no code. Don't install it or route keys through its relay. |
| ATH-MaaS/Pixelle-Video | Rewrite and retry on a moderation block (#27a) | UGC, Create | Apache-2.0. Stalled since June. |
| academa-labs/manimgx | Math-style explainer shots (#44) | Create, later | MIT, but its packaged builds are GPL through x264: fine to run on our server, don't redistribute. 6 days old. |
| waooAI/waoowaoo | Nothing new (we already handle reference images the same way) | — | Elastic License 2.0: no hosted use, so skip. |
| AbubakrChan/product-launch-motion (2026-10-12) | 14-shot beat catalogue + launch-film rules (#12); HyperFrames trap index into the builder prompt; word-locked cues, audio-present check (#28) | Create | MIT; evals are write-ups, not benchmarks. |
| nateherkai/hyperframes-student-kit | Black-flash fix (pad sub-timelines to their slot) into the builder prompt; explainer cards (#34); cut-silences/mistakes (My Footage, later) | Create, footage | MIT + commercial grant for the pipeline and styles; brand assets excluded; built on HyperFrames 0.7.109. Never market named channel looks. |
| cth9191/animate, howseen-ai/claude-motion-design, huashu storyboard_lint | Measured checks: text under phone UI or cut off, one-frame pops, cues present, loudness; plan-time storyboard lint (#28, #12) | Create | MIT. claude-motion-design scrapes faces/memes and has a 1:1 remake mode: take checks only. |
| charlie947/motion-graphics-skills | Interface-morph timing recipes (#12); 5-line reference breakdown (#34) | Create | MIT; take text only, no `npx skills add`. |
| shuding/cobe, pmndrs/drei | Seek-driven globe for "worldwide" scenes; selected 3D helpers for product hero shots (#30) | Create | MIT; self-animating helpers banned; WebGL is software (SwiftShader) on the worker, keep to 1–3 beats. |
| shader-effects-inc/shaders | Port a few effects' maths to WebGL (gradient/aurora backgrounds, glass reveals, grain) (#12, #34), later | Create | MIT engine, Pro presets are not; WebGPU-only, cannot render on our GPU-less worker; MCP/CLI send telemetry. |
| fabricjs/fabric.js | Still-ad editor: logo/headline on a generated image, export 1.91:1 and 1:1 (new) | Images | MIT. |
| arXiv 2609.20804 (harness study) | Rule-based trimming of old tool output; re-test planning and fixed tools on cost (harness goal) | Create loop | Coding benchmarks, not video. |
| Leonxlnx/cinetic (in skills/rough.md) | 273 measured techniques with 60 fps timings and a "looks generated" ban linter (#12); three-concepts-first idea step | Create | MIT; supports HyperFrames; installs via npx: take text only. Review before use. |
| skills/ (owner's motion briefs, 2026-10-12) | Builder rules (#9a), finishing checks (#28), bench briefs (#32) | Create | Briefs pull Unsplash/Pexels/Mixkit/ElevenLabs: we use only our own licensed assets. |
| Skipped | motion, ffmpeg.wasm, deck.gl, canvas-confetti, twgl.js, rive-runtime, buildfast-skills, wheresryan anatomy (idea only for #34) | — | Not seekable, duplicate GSAP/server ffmpeg, too heavy, or need assets we can't author. |

